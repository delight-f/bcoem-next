<?php

declare(strict_types=1);

namespace App\Support\Awards;

use App\Http\Controllers\BrewController;
use App\Support\Results\BestBrewerStandings;
use App\Support\Results\Place;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Builds the awards deck slides (legacy awards.php). Faithful order:
 * winner slides (table / category / subcategory per prefsWinnerMethod)
 * → BOS per style type → special best → Best Brewer → Best Club.
 *
 * Display semantics pinned to legacy:
 * - place_heirarchy() INVERTS place → 1st=5, 2nd=4, 3rd=3, 4th=2, HM=1.
 *   Both the reveal fragment index AND the pos-N medal class use this
 *   number, so 1st sits in grid row pos-5 (gold) and HM in pos-1 (teal),
 *   matching css/awards.css grid rows.
 * - display_place(place,1): 1..4 ordinals, 5/HM → "HM", else "N/A".
 * - truncate_string(s, limit, ' '): break at the next space after limit,
 *   append "..."; if no breakpoint or already short, unchanged.
 * - Style display: AABC "1.A" (ltrim zeros, dot), no-numbering sets
 *   (StyleSets::noNumbering — the BA sets) show the style name only,
 *   otherwise "cat.sub: name" (legacy :181-186 / :420-425).
 * - Pro edition: name = brewerBreweryName, club line suppressed.
 * - Club: '' when "Other" or empty; truncated to 25.
 * - Co-brewer: "& <em>…</em>" after name, truncated to 20.
 */
final class AwardDeckBuilder
{
    /** @var array<int, list<\stdClass>>|null */
    private ?array $winnerRowsByTable = null;

    /** @var array<string, list<\stdClass>>|null */
    private ?array $winnerRowsByCategory = null;

    /** @var array<string, list<\stdClass>>|null */
    private ?array $winnerRowsBySub = null;

    /** @var array<int, array{0:string,1:string}>|null */
    private ?array $stylesById = null;

    /** @var array<string, array<string,int>>|null */
    private ?array $receivedByPair = null;

    /** @var array<int, list<string>>|null */
    private ?array $judgesByTable = null;

    private ?bool $scoredEntriesExist = null;

    /**
     * @return list<AwardSlide>
     */
    public function winnerSlides(TenantContext $ctx, string $go, bool $includeEmpty = false): array
    {
        // Legacy awards.php:108 gates ALL table/category/subcategory and
        // style-type BOS slides on at least one scored entry.
        if (! $this->hasScoredEntries()) {
            return [];
        }

        $slides = match ((int) ($ctx->prefsStr('prefsWinnerMethod') ?? 0)) {
            1 => $this->categorySlides($ctx),
            2 => $this->subcategorySlides($ctx),
            default => $this->tableSlides($ctx, $go),
        };

        // Ceremony deviation (legacy rendered a dead "no winning entries"
        // slide for every unjudged table/category): drop empty winner
        // slides unless ?empty=1 restores legacy output.
        if ($includeEmpty) {
            return $slides;
        }

        return array_values(array_filter($slides, static fn (AwardSlide $s): bool => $s->winners !== []));
    }

    /** @return list<AwardSlide> */
    public function tableSlides(TenantContext $ctx, string $go): array
    {
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get(['id', 'tableName', 'tableNumber', 'tableStyles']);

        $slides = [];
        foreach ($tables as $table) {
            $rows = $this->winnerRowsForTable((int) $table->id);
            $count = $this->tableEntryCount($table);

            // ?go=table-name-only shows only the name (legacy prefix would
            // make the label a lie); every other sort keeps "Table N: Name".
            $title = $go === 'table-name-only'
                ? (string) $table->tableName
                : 'Table '.$table->tableNumber.': '.$table->tableName;

            $slides[] = new AwardSlide(
                title: $title,
                subtitle: $this->entryCountLine($count),
                judgesLine: $this->tableJudgesLine((int) $table->id),
                titleLong: (string) $table->tableName,
                count: $count,
                winners: $this->winners($ctx, $rows),
                sortNumber: (int) $table->tableNumber,
            );
        }

        return $this->orderTableSlides($slides, $go);
    }

