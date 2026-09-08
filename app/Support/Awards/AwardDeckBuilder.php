<?php

declare(strict_types=1);

namespace App\Support\Awards;

use App\Http\Controllers\BrewController;
use App\Support\Results\BestBrewerStandings;
use App\Support\Results\Place;
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
 * - Style display: AABC "1.A" (ltrim zeros, dot), BA style name only,
 *   otherwise "cat.sub: name" (legacy :181-186 / :420-425).
 * - Pro edition: name = brewerBreweryName, club line suppressed.
 * - Club: '' when "Other" or empty; truncated to 25.
 * - Co-brewer: "& <em>…</em>" after name, truncated to 20.
 */
final class AwardDeckBuilder
{
    /** @return list<AwardSlide> */
    public function winnerSlides(TenantContext $ctx, string $go): array
    {
        // Legacy awards.php:108 gates ALL table/category/subcategory and
        // style-type BOS slides on at least one scored entry.
        if (! self::hasScoredEntries()) {
            return [];
        }

        return match ((int) ($ctx->prefsStr('prefsWinnerMethod') ?? 0)) {
            1 => $this->categorySlides($ctx),
            2 => $this->subcategorySlides($ctx),
            default => $this->tableSlides($ctx, $go),
        };
    }

    /** @return list<AwardSlide> */
    public function tableSlides(TenantContext $ctx, string $go): array
    {
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get(['id', 'tableName', 'tableNumber', 'tableStyles']);

        $slides = [];
        foreach ($tables as $table) {
            $rows = $this->scoresFor($ctx, ['table' => $table]);
            $count = $this->tableEntryCount($table);

            $slides[] = new AwardSlide(
                title: 'Table '.$table->tableNumber.': '.$table->tableName,
                subtitle: $this->entryCountLine($count),
                judgesLine: $this->tableJudgesLine((int) $table->id),
                titleLong: (string) $table->tableName,
                count: $count,
                winners: $this->winners($ctx, $rows),
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
            $rows = $this->scoresFor($ctx, ['categorySort' => $cat]);
            $count = $this->categoryEntryCount($ctx, $cat);

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
            $rows = $this->scoresFor($ctx, ['group' => $group, 'num' => $num]);
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
        if (! self::hasScoredEntries()) {
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
     * @param  array{table: \stdClass}|array{categorySort: string}|array{group: string, num: string}  $where
     * @return list<\stdClass> raw judging_scores rows joined to brewing+brewer
     */
    private function scoresFor(TenantContext $ctx, array $where): array
    {
        $q = DB::table('judging_scores as js')
            ->join('brewing as b', 'js.eid', '=', 'b.id')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->whereIn('js.scorePlace', ['1', '2', '3', '4', '5']);

        if (isset($where['table'])) {
            $q->where('js.scoreTable', $where['table']->id);
        } elseif (isset($where['categorySort'])) {
            $q->where('b.brewCategorySort', $where['categorySort']);
        } else {
            $q->where('b.brewCategorySort', $where['group'])
                ->where('b.brewSubCategory', $where['num']);
        }

        // Legacy scores.db.php: action=awards-pres ORDER BY scorePlace DESC →
        // 1st first (scorePlace 1 is highest).
        return array_values($q->orderBy('js.scorePlace')
            ->get([
                'js.scorePlace', 'b.brewName', 'b.brewStyle', 'b.brewCategory',
                'b.brewCategorySort', 'b.brewSubCategory', 'b.brewCoBrewer',
                'br.brewerFirstName', 'br.brewerLastName', 'br.brewerBreweryName',
                'br.brewerClubs',
            ])->all());
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

        if ($styleSet === 'BA') {
            return $brewStyle;
        }

        return $categorySort.$subCategory.': '.$brewStyle;
    }

    private function categoryTitle(TenantContext $ctx, object $first, string $cat, ?string $sub): string
    {
        if ((string) ($ctx->prefsStr('prefsStyleSet')) === 'BA') {
            return (string) ($first->brewStyleCategory ?? '');
        }

        $name = (string) ($first->brewStyleCategory ?? 'Custom Category');

        return 'Category '.ltrim($cat, '0').': '.$name;
    }

    private function subcategoryTitle(TenantContext $ctx, string $brewStyle, string $group, string $num): string
    {
        if ((string) ($ctx->prefsStr('prefsStyleSet')) === 'BA') {
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

        $q = DB::table('brewing')->where('brewReceived', 1);

        $pairs = [];
        foreach ($styles as $styleId) {
            $row = DB::table('styles')->where('id', (int) $styleId)->first(['brewStyleGroup', 'brewStyleNum']);
            if ($row) {
                $pairs[] = [(string) $row->brewStyleGroup, (string) $row->brewStyleNum];
            }
        }

        if ($pairs !== []) {
            $q->where(function ($qq) use ($pairs): void {
                foreach ($pairs as $i => [$group, $num]) {
                    if ($i === 0) {
                        $qq->where(fn ($qq2) => $qq2->where('brewCategorySort', $group)->where('brewSubCategory', $num));
                    } else {
                        $qq->orWhere(fn ($qq2) => $qq2->where('brewCategorySort', $group)->where('brewSubCategory', $num));
                    }
                }
            });
        }

        return (int) $q->count();
    }

    private function categoryEntryCount(TenantContext $ctx, string $cat): int
    {
        return (int) DB::table('brewing')->where('brewCategorySort', $cat)->where('brewReceived', 1)->count();
    }

    private function subcategoryEntryCount(string $group, string $num): int
    {
        return (int) DB::table('brewing')->where('brewCategorySort', $group)->where('brewSubCategory', $num)->where('brewReceived', 1)->count();
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
            return strnatcmp($a->titleLong, $b->titleLong);
        });

        return $slides;
    }

    /**
     * "Judges: A, B (Head Judge)" line for a table slide (legacy :157-170).
     * Roles come from judging_assignments.assignRoles; 'HJ' marks head judge.
     */
    private function tableJudgesLine(int $tableId): string
    {
        $rows = DB::table('judging_assignments as ja')
            ->join('brewer as br', 'ja.bid', '=', 'br.uid')
            ->where('ja.assignment', 'J')
            ->where('ja.assignTable', $tableId)
            ->orderBy('br.brewerLastName')->orderBy('br.brewerFirstName')
            ->get(['br.brewerFirstName', 'br.brewerLastName', 'ja.assignRoles']);

        if ($rows->isEmpty()) {
            return '';
        }

        $names = $rows->map(function ($r): string {
            $name = trim($r->brewerFirstName.' '.$r->brewerLastName);
            if (str_contains((string) $r->assignRoles, 'HJ')) {
                return $name.' (Head Judge)';
            }

            return $name;
        })->all();

        return 'Judges: '.implode(', ', $names);
    }

    private static function hasScoredEntries(): bool
    {
        return DB::table('judging_scores')->whereNotNull('scorePlace')->exists();
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
