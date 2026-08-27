<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\OutputFormat;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Participant address/name label sheets AND the admin bottle-label matrix
 * (spec §7 P5.2). Legacy: output/labels.output.php.
 *
 * The single `labels` route serves every mode the legacy file exports; the
 * `action` query param selects the branch, mirroring the legacy switch on
 * ($go, $action):
 *
 *  - action=address_labels (default) → participant address/name sheets
 *    (the original P5.2 address-labels port; see build()).
 *  - action=bottle-entry|bottle-judging with view=default → the six
 *    `entry_no (style)` matrix labels ({@see bottleDefaults()}).
 *  - … with view=quicksort → the 5167 quick-sort judging-number labels
 *    ({@see bottleQuicksort()}).
 *  - … with any other view (all/special) → required-info labels
 *    ({@see bottleRequiredInfo()}).
 *  - action=bottle-entry-round|bottle-judging-round → round bottle labels
 *    ({@see bottleRound()}).
 *  - action=bottle-category-round → category-only round labels
 *    ({@see bottleCategoryRound()}).
 *
 * Quirks mirrored (see each builder's docblock for the legacy provenance):
 *  - psort selects the sheet density: Avery 3422 = 24 labels/sheet,
 *    anything else = Avery 5160 = 30 labels/sheet.
 *  - prefsStyleSet selects BA vs AABC style-display; AABC drops leading
 *    zeros as `cat.sub`, everything else prints `cat+sub` verbatim.
 *  - `sort` request param repeats every label N times (legacy's copies
 *    loop) on the required-info and round branches.
 *  - tb=received adds WHERE brewReceived='1'; filter=<category> restricts
 *    to brewCategorySort=<category>.
 *  - The required-info branch truncates style names to 21 chars after the
 *    abbreviation substitutions, marks beer strength, sweetness,
 *    carbonation with *Session*, *Low/No Sweet*, *Med Carb* etc., and prints
 *    "Possible Allergens: …" lines (label_allergens = "Allergens").
 *
 * Divergences:
 *  - Admin-only; legacy let a logged-in brewer print their own labels in a
 *    couple of sibling modes (bid ownership checks not ported — the port's
 *    route group is admin-gated per ticket).
 *  - dompdf replaces FPDF: Avery sheet metrics are approximated as CSS
 *    grids in the Blade templates, and the PDF is always letter paper
 *    (Avery 3422 is an A4 sheet in legacy).
 *  - Non-ASCII text is ASCII-transliterated via Str::ascii() instead of
 *    legacy's iconv(TRANSLIT) + Any-Latin; Latin-ASCII — equivalent for the
 *    corpus and shipped style sets.
 *  - Dead legacy accumulators `character_limit_adjust` and
 *    `character_limit_adjust_special` (assigned but never read) are not
 *    reproduced; they do not affect label content.
 */
final class LabelsController extends Controller
{
    private const ALLERGENS = 'Allergens';

    private const CHARACTER_LIMIT = 32;

    private const TOTAL_POSSIBLE_CHARACTERS = 192; // 6 lines x 32 chars

    /** Legacy $special_strength strtr cleanup table (labels.output.php:482). */
    private const SPECIAL_STRENGTH = [
        'Strength' => '', 'strength' => '', 'Sweetness' => '', 'sweetness' => '',
        'Carbonation' => '', 'carbonation' => '', 'Session ' => '', 'session ' => '',
        'Standard ' => '', 'standard ' => '', 'Double ' => '', 'double ' => '',
        'Table ' => '', 'table ' => '', 'Super ' => '', 'super ' => '',
        'Low/None' => '', 'Low' => '', 'low' => '', 'High' => '', 'high' => '',
        'Medium' => '', 'medium' => '',
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        $action = (string) $request->query('action', 'address_labels');

        // Every bottle-label mode lives on the same /admin/output/labels
        // route; the legacy (go=entries, action=…) switch is reproduced
        // here. The address_labels mode keeps its original small block.
        if (str_starts_with($action, 'bottle-')) {
            $view = (string) $request->query('view', 'default');
            $psort = (string) $request->query('psort', '5160');
            $filter = (string) $request->query('filter', 'default');
            $tb = (string) $request->query('tb', 'default');
            $location = (string) $request->query('location', 'default');
            $sort = max(1, (int) $request->query('sort', '1'));

            if ($action === 'bottle-category-round') {
                $data = self::bottleCategoryRound($ctx, $filter, $sort, $psort);
            } elseif (str_ends_with($action, '-round')) {
                $data = self::bottleRound($ctx, $action, $filter, $sort, $psort);
            } elseif ($view === 'quicksort') {
                $data = self::bottleQuicksort($ctx, $tb);
            } elseif ($view === 'default') {
                $data = self::bottleDefaults($ctx, $action, $filter, $tb, $psort);
            } else {
                $data = self::bottleRequiredInfo($ctx, $action, $view, $filter, $tb, $location, $sort, $psort);
            }

            return StreamPdf::response($data['view_name'], $data['view'], $data['filename']);
        }

        $psort = (string) $request->query('psort', '5160');
        $withEntries = $request->query('filter') === 'with_entries';
        $copies = max(1, (int) $request->query('sort', '1'));
        // Legacy user_entry_count($uid,$view): view=entry lists entry
        // numbers ordered by id; anything else lists judging numbers.
        $view = $request->query('view') === 'entry' ? 'entry' : 'judging';

        $contest = str_replace(' ', '_', (string) $ctx->contestStr('contestName'));
        $filename = $contest.'_Participants_'.($withEntries ? 'With_Entries_' : '')
            .'Address_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf';

        return StreamPdf::response('outputs.labels', [
            'perSheet' => $psort === '3422' ? 24 : 30,
            'labels' => self::build($withEntries, $copies, $view),
        ], $filename);
    }

    /**
     * View payload: a flat list of labels, each a list of text lines.
     *
     * @return list<list<string>>
     */
    public static function build(bool $withEntries, int $copies, string $view = 'judging'): array
    {
        // output_labels.db.php:106 — all brewers, last-name order.
        $brewers = DB::table('brewer')->orderBy('brewerLastName')->get();

        // filter=with_entries: distinct brewers having received entries.
        $withIds = [];
        if ($withEntries) {
            $withIds = DB::table('brewing')->where('brewReceived', '1')
                ->distinct()->pluck('brewBrewerID')->all();
        }

        $labels = [];
        foreach ($brewers as $b) {
            if ($withEntries && ! in_array($b->uid, $withIds)) {
                continue;
            }

            $country = $b->brewerCountry !== 'United States' ? (string) $b->brewerCountry : '';

            if ($withEntries) {
                // Entry-summary label first (legacy :993-1001).
                $rows = DB::table('brewing')->where('brewBrewerID', $b->uid)
                    ->where('brewReceived', '1')
                    ->orderBy($view === 'entry' ? 'id' : 'brewJudgingNumber')
                    ->get();

                $numbers = $rows->map(
                    fn ($r) => sprintf('%06d', $view === 'entry' ? (int) $r->id : (int) $r->brewJudgingNumber),
                )->unique()->implode(', ');

                $count = $rows->count().' '.($rows->count() === 1 ? 'Entry' : 'Entries');

                // Legacy truncates the number list to fit the remaining
                // label lines (126 chars when a country line follows,
                // else 166; :990-991).
                $numbers = mb_substr($numbers, 0, $country !== '' ? 126 : 166);

                for ($i = 0; $i < $copies; $i++) {
                    $labels[] = [
                        'Entry Summary for '.trim($b->brewerFirstName.' '.$b->brewerLastName),
                        $count,
                        'Entry #: '.$numbers,
                        $country,
                    ];
                }
            }

            $address = [
                trim($b->brewerFirstName.' '.$b->brewerLastName),
                (string) $b->brewerAddress,
                trim(sprintf('%s, %s %s', $b->brewerCity, $b->brewerState, $b->brewerZip), ', '),
                $country,
            ];

            for ($i = 0; $i < $copies; $i++) {
                $labels[] = $address;
            }
        }

        return $labels;
    }

    /**
     * Branch 1 — action=bottle-entry|bottle-judging, view=default: six
     * identical `entry_no (style)` pairs per label on a 3422/5160 sheet
     * (legacy labels.output.php ~:330-390).
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function bottleDefaults(TenantContext $ctx, string $action, string $filter, string $tb, string $psort): array
    {
        $aabc = $ctx->prefsStr('prefsStyleSet') === 'AABC';

        $rows = self::entriesQuery($action, $filter, $tb)->get();

        $labels = [];
        foreach ($rows as $e) {
            // :334-336 — `%06s` of id (entry) or uppercased judging number.
            $entryNo = $action === 'bottle-entry'
                ? sprintf('%06s', (string) $e->id)
                : sprintf('%06s', strtoupper((string) $e->brewJudgingNumber));

            $category = sprintf('%02s', (string) $e->brewCategorySort);
            $subcategory = (string) $e->brewSubCategory;
            $catOutput = $aabc
                ? ltrim($category, '0').'.'.ltrim($subcategory, '0')
                : $category.$subcategory;

            $text = sprintf(
                "\n%s (%s)  %s (%s)  %s (%s)\n\n\n\n%s (%s)  %s (%s)  %s (%s)",
                $entryNo, $catOutput, $entryNo, $catOutput, $entryNo, $catOutput,
                $entryNo, $catOutput, $entryNo, $catOutput, $entryNo, $catOutput,
            );

            $labels[] = explode("\n", self::ascii($text));
        }

        // :332-338 — filename is always "_Bottle_Labels_Entry_Numbers",
        // even for the judging-number variant (a legacy quirk).
        $filename = str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
            .'_Bottle_Labels_Entry_Numbers'
            .($filter !== 'default' ? '_Category_'.$filter : '')
            .($psort === '3422' ? '_Avery3422' : '_Avery5160')
            .'.pdf';

        return [
            'view_name' => 'outputs.labels',
            'view' => ['perSheet' => $psort === '3422' ? 24 : 30, 'labels' => $labels],
            'filename' => $filename,
        ];
    }

    /**
     * Branch 2 — view=all / view=special: required-info labels
     * (legacy labels.output.php else branch ~:460-700). The $view value
     * gates whether only special/mead entries are emitted (special) or all
     * entries are (all).
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function bottleRequiredInfo(
        TenantContext $ctx,
        string $action,
        string $view,
        string $filter,
        string $tb,
        string $location,
        int $sort,
        string $psort,
    ): array {
        $ba = $ctx->prefsStr('prefsStyleSet') === 'BA';
        $aabc = $ctx->prefsStr('prefsStyleSet') === 'AABC';

        [$specialIngredients, $meadStyles] = self::specialAndMead($ba);

        $rows = self::entriesQuery($action, $filter, $tb)->get();

        // Table-scoped labels: entries assigned to $location via judging_flights.
        $entriesAtTable = [];
        if ($location !== 'default') {
            $entriesAtTable = DB::table('judging_flights')
                ->where('flightTable', $location)
                ->pluck('flightEntryID')->all();
        }

        $labels = [];
        foreach ($rows as $e) {
            // :497-499 — $style is CategorySort + SubCategory uppercased.
            $subcategory = (string) $e->brewSubCategory;
            $style = strtoupper((string) $e->brewCategorySort).$subcategory;
            $styleDisplay = $aabc
                ? strtoupper(ltrim((string) $e->brewCategorySort, '0')).'.'.ltrim($subcategory, '0')
                : $style;

            for ($i = 0; $i < $sort; $i++) {
                $characterLength = 0;
                $special = '';
                $optional = '';
                $allergens = '';
                $sweetCarb = '';
                $beerStrength = '';
                $beerSweetness = '';
                $beerCarbonation = '';

                // :515-516 — entry vs judging number.
                $entryNo = $action === 'bottle-entry'
                    ? sprintf('%06s', (string) $e->id)
                    : sprintf('%06s', strtoupper((string) $e->brewJudgingNumber));

                // :523-546 — style-name abbreviation substitutions, truncate(21),
                // then ASCII.
                $styleName = self::truncate(self::abbreviateStyleName((string) $e->brewStyle), 21);
                $styleName = self::ascii($styleName);

                $entryInfo = $ba
                    ? sprintf('%s (%s)', $entryNo, $styleName)
                    : sprintf('%s (%s: %s)', $entryNo, $styleDisplay, $styleName);
                $characterLength += strlen($entryInfo);

                // :551-589 — special-ingredients line when the style requires it.
                if (in_array($style, $specialIngredients, true)) {
                    $special = self::ascii(trim(str_replace("\n", '', str_replace('^', '', html_entity_decode(strip_tags((string) $e->brewInfo))))));
                    if ($special !== '') {
                        $characterLength += strlen($special);
                        $special = "\n".$special;
                    }

                    if (! in_array($style, $meadStyles, true)) {
                        $lowerInfo = mb_strtolower((string) $e->brewInfo);
                        if (str_contains($lowerInfo, 'session strength')) { $beerStrength .= '*Session* '; }
                        if (str_contains($lowerInfo, 'standard strength')) { $beerStrength .= '*Standard* '; }
                        if (str_contains($lowerInfo, 'double strength')) { $beerStrength .= '*Double* '; }
                        if (str_contains($lowerInfo, 'table strength')) { $beerStrength .= '*Table* '; }
                        if (str_contains($lowerInfo, 'super strength')) { $beerStrength .= '*Super* '; }
                        if (str_contains($lowerInfo, 'low/none sweetness')) { $beerSweetness .= '*Low/No Sweet* '; }
                        if (str_contains($lowerInfo, 'medium sweetness')) { $beerSweetness .= '*Med Sweet* '; }
                        if (str_contains($lowerInfo, 'high sweetness')) { $beerSweetness .= '*High Sweet* '; }
                        if (str_contains($lowerInfo, 'low carbonation')) { $beerCarbonation .= '*Low Carb* '; }
                        if (str_contains($lowerInfo, 'medium carbonation')) { $beerCarbonation .= '*Med Carb* '; }
                        if (str_contains($lowerInfo, 'high carbonation')) { $beerCarbonation .= '*High Carb* '; }

                        if ($beerStrength !== '' || $beerSweetness !== '' || $beerCarbonation !== '') {
                            $special = strtr($special, self::SPECIAL_STRENGTH);
                        }

                        $sweetCarb .= $beerCarbonation.$beerSweetness.$beerStrength;
                    }
                }

                // :590-602 — allergens line when present and budget remains.
                if ((string) $e->brewPossAllergens !== '' && $characterLength < self::TOTAL_POSSIBLE_CHARACTERS) {
                    $allergens = self::ascii(html_entity_decode(
                        str_replace("\n", ' ', sprintf('%s: %s', self::ALLERGENS, strip_tags((string) $e->brewPossAllergens))),
                    ));
                    if ($allergens !== '') {
                        $characterLength += strlen($allergens);
                        $allergens = "\n".$allergens;
                    }
                }

                // :610-622 — mead/cider markers.
                if (in_array($style, $meadStyles, true)) {
                    if ((string) $e->brewMead1 !== '') { $sweetCarb .= sprintf('*%s* ', $e->brewMead1); }
                    if ((string) $e->brewMead2 !== '') { $sweetCarb .= sprintf('*%s* ', $e->brewMead2); }
                    if ((string) $e->brewMead3 !== '') { $sweetCarb .= sprintf('*%s* ', $e->brewMead3); }

                    $sweetCarb = str_replace('Medium Sweet', 'Med Sweet', $sweetCarb);
                    $sweetCarb = str_replace('Medium Dry', 'Med Dry', $sweetCarb);
                    $sweetCarb = str_replace('Sparkling', 'Spark', $sweetCarb);
                    $sweetCarb = str_replace('Hydromel', 'Hydro', $sweetCarb);
                    $sweetCarb = str_replace('Petillant', 'Petill', $sweetCarb);
                }

                if ($sweetCarb !== '') {
                    $characterLength += strlen($sweetCarb);
                    $sweetCarb = "\n".$sweetCarb;
                }

                // :625-637 — optional line only if one line of budget remains.
                if ((string) $e->brewInfoOptional !== ''
                    && $characterLength < (self::TOTAL_POSSIBLE_CHARACTERS - self::CHARACTER_LIMIT)) {
                    $optional = self::truncate(self::ascii(html_entity_decode((string) $e->brewInfoOptional)), self::CHARACTER_LIMIT, '');
                    $optional = str_replace("\n", ' ', $optional);
                    $characterLength += strlen($optional);
                    $optional = "\n".$optional;
                }

                // :647-659 — tighten special/optional when allergens/mead present.
                if ($allergens !== '' && $sweetCarb === '') {
                    if ($special !== '') { $special = self::truncate($special, self::CHARACTER_LIMIT * 4, ''); }
                    if ($optional !== '') { $optional = self::truncate($optional, self::CHARACTER_LIMIT, ''); }
                } elseif ($allergens === '' && $sweetCarb !== '') {
                    if ($special !== '') { $special = self::truncate($special, self::CHARACTER_LIMIT * 4, ''); }
                    if ($optional !== '') { $optional = self::truncate($optional, self::CHARACTER_LIMIT, ''); }
                } elseif ($allergens !== '' && $sweetCarb !== '') {
                    if ($special !== '') { $special = self::truncate($special, self::CHARACTER_LIMIT * 3, ''); }
                    if ($optional !== '') { $optional = self::truncate($optional, self::CHARACTER_LIMIT, ''); }
                }

                // :658-665 — view=special only emits special/mead entries (and
                // table-scoped only those at the table); view=all emits all.
                if ($view === 'special') {
                    $isSpecial = in_array($style, $specialIngredients, true) || in_array($style, $meadStyles, true);
                    if ($location !== 'default') {
                        $text = ($isSpecial && in_array((int) $e->id, $entriesAtTable))
                            ? $entryInfo.$special.$sweetCarb.$allergens.$optional : '';
                    } elseif ($isSpecial) {
                        $text = $entryInfo.$special.$sweetCarb.$allergens.$optional;
                    } else {
                        $text = '';
                    }
                } else {
                    $text = $entryInfo.$special.$sweetCarb.$allergens.$optional;
                }

                if ($text !== '') {
                    $labels[] = explode("\n", self::ascii($text));
                }
            }
        }

        $filename = self::requiredInfoFilename($ctx, $action, $filter, $psort, $location);

        return [
            'view_name' => 'outputs.labels',
            'view' => ['perSheet' => $psort === '3422' ? 24 : 30, 'labels' => $labels],
            'filename' => $filename,
        ];
    }

    /**
     * Branch 3 — view=quicksort: Avery 5167 judging-number quick-sort labels
     * (legacy labels.output.php quicksort branch). Produces a flat list of
     * 5167 cells; each cell carries an optional top separator class marking a
     * style / category break.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function bottleQuicksort(TenantContext $ctx, string $tb): array
    {
        // output_labels.db.php(:26-34 bottle-judging query) — no received filter.
        $rows = DB::table('brewing')
            ->orderBy('brewCategorySort')->orderBy('brewSubCategory')->orderBy('brewJudgingNumber')
            ->get();

        $default = $tb !== 'short';
        $cells = [];
        $lastStyle = '';

        foreach ($rows as $e) {
            $sep = '';
            if ($lastStyle !== '') {
                $sep = $lastStyle === (string) $e->brewCategory ? 'dashed' : 'solid';
            }
            $lastStyle = (string) $e->brewCategory;

            $judgingNumber = OutputFormat::judgingNumber($e->brewJudgingNumber);
            $entryNumber = sprintf('%06s', (string) $e->id);
            $style = (string) $e->brewCategory.$e->brewSubCategory;
            $styleName = self::ascii(self::truncate((string) $e->brewStyle, 18));
            $brewerName = self::ascii(self::truncate((string) trim($e->brewBrewerFirstName.' '.$e->brewBrewerLastName), 30));

            $first = true;
            foreach (['#1', '#2', '#3/BOS'] as $b) {
                $cells[] = [
                    'sep' => $first ? $sep : '',
                    'lines' => self::quicksortBottleLines($style, $judgingNumber, $b),
                ];
                $first = false;
            }

            if ($default) {
                // Entrant info label (:447-450).
                $cells[] = [
                    'sep' => '',
                    'lines' => ['  '.$style.' '.$styleName, '  '.$brewerName],
                ];

                foreach (['#1', '#2', '#3/BOS'] as $b) {
                    $cells[] = [
                        'sep' => '',
                        'lines' => self::quicksortBottleLines($style, $judgingNumber, $b),
                    ];
                }

                // Big entry|judging number label (:463-466).
                $cells[] = [
                    'sep' => '',
                    'lines' => [$entryNumber === $judgingNumber
                        ? '  '.$entryNumber
                        : '  '.$entryNumber.' | '.$judgingNumber],
                ];
            } else {
                // tb=short compact label (:471-475).
                $cells[] = [
                    'sep' => '',
                    'lines' => [$entryNumber === $judgingNumber
                        ? '  '.$style.' - '.$entryNumber
                        : '  '.$style.' - '.$entryNumber.' | '.$judgingNumber,
                        '  '.$brewerName],
                ];
            }
        }

        return [
            'view_name' => 'outputs.labels_quicksort',
            'view' => ['cells' => $cells],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_QuickSort_Labels_Judging_Numbers.pdf',
        ];
    }

    /**
     * Branch 4 — action=bottle-entry-round|bottle-judging-round: round
     * bottle labels (legacy ~:730-790). filter=recent only prints entries
     * updated after the registration deadline.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function bottleRound(TenantContext $ctx, string $action, string $filter, int $sort, string $psort): array
    {
        $judging = $action === 'bottle-judging-round';
        $aabc = $ctx->prefsStr('prefsStyleSet') === 'AABC';
        $deadline = $ctx->contestEpoch('contestRegistrationDeadline');

        $rows = DB::table('brewing')
            ->orderBy('brewCategorySort')->orderBy('brewSubCategory')
            ->orderBy($judging ? 'brewJudgingNumber' : 'id')
            ->get();

        $cells = [];
        foreach ($rows as $e) {
            $entryNo = $judging
                ? sprintf('%06s', (string) $e->brewJudgingNumber)
                : sprintf('%06s', (string) $e->id);

            $cat = $aabc
                ? ltrim((string) $e->brewCategory, '0').'.'.ltrim((string) $e->brewSubCategory, '0')
                : (string) $e->brewCategory.$e->brewSubCategory;

            for ($i = 0; $i < $sort; $i++) {
                $emit = false;
                if ($entryNo !== '' && $filter === 'default') {
                    $emit = true;
                }
                if ($entryNo !== '' && $filter === 'recent'
                    && strtotime((string) $e->brewUpdated) > (int) $deadline) {
                    $emit = true;
                }

                if ($emit) {
                    $cells[] = [$entryNo, '('.$cat.')'];
                }
            }
        }

        return [
            'view_name' => 'outputs.labels_round',
            'view' => ['cells' => $cells, 'psort' => $psort],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Round_Bottle_Labels_'.($judging ? 'Judging' : 'Entry').'_Numbers'
                .($psort === 'OL32' ? '_.50_Inch' : ($psort === 'OL5275WR' ? '_.75_Inch' : ''))
                .($filter === 'recent' ? '_Added_After_Reg_Close' : '')
                .'.pdf',
        ];
    }

    /**
     * Branch 5 — action=bottle-category-round: category-only round labels
     * (legacy ~:796-840).
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function bottleCategoryRound(TenantContext $ctx, string $filter, int $sort, string $psort): array
    {
        $aabc = $ctx->prefsStr('prefsStyleSet') === 'AABC';

        $q = DB::table('brewing')->orderBy('brewCategorySort')->orderBy('brewSubCategory');
        if ($filter !== 'default') {
            $q->where('brewCategorySort', $filter);
        }

        $rows = $q->get(['brewCategorySort', 'brewSubCategory']);

        $cells = [];
        foreach ($rows as $e) {
            $cat = $aabc
                ? ltrim((string) $e->brewCategorySort, '0').'.'.ltrim((string) $e->brewSubCategory, '0')
                : (string) $e->brewCategorySort.$e->brewSubCategory;
            for ($i = 0; $i < $sort; $i++) {
                $cells[] = [$cat];
            }
        }

        return [
            'view_name' => 'outputs.labels_round',
            'view' => ['cells' => $cells, 'psort' => $psort],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Round_Bottle_Labels_Category_Only'
                .($filter !== 'default' ? '_Category_'.$filter : '')
                .($psort === 'OL32' ? '_.50_Inch' : ($psort === 'OL5275WR' ? '_.75_Inch' : ''))
                .'.pdf',
        ];
    }

    /** @return \Illuminate\Database\Query\Builder */
    private static function entriesQuery(string $action, string $filter, string $tb)
    {
        $q = DB::table('brewing');
        if ($filter === 'default') {
            if ($tb === 'received') {
                $q->where('brewReceived', '1');
            }
        } else {
            $q->where('brewCategorySort', $filter);
            if ($tb === 'received') {
                $q->where('brewReceived', '1');
            }
        }

        return $q->orderBy('brewCategorySort')->orderBy('brewSubCategory')
            ->orderBy($action === 'bottle-entry' ? 'id' : 'brewJudgingNumber');
    }

    /**
     * Build the special-ingredients and mead/cider style-key arrays the
     * required-info branch matches $style against (legacy :58-76). Returned
     * keys are brewStyleGroup+brewStyleNum (BA: brewStyleNum only).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function specialAndMead(bool $ba): array
    {
        $special = [];
        $mead = [];
        foreach (DB::table('styles')->get() as $s) {
            $key = $ba ? (string) $s->brewStyleNum : (string) $s->brewStyleGroup.(string) $s->brewStyleNum;
            if ((int) $s->brewStyleReqSpec === 1) {
                $special[] = $key;
            }
            if ((int) $s->brewStyleStrength === 1 || (int) $s->brewStyleCarb === 1 || (int) $s->brewStyleSweet === 1) {
                $mead[] = $key;
            }
        }

        return [$special, $mead];
    }

    /** Legacy :525-542 abbreviation substitutions, in order. */
    private static function abbreviateStyleName(string $styleName): string
    {
        $styleName = str_replace('Pre-Prohibition', 'Pre-Prohib.', $styleName);
        $styleName = str_replace('Fermentation', 'Ferm.', $styleName);
        $styleName = str_replace('Premium', 'Prem.', $styleName);
        $styleName = str_replace('Australian', 'Aust.', $styleName);
        $styleName = str_replace('Spice, Herb, or Vegetable', 'Spice/Herb/Veg', $styleName);
        $styleName = str_replace('Alternative', 'Alt.', $styleName);
        $styleName = str_replace('Classic Style', 'Cl. Style', $styleName);
        $styleName = str_replace('Specialty', 'Spec.', $styleName);
        $styleName = str_replace('Speciality', 'Spec.', $styleName);

        return str_replace('with', 'w/', $styleName);
    }

    /**
     * truncate() (output.lib.php:154): word-aware truncation. `$append`
     * (default "") is appended when the string is shortened.
     */
    private static function truncate(string $string, int $width, string $append = '', int $maxWordLength = 20): string
    {
        $parts = preg_split('/([\s\n\r]+)/', $string, -1, PREG_SPLIT_DELIM_CAPTURE);
        $partsCount = count($parts);

        // Single word: truncate by character count.
        if ($partsCount === 1 && mb_strlen($string) > $width) {
            $appendLen = mb_strlen($append);

            return mb_substr($string, 0, $width - $appendLen).$append;
        }

        $length = 0;
        $lastPart = 0;
        for (; $lastPart < $partsCount; ++$lastPart) {
            $length += mb_strlen($parts[$lastPart]);
            if ($length > $width) {
                $part = $parts[$lastPart];
                if (! preg_match('/[\s\n\r]/', $part) && mb_strlen($part) >= $maxWordLength) {
                    $appendLen = mb_strlen($append);
                    $remaining = $width - ($length - mb_strlen($part)) - $appendLen;
                    if ($remaining >= 1) {
                        $r = implode('', array_slice($parts, 0, $lastPart));
                        $r .= mb_substr($part, 0, $remaining).$append;

                        return $r;
                    }
                }
                break;
            }
        }

        $r = implode('', array_slice($parts, 0, $lastPart));

        if (mb_strlen($string) > $width) {
            $r = rtrim($r);
            $r .= $append;
        }

        return $r;
    }

    /** Legacy iconv(UTF-8, ASCII//TRANSLIT//IGNORE) via Laravel's Str::ascii(). */
    private static function ascii(string $value): string
    {
        return Str::ascii($value);
    }

    /** Quicksort bottle cell: two aligned lines (leading blank line dropped;
     *  the Blade applies the top padding the legacy leading "\n" provided). */
    private static function quicksortBottleLines(string $style, string $judgingNumber, string $bottle): array
    {
        return [
            sprintf('%s  %s', $style, $judgingNumber),
            sprintf('%s', $bottle),
        ];
    }

    /** Required-info filename (legacy :522-531). */
    private static function requiredInfoFilename(TenantContext $ctx, string $action, string $filter, string $psort, string $location): string
    {
        $filename = str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
            .'_Bottle_Labels_'.($action === 'bottle-entry' ? 'Entry_Numbers' : 'Judging_Numbers')
            .($filter !== 'default' ? '_Category_'.$filter : '')
            .'_Req_Info'
            .($psort === '3422' ? '_Avery3422' : '_Avery5160');

        if ($location !== 'default') {
            $tableNumber = DB::table('judging_tables')->where('tableLocation', $location)->value('tableNumber');
            $filename .= '_Table_'.($tableNumber ?? '');
        }

        return $filename.'.pdf';
    }
}