    /** @return list<AwardSlide> */
    public function categorySlides(TenantContext $ctx): array
    {
        $styles = BrewController::activeStyles($ctx)->groupBy('brewStyleGroup');

        $slides = [];
        foreach ($styles as $group => $groupStyles) {
            $cat = (string) $group;
            if ($cat === '') {
                continue;
            }
            $first = $groupStyles->first();
            if ($first === null) {
                continue;
            }
            $rows = $this->winnerRowsForCategory($cat);
            $count = $this->categoryEntryCount($cat);

            $title = $this->categoryTitle($ctx, $first, $cat, null);

            $slides[] = new AwardSlide(
                title: $title,
                subtitle: $this->entryCountLine($count),
                judgesLine: '',
                titleLong: $cat,
                count: $count,
                winners: $this->winners($ctx, $rows),
            );
        }

        return $slides;
    }

    /** @return list<AwardSlide> */
    public function subcategorySlides(TenantContext $ctx): array
    {
        $styles = BrewController::activeStyles($ctx);

        $bySub = $styles->groupBy(fn ($s): string => (string) $s->brewStyleGroup.'-'.(string) $s->brewStyleNum);

        $slides = [];
        foreach ($bySub as $key => $subStyles) {
            $first = $subStyles->first();
            $group = (string) ($first->brewStyleGroup ?? '');
            $num = (string) ($first->brewStyleNum ?? '');
            $rows = $this->winnerRowsForSub($group, $num);
            $count = $this->subcategoryEntryCount($group, $num);

            $slides[] = new AwardSlide(
                title: $this->subcategoryTitle($ctx, (string) ($first->brewStyle ?? ''), $group, $num),
                subtitle: $this->entryCountLine($count),
                judgesLine: '',
                titleLong: $key,
                count: $count,
                winners: $this->winners($ctx, $rows),
            );
        }

        return $slides;
    }

    /**
     * BOS per style type.
     *
     * @return list<AwardSlide>
     */
    public function bosSlides(TenantContext $ctx): array
    {
        // Same scored-entries gate as legacy (inside :108-440).
        if (! $this->hasScoredEntries()) {
            return [];
        }

        $slides = [];
        $styleTypes = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get(['id', 'styleTypeName']);

        foreach ($styleTypes as $type) {
            $rows = $this->bosRows((int) $type->id);
            if ($rows === []) {
                continue;
            }

            $slides[] = new AwardSlide(
                title: 'Best of Show',
                subtitle: (string) $type->styleTypeName,
                judgesLine: '',
                titleLong: (string) $type->styleTypeName,
                count: count($rows),
                winners: $this->winners($ctx, $rows),
            );
        }

        return $slides;
    }

    /**
     * Special/custom best-of categories.
     *
     * @return list<AwardSlide>
     */
    public function specialBestSlides(TenantContext $ctx): array
    {
        $slides = [];
        $sbi = DB::table('special_best_info')->orderBy('sbi_name')->get(['id', 'sbi_name', 'sbi_display_places']);

        foreach ($sbi as $row) {
            $rows = DB::table('special_best_data as sbd')
                ->join('brewing as b', 'sbd.eid', '=', 'b.id')
                ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
                ->where('sbd.sid', $row->id)
                ->orderBy('sbd.sbd_place')
                ->get([
                    'sbd.sbd_place', 'b.brewName', 'b.brewStyle', 'b.brewCategory',
                    'b.brewCategorySort', 'b.brewSubCategory', 'b.brewCoBrewer',
                    'br.brewerFirstName', 'br.brewerLastName', 'br.brewerBreweryName',
                    'br.brewerClubs',
                ]);

            if ($rows->isEmpty()) {
                continue;
            }

            $displayPlaces = (int) $row->sbi_display_places === 1;
            $seq = 0;
            $winners = array_values($rows->map(function ($r) use ($ctx, $displayPlaces, &$seq): AwardWinner {
                $placeInt = (int) $r->sbd_place;
                $showPlace = $displayPlaces && $r->sbd_place !== '' && $placeInt >= 1 && $placeInt <= 5;

                if ($showPlace) {
                    $fh = $this->hierarchy((string) $placeInt);
                    $placeLabel = Place::label((string) $placeInt);
                } else {
                    $seq += 1;
                    $fh = $this->hierarchy((string) $seq);
                    $placeLabel = '';
                }

                return $this->winnerFrom(
                    $ctx,
                    place: $placeLabel,
                    fh: $fh,
                    firstName: (string) $r->brewerFirstName,
                    lastName: (string) $r->brewerLastName,
                    breweryName: (string) $r->brewerBreweryName,
                    clubs: (string) $r->brewerClubs,
                    brewName: (string) $r->brewName,
                    category: (string) $r->brewCategory,
                    categorySort: (string) $r->brewCategorySort,
                    subCategory: (string) $r->brewSubCategory,
                    brewStyle: (string) $r->brewStyle,
                    coBrewer: (string) $r->brewCoBrewer,
                );
            })->all());

            $slides[] = new AwardSlide(
                title: (string) $row->sbi_name,
                subtitle: '',
                judgesLine: '',
                titleLong: (string) $row->sbi_name,
                count: count($winners),
                winners: $winners,
            );
        }

        return $slides;
    }

    /**
     * Build the Best Brewer + Best Club slides (legacy :962-1101).
     *
     * @return list<BestBrewerSlide>
     */
    public function bestBrewerSlides(TenantContext $ctx): array
    {
        $standings = BestBrewerStandings::forAwards($ctx);

        $slides = [];

        if ($standings->brewerRows !== []) {
            $slides[] = new BestBrewerSlide(
                title: (string) ($ctx->prefsStr('prefsBestBrewerTitle') ?: 'Best Brewer'),
                participantLine: $standings->brewerCount.' '.'participating brewers',
                rows: $standings->brewerRows,
                show4th: $standings->show4th,
                showHm: $standings->showHm,
                showClub: false,
                proEdition: (int) ($ctx->prefsStr('prefsProEdition') ?? 0) === 1,
            );
        }

        if ($standings->clubRows !== []) {
            $slides[] = new BestBrewerSlide(
                title: (string) ($ctx->prefsStr('prefsBestClubTitle') ?: 'Best Club'),
                participantLine: $standings->clubCount.' '.'participating clubs',
                rows: $standings->clubRows,
                show4th: $standings->show4th,
                showHm: $standings->showHm,
                showClub: true,
                proEdition: false,
            );
        }

        return $slides;
    }

    // ---------------------------------------------------------------

    /**
     * Preload every placed row in one query and index it three ways
     * (table / category / subcategory). Replaces the per-group scoresFor()
     * N+1 (one query per table, per category, per subcategory).
     */
    private function loadWinnerRows(): void
    {
        if ($this->winnerRowsByTable !== null) {
            return;
        }

        $byTable = [];
        $byCategory = [];
        $bySub = [];

        // Legacy scores.db.php: action=awards-pres ORDER BY scorePlace DESC →
        // 1st first (scorePlace 1 is highest). Ordering is preserved because
        // rows append in query order.
        $rows = DB::table('judging_scores as js')
            ->join('brewing as b', 'js.eid', '=', 'b.id')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->whereIn('js.scorePlace', ['1', '2', '3', '4', '5'])
            ->orderBy('js.scorePlace')
            ->get([
                'js.scorePlace', 'js.scoreTable', 'b.brewName', 'b.brewStyle', 'b.brewCategory',
                'b.brewCategorySort', 'b.brewSubCategory', 'b.brewCoBrewer',
                'br.brewerFirstName', 'br.brewerLastName', 'br.brewerBreweryName',
                'br.brewerClubs',
            ]);

        foreach ($rows as $r) {
            $byTable[(int) $r->scoreTable][] = $r;
            $byCategory[(string) $r->brewCategorySort][] = $r;
            $bySub[(string) $r->brewCategorySort.'-'.(string) $r->brewSubCategory][] = $r;
        }

        $this->winnerRowsByTable = $byTable;
        $this->winnerRowsByCategory = $byCategory;
        $this->winnerRowsBySub = $bySub;
    }

    /** @return list<\stdClass> */
    private function winnerRowsForTable(int $tableId): array
    {
        $this->loadWinnerRows();

        return $this->winnerRowsByTable[$tableId] ?? [];
    }

    /** @return list<\stdClass> */
    private function winnerRowsForCategory(string $cat): array
    {
        $this->loadWinnerRows();

        return $this->winnerRowsByCategory[$cat] ?? [];
    }

    /** @return list<\stdClass> */
    private function winnerRowsForSub(string $group, string $num): array
    {
        $this->loadWinnerRows();

        return $this->winnerRowsBySub[$group.'-'.$num] ?? [];
    }

    /** @return array<int, array{0:string,1:string}> style id => [group, num] */
    private function stylesById(): array
    {
        if ($this->stylesById === null) {
            $map = [];
            foreach (DB::table('styles')->get(['id', 'brewStyleGroup', 'brewStyleNum']) as $s) {
                $map[(int) $s->id] = [(string) $s->brewStyleGroup, (string) $s->brewStyleNum];
            }
            $this->stylesById = $map;
        }

        return $this->stylesById;
    }

    /** @return array<string, array<string,int>> received counts [group][num] */
    private function receivedByPair(): array
    {
        if ($this->receivedByPair === null) {
            $map = [];
            $rows = DB::table('brewing')
                ->where('brewReceived', 1)
                ->selectRaw('brewCategorySort, brewSubCategory, COUNT(*) as cnt')
                ->groupBy('brewCategorySort', 'brewSubCategory')
                ->get();
            foreach ($rows as $r) {
                $map[(string) $r->brewCategorySort][(string) $r->brewSubCategory] = (int) $r->cnt;
            }
            $this->receivedByPair = $map;
        }

        return $this->receivedByPair;
    }

    /** @return array<int, list<string>> "Judges: …" names keyed by table id */
    private function judgesByTable(): array
    {
        if ($this->judgesByTable === null) {
            $map = [];
            $rows = DB::table('judging_assignments as ja')
                ->join('brewer as br', 'ja.bid', '=', 'br.uid')
                ->where('ja.assignment', 'J')
                ->orderBy('br.brewerLastName')->orderBy('br.brewerFirstName')
                ->get(['ja.assignTable', 'br.brewerFirstName', 'br.brewerLastName', 'ja.assignRoles']);
            foreach ($rows as $r) {
                $name = trim((string) $r->brewerFirstName.' '.(string) $r->brewerLastName);
                if (str_contains((string) $r->assignRoles, 'HJ')) {
                    $name .= ' (Head Judge)';
                }
                $map[(int) $r->assignTable][] = $name;
            }
            $this->judgesByTable = $map;
        }

        return $this->judgesByTable;
    }

    /** @return list<\stdClass> BOS rows, with legacy type-4 combined scoreType 2/3/4 */
    private function bosRows(int $typeId): array
    {
        $q = DB::table('judging_scores_bos as jsb')
            ->join('brewing as b', 'jsb.eid', '=', 'b.id')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->whereIn('jsb.scorePlace', ['1', '2', '3', '4', '5'])
            ->orderBy('jsb.scorePlace');

        if ($typeId === 4) {
            // Legacy output_results_download_bos.db.php: type 4 (combined
            // Mead/Cider) selects scoreType 2 OR 3 OR 4.
            $q->whereIn('jsb.scoreType', ['2', '3', '4']);
        } else {
            $q->where('jsb.scoreType', $typeId);
        }

        return array_values($q->get([
            'jsb.scorePlace', 'b.brewName', 'b.brewStyle', 'b.brewCategory',
            'b.brewCategorySort', 'b.brewSubCategory', 'b.brewCoBrewer',
            'br.brewerFirstName', 'br.brewerLastName', 'br.brewerBreweryName',
            'br.brewerClubs',
        ])->all());
    }

    /**
     * @param  list<\stdClass>  $rows
     * @return list<AwardWinner>
     */
    private function winners(TenantContext $ctx, array $rows): array
    {
        return array_values(array_map(fn ($r): AwardWinner => $this->winnerFrom(
            $ctx,
            place: Place::label((string) $r->scorePlace),
            fh: $this->hierarchy((string) $r->scorePlace),
            firstName: (string) $r->brewerFirstName,
            lastName: (string) $r->brewerLastName,
            breweryName: (string) $r->brewerBreweryName,
            clubs: (string) $r->brewerClubs,
            brewName: (string) $r->brewName,
            category: (string) $r->brewCategory,
            categorySort: (string) $r->brewCategorySort,
            subCategory: (string) $r->brewSubCategory,
            brewStyle: (string) $r->brewStyle,
            coBrewer: (string) $r->brewCoBrewer,
        ), $rows));
    }

    private function winnerFrom(
        TenantContext $ctx,
        string $place,
        int $fh,
        string $firstName,
        string $lastName,
        string $breweryName,
        string $clubs,
        string $brewName,
        string $category,
        string $categorySort,
        string $subCategory,
        string $brewStyle,
        string $coBrewer,
    ): AwardWinner {
        $pro = (int) ($ctx->prefsStr('prefsProEdition') ?? 0) === 1;
        $styleSet = (string) $ctx->prefsStr('prefsStyleSet');

        $name = $pro && $breweryName !== '' ? $breweryName : trim($firstName.' '.$lastName);
        $club = (! $pro && $clubs !== '' && $clubs !== 'Other') ? $clubs : '';

        return new AwardWinner(
            place: $place,
            fh: $fh,
            name: $name,
            club: $this->truncate($club, 25),
            entry: $this->truncate($brewName, 65),
            style: $this->styleDisplay($styleSet, $category, $categorySort, $subCategory, $brewStyle),
            coBrewer: $coBrewer === '' ? '' : $this->truncate($coBrewer, 20),
        );
    }

    private function styleDisplay(string $styleSet, string $category, string $categorySort, string $subCategory, string $brewStyle): string
    {
        if ($styleSet === 'AABC') {
            $style = ltrim($category, '0').'.'.ltrim($subCategory, '0');

            return $style.': '.$brewStyle;
        }

        if (StyleSets::noNumbering($styleSet)) {
            return $brewStyle;
        }

        return $categorySort.$subCategory.': '.$brewStyle;
    }

    private function categoryTitle(TenantContext $ctx, object $first, string $cat, ?string $sub): string
    {
        if (StyleSets::noNumbering((string) $ctx->prefsStr('prefsStyleSet'))) {
            return (string) ($first->brewStyleCategory ?? '');
        }

        $name = (string) ($first->brewStyleCategory ?? 'Custom Category');

        return 'Category '.ltrim($cat, '0').': '.$name;
    }

    private function subcategoryTitle(TenantContext $ctx, string $brewStyle, string $group, string $num): string
    {
        if (StyleSets::noNumbering((string) $ctx->prefsStr('prefsStyleSet'))) {
            return $brewStyle;
        }

        return 'Category '.ltrim($group, '0').$num.': '.$brewStyle;
    }

    private function entryCountLine(int $count): string
    {
        return $count.' '.($count === 1 ? 'entry' : 'entries');
    }

    /** Table entry count via tableStyles (get_table_info count_total). */
    private function tableEntryCount(\stdClass $table): int
    {
        $styles = array_filter(array_map('trim', explode(',', (string) $table->tableStyles)));

        if ($styles === []) {
            return 0;
        }

        $byId = $this->stylesById();
        $received = $this->receivedByPair();

        $total = 0;
        foreach ($styles as $styleId) {
            $pair = $byId[(int) $styleId] ?? null;
            if ($pair !== null) {
                $total += $received[$pair[0]][$pair[1]] ?? 0;
            }
        }

        return $total;
    }

    private function categoryEntryCount(string $cat): int
    {
        return (int) array_sum($this->receivedByPair()[$cat] ?? []);
    }

    private function subcategoryEntryCount(string $group, string $num): int
    {
        return $this->receivedByPair()[$group][$num] ?? 0;
    }

    /**
     * @param  list<AwardSlide>  $slides
     * @return list<AwardSlide>
     */
    private function orderTableSlides(array $slides, string $go): array
    {
        usort($slides, function (AwardSlide $a, AwardSlide $b) use ($go): int {
            if ($go === 'table-entry-count-asc' || $go === 'table-entry-count-desc') {
                $cmp = $a->count <=> $b->count;
                if ($cmp !== 0) {
                    return $go === 'table-entry-count-desc' ? -$cmp : $cmp;
                }

                return strcmp($a->titleLong, $b->titleLong);
            }

            if ($go === 'table-name-only') {
                return strcmp($a->titleLong, $b->titleLong);
            }

            // Legacy array_multisort :236-237: numeric table number, then name.
            $cmp = ($a->sortNumber ?? 0) <=> ($b->sortNumber ?? 0);

            return $cmp !== 0 ? $cmp : strcmp($a->titleLong, $b->titleLong);
        });

        return $slides;
    }

    /**
     * "Judges: A, B (Head Judge)" line for a table slide (legacy :157-170).
     * Roles come from judging_assignments.assignRoles; 'HJ' marks head judge.
     */
    private function tableJudgesLine(int $tableId): string
    {
        $names = $this->judgesByTable()[$tableId] ?? [];

        if ($names === []) {
            return '';
        }

        return 'Judges: '.implode(', ', $names);
    }

    private function hasScoredEntries(): bool
    {
        return $this->scoredEntriesExist ??= DB::table('judging_scores')->whereNotNull('scorePlace')->exists();
    }

    /** Legacy place_heirarchy: 1st→5, 2nd→4, 3rd→3, 4th→2, HM/5→1. */
    private function hierarchy(string $place): int
    {
        return match ($place) {
            '1' => 5,
            '2' => 4,
            '3' => 3,
            '4' => 2,
            default => 1,
        };
    }

    /** truncate_string(s, limit, ' ') — break at space after limit, pad "...". */
    private function truncate(string $s, int $limit): string
    {
        if (strlen($s) <= $limit) {
            return $s;
        }

        $breakpoint = strpos($s, ' ', $limit);
        if ($breakpoint !== false && $breakpoint < strlen($s) - 1) {
            return substr($s, 0, $breakpoint).'...';
        }

        return $s;
    }
}
