<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\OutputFormat;
use App\Support\Outputs\StreamPdf;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
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
        $go = (string) $request->query('go', '');

        // go=judging_tables: box labels (no filter) and virtual judge
        // labels (filter=judges) — legacy labels.output.php judging_tables
        // branch.
        if ($go === 'judging_tables') {
            $filter = (string) $request->query('filter', 'default');
            $sort = max(1, (int) $request->query('sort', '1'));
            $psort = (string) $request->query('psort', '5160');
            $data = $filter === 'judges'
                ? self::virtualJudgeLabels($ctx, $psort, $sort)
                : self::boxLabels($ctx, $psort, $sort);
            $viewName = $filter === 'judges' ? 'outputs.labels' : 'outputs.labels_box';

            return StreamPdf::response($viewName, $data['view'], $data['filename']);
        }

        // go=participants judging_nametags / judging_labels (id=default).
        if ($action === 'judging_nametags') {
            $data = self::nametags($ctx);

            return StreamPdf::response('outputs.labels_nametag', $data['view'], $data['filename']);
        }

        if ($action === 'judging_labels') {
            $psort = (string) $request->query('psort', '5160');
            $data = self::judgingLabels($ctx, $psort);

            return StreamPdf::response('outputs.labels', $data['view'], $data['filename']);
        }

        // go=judging_scores&action=awards: award labels (filter=default),
        // winner address labels (filter=address), medal round labels
        // (filter=round).
        if ($action === 'awards') {
            $filter = (string) $request->query('filter', 'default');
            $psort = (string) $request->query('psort', '5160');
            $data = self::awardLabels($ctx, $filter, $psort);
            $viewName = $filter === 'round' ? 'outputs.labels_round' : 'outputs.labels';

            return StreamPdf::response($viewName, $data['view'], $data['filename']);
        }
        if (str_starts_with($action, 'bottle-')) {
            $view = (string) $request->query('view', 'default');
            $psort = (string) $request->query('psort', '5160');
            $filter = (string) $request->query('filter', 'default');
            $tb = (string) $request->query('tb', 'default');
            $location = (string) $request->query('location', 'default');
            $sort = max(1, (int) $request->query('sort', '1'));

            $viewName = match (true) {
                $action === 'bottle-category-round' => 'outputs.labels_round',
                str_ends_with($action, '-round') => 'outputs.labels_round',
                $view === 'quicksort' => 'outputs.labels_quicksort',
                $view === 'default' => 'outputs.labels',
                default => 'outputs.labels',
            };
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

            return StreamPdf::response($viewName, $data['view'], $data['filename']);
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
                        if (str_contains($lowerInfo, 'session strength')) {
                            $beerStrength .= '*Session* ';
                        }
                        if (str_contains($lowerInfo, 'standard strength')) {
                            $beerStrength .= '*Standard* ';
                        }
                        if (str_contains($lowerInfo, 'double strength')) {
                            $beerStrength .= '*Double* ';
                        }
                        if (str_contains($lowerInfo, 'table strength')) {
                            $beerStrength .= '*Table* ';
                        }
                        if (str_contains($lowerInfo, 'super strength')) {
                            $beerStrength .= '*Super* ';
                        }
                        if (str_contains($lowerInfo, 'low/none sweetness')) {
                            $beerSweetness .= '*Low/No Sweet* ';
                        }
                        if (str_contains($lowerInfo, 'medium sweetness')) {
                            $beerSweetness .= '*Med Sweet* ';
                        }
                        if (str_contains($lowerInfo, 'high sweetness')) {
                            $beerSweetness .= '*High Sweet* ';
                        }
                        if (str_contains($lowerInfo, 'low carbonation')) {
                            $beerCarbonation .= '*Low Carb* ';
                        }
                        if (str_contains($lowerInfo, 'medium carbonation')) {
                            $beerCarbonation .= '*Med Carb* ';
                        }
                        if (str_contains($lowerInfo, 'high carbonation')) {
                            $beerCarbonation .= '*High Carb* ';
                        }

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
                    if ((string) $e->brewMead1 !== '') {
                        $sweetCarb .= sprintf('*%s* ', $e->brewMead1);
                    }
                    if ((string) $e->brewMead2 !== '') {
                        $sweetCarb .= sprintf('*%s* ', $e->brewMead2);
                    }
                    if ((string) $e->brewMead3 !== '') {
                        $sweetCarb .= sprintf('*%s* ', $e->brewMead3);
                    }

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
                    if ($special !== '') {
                        $special = self::truncate($special, self::CHARACTER_LIMIT * 4, '');
                    }
                    if ($optional !== '') {
                        $optional = self::truncate($optional, self::CHARACTER_LIMIT, '');
                    }
                } elseif ($allergens === '' && $sweetCarb !== '') {
                    if ($special !== '') {
                        $special = self::truncate($special, self::CHARACTER_LIMIT * 4, '');
                    }
                    if ($optional !== '') {
                        $optional = self::truncate($optional, self::CHARACTER_LIMIT, '');
                    }
                } elseif ($allergens !== '' && $sweetCarb !== '') {
                    if ($special !== '') {
                        $special = self::truncate($special, self::CHARACTER_LIMIT * 3, '');
                    }
                    if ($optional !== '') {
                        $optional = self::truncate($optional, self::CHARACTER_LIMIT, '');
                    }
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

    /** @return Builder */
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
        if ($parts === false) {
            return $string;
        }
        $partsCount = count($parts);

        // Single word: truncate by character count.
        if ($partsCount === 1 && mb_strlen($string) > $width) {
            $appendLen = mb_strlen($append);

            return mb_substr($string, 0, $width - $appendLen).$append;
        }

        $length = 0;
        $lastPart = 0;
        for (; $lastPart < $partsCount; $lastPart++) {
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

    /**
     * Quicksort bottle cell: two aligned lines (leading blank line dropped;
     * the Blade applies the top padding the legacy leading "\n" provided).
     *
     * @return list<string>
     */
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

    /**
     * Box labels — go=judging_tables (no filter): one label per judging
     * table with a big table-number cell, table name, location name and the
     * style-number list, `sort` copies each (legacy labels.output.php
     * judging_tables else branch).
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function boxLabels(TenantContext $ctx, string $psort, int $sort): array
    {
        $styleSet = (string) $ctx->prefsStr('prefsStyleSet');

        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get();

        $locationIds = $tables->pluck('tableLocation')->filter()->unique()->all();
        $locations = $locationIds !== []
            ? DB::table('judging_locations')->whereIn('id', $locationIds)->get()->keyBy('id')
            : collect();

        $styleIds = collect($tables)->flatMap(fn ($t) => array_filter(explode(',', (string) $t->tableStyles)))
            ->filter()->unique()->values()->all();
        $styles = $styleIds !== []
            ? DB::table('styles')->whereIn('id', $styleIds)->get()->keyBy('id')
            : collect();

        $labels = [];
        foreach ($tables as $t) {
            $styleParts = [];
            foreach (array_filter(explode(',', (string) $t->tableStyles)) as $sid) {
                $style = $styles->get((int) $sid);
                if ($style !== null) {
                    $num = self::styleNumberConst((string) $style->brewStyleGroup, (string) $style->brewStyleNum, $styleSet);
                    if ($num !== '') {
                        $styleParts[] = $num;
                    }
                }
            }
            $stylesList = implode(', ', $styleParts);

            $tableName = self::ascii(self::truncate(htmlspecialchars_decode((string) $t->tableName), 30));
            $loc = $locations->get($t->tableLocation);
            $location = '';
            $grey = false;
            if ($loc !== null) {
                $location = self::ascii(self::truncate(htmlspecialchars_decode((string) $loc->judgingLocName), 30));
                $grey = (int) $loc->judgingLocType === 1;
            }

            for ($i = 0; $i < $sort; $i++) {
                $labels[] = [
                    'number' => (string) $t->tableNumber,
                    'name' => $tableName,
                    'location' => $location,
                    'styles' => $stylesList,
                    'grey' => $grey,
                ];
            }
        }

        return [
            'view_name' => 'outputs.labels_box',
            'view' => ['perSheet' => $psort === '3422' ? 24 : 30, 'labels' => $labels],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Box_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf',
        ];
    }

    /**
     * Virtual judge labels — go=judging_tables&filter=judges (legacy
     * judging_tables judges branch): judges assigned to a virtual
     * (judgingLocType=1) location get name, city/state and the
     * "Table N"/"Tables N, M" assignment line, `sort` copies each.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function virtualJudgeLabels(TenantContext $ctx, string $psort, int $sort): array
    {
        $virtual = DB::table('judging_locations')->where('judgingLocType', 1)->get(['id'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'check' => 'Y-'.$l->id])->values()->all();

        $brewers = DB::table('brewer')->where('brewerJudge', 'Y')->orderBy('brewerLastName')->get();

        $labels = [];
        foreach ($brewers as $b) {
            $locations = array_filter(explode(',', (string) $b->brewerJudgeLocation));

            $isVirtual = false;
            foreach ($virtual as $v) {
                if (in_array($v['check'], $locations, true)) {
                    $isVirtual = true;
                    break;
                }
            }
            if (! $isVirtual) {
                continue;
            }

            // judge_assignment(uid, locId) -> the tableNumber the judge has at
            // that virtual location, or null when unassigned (legacy :196).
            $tableNumbers = [];
            foreach ($virtual as $v) {
                if (! in_array($v['check'], $locations, true)) {
                    continue;
                }
                $tableNumbers[] = DB::table('judging_assignments')
                    ->join('judging_tables', 'judging_tables.id', '=', 'judging_assignments.assignTable')
                    ->where('judging_assignments.bid', $b->uid)
                    ->where('judging_assignments.assignLocation', $v['id'])
                    ->value('judging_tables.tableNumber');
            }

            if ($tableNumbers !== []) {
                $flight = '';
                $count = 0;
                foreach ($tableNumbers as $tn) {
                    if ($tn !== null && $tn !== '') {
                        $flight .= $tn.', ';
                        $count++;
                    }
                }
                $tableLine = self::ascii(rtrim(($count > 1 ? 'Tables ' : 'Table ').$flight, ', '));
            } else {
                $tableLine = 'Table: ______';
            }

            for ($i = 0; $i < $sort; $i++) {
                $labels[] = [
                    'name' => self::ascii($b->brewerFirstName.' '.$b->brewerLastName),
                    'loc' => self::ascii($b->brewerCity.', '.$b->brewerState),
                    'table' => $tableLine,
                ];
            }
        }

        return [
            'view_name' => 'outputs.labels',
            'view' => [
                'perSheet' => $psort === '3422' ? 24 : 30,
                'labels' => collect($labels)->map(fn ($l) => [$l['name'], $l['loc'], $l['table']])->all(),
            ],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Virtual_Judge_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf',
        ];
    }

    /**
     * All judge scoresheet labels — go=participants&action=judging_labels&id=default
     * (legacy judging_labels id=default branch): a full sheet of labels per
     * judge (24/30 copies), each with name, BJCP rank, secondary ranks and
     * the lowercased email.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function judgingLabels(TenantContext $ctx, string $psort): array
    {
        $numberOfLabels = $psort === '3422' ? 24 : 30;
        $characterLimit = 32 + 6; // legacy `+= 6` for Arial

        $rows = DB::table('brewer')
            ->join('staff', 'staff.uid', '=', 'brewer.uid')
            ->where('staff.staff_judge', '1')
            ->where('brewer.brewerJudge', 'Y')
            ->select('brewer.id', 'brewer.brewerFirstName', 'brewer.brewerLastName',
                'brewer.brewerJudgeID', 'brewer.brewerEmail', 'brewer.brewerJudgeRank',
                'brewer.brewerJudgeMead', 'brewer.brewerJudgeCider', 'staff.uid')
            ->orderBy('brewer.brewerLastName')
            ->get();

        $labels = [];
        foreach ($rows as $r) {
            $bjcpRank = explode(',', (string) $r->brewerJudgeRank);
            $rank = self::bjcpRank($bjcpRank[0] ?? '', 2);
            if (str_contains($rank, 'Non-BJCP Judge')
                && (((string) $r->brewerJudgeMead === 'Y') || ((string) $r->brewerJudgeCider === 'Y'))) {
                $rank = 'BJCP Cider or Mead Judge';
            }

            $judgeId = self::validateBjcpId((string) $r->brewerJudgeID)
                ? ' ('.$r->brewerJudgeID.')' : '';
            $rank .= strtoupper($judgeId);

            $mead = '';
            $cider = '';
            $pro = '';
            $certCicerone = '';
            $advCicerone = '';
            $mastCicerone = '';

            if ((string) $r->brewerJudgeMead === 'Y') {
                $mead = 'Certified Mead Judge';
            }
            if (in_array('Certified Cider Guide', $bjcpRank, true)) {
                $cider = 'Certified Cider Guide';
            }
            if (in_array('Certified Pommelier', $bjcpRank, true)) {
                $cider = 'Certified Pommelier';
            }
            if ((string) $r->brewerJudgeCider === 'Y') {
                $cider = 'Certified Cider Judge';
            }
            if (in_array('Professional Brewer', $bjcpRank, true)) {
                $pro = 'Professional Brewer';
            }
            if (in_array('Certified Cicerone', $bjcpRank, true)) {
                $certCicerone = 'Certified Cicerone';
            }
            if (in_array('Advanced Cicerone', $bjcpRank, true)) {
                $advCicerone = 'Advanced Cicerone';
            }
            if (in_array('Master Cicerone', $bjcpRank, true)) {
                $mastCicerone = 'Master Cicerone';
            }

            $cicerone = [];
            $other = [];
            if ($mastCicerone !== '') {
                $cicerone[] = $mastCicerone;
            } elseif ($certCicerone === '' && $advCicerone !== '') {
                $cicerone[] = $advCicerone;
            } elseif ($advCicerone === '' && $certCicerone !== '') {
                $cicerone[] = $certCicerone;
            }

            if ($mead !== '') {
                $other[] = $mead;
            }
            if ($cider !== '') {
                $other[] = $cider;
            }
            if ($pro !== '') {
                $other[] = $pro;
            }

            if ($cicerone !== [] && $other !== []) {
                $otherCombined = array_merge($cicerone, $other);
            } elseif ($cicerone !== [] && $other === []) {
                $otherCombined = $cicerone;
            } elseif ($other !== []) {
                $otherCombined = $other;
            } else {
                $otherCombined = '';
            }
            $otherRanks = $otherCombined !== '' ? implode(', ', $otherCombined) : '';
            $otherRanks = ltrim($otherRanks, ' ,');
            $otherRanks = ltrim($otherRanks, ' , ');
            $otherRanks = ltrim($otherRanks, ', ');
            $otherRanks = ltrim($otherRanks, ',');

            $firstName = html_entity_decode((string) $r->brewerFirstName);
            $lastName = html_entity_decode((string) $r->brewerLastName);
            $rankLine = self::truncate($rank, $characterLimit);
            $otherLine = $otherRanks !== '' ? self::truncate($otherRanks, $characterLimit) : '';
            $email = strtolower((string) $r->brewerEmail);

            $labelLines = [$firstName.' '.$lastName, $rankLine];
            if ($otherLine !== '') {
                $labelLines[] = $otherLine;
            }
            $labelLines[] = $email;
            $labelLines = array_map(fn ($l) => self::ascii($l), $labelLines);

            for ($i = 0; $i < $numberOfLabels; $i++) {
                $labels[] = $labelLines;
            }
        }

        return [
            'view_name' => 'outputs.labels',
            'view' => ['perSheet' => $numberOfLabels, 'labels' => $labels],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_All_Judge_Scoresheet_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf',
        ];
    }

    /**
     * Judging nametags — go=participants&action=judging_nametags (legacy
     * judging_nametags branch): one Avery 5395 nametag per staff brewer
     * (judge/steward/staff/organizer) with name, role assignment and
     * city/state.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function nametags(TenantContext $ctx): array
    {
        $rows = DB::table('brewer')
            ->join('staff', 'staff.uid', '=', 'brewer.uid')
            ->select('brewer.id', 'brewer.brewerFirstName', 'brewer.brewerLastName',
                'brewer.brewerCity', 'brewer.brewerState',
                'staff.staff_judge', 'staff.staff_steward', 'staff.staff_staff', 'staff.staff_organizer')
            ->orderBy('brewer.brewerLastName')
            ->get();

        $labels = [];
        foreach ($rows as $r) {
            $isStaff = (($r->staff_judge == 1) || ($r->staff_steward == 1)
                || ($r->staff_staff == 1) || ($r->staff_organizer == 1));
            if (! $isStaff) {
                continue;
            }

            $assignment = '';
            if ($r->staff_judge == 1) {
                $assignment .= 'Judge, ';
            }
            if ($r->staff_steward == 1) {
                $assignment .= 'Steward, ';
            }
            if ($r->staff_staff == 1) {
                $assignment .= 'Staff, ';
            }
            if ($r->staff_organizer == 1) {
                $assignment .= 'Organizer';
            }
            $assignment = rtrim($assignment, ', ');
            $assignment = rtrim($assignment, ' ');
            $assignment = rtrim($assignment, ',');

            $location = ($r->brewerCity !== 'Anytown')
                ? $r->brewerCity.', '.$r->brewerState : '';

            $labels[] = [
                'name' => self::ascii(html_entity_decode($r->brewerFirstName.' '.$r->brewerLastName)),
                'assignment' => self::ascii(html_entity_decode($assignment)),
                'location' => self::ascii(html_entity_decode($location)),
            ];
        }

        return [
            'view_name' => 'outputs.labels_nametag',
            'view' => ['labels' => $labels],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Nametags_Avery5395.pdf',
        ];
    }

    /** style_number_const(method 0, common.lib.php:4494) for a style set. */
    private static function styleNumberConst(string $group, string $sub, string $styleSet): string
    {
        // No-numbering sets (the BA sets) print no group/sub style code.
        if (StyleSets::noNumbering($styleSet)) {
            return '';
        }

        return match ($styleSet) {
            'BJCP2021', 'BJCP2025' => ltrim($group, '0').self::styleSeparator($styleSet).ltrim($sub, '0'),
            default => $group.self::styleSeparator($styleSet).$sub,
        };
    }

    /** style_set_display_separator (mirrors PullsheetsController::styleSeparator). */
    private static function styleSeparator(string $styleSet): string
    {
        // `AABC` (2019) is legacy-only; the rest come from StyleSets.
        return $styleSet === 'AABC' ? '.' : StyleSets::separator($styleSet);
    }

    /** bjcp_rank($rank, 2) (common.lib.php:2373). */
    private static function bjcpRank(string $rank, int $method): string
    {
        if ($method === 2) {
            return match ($rank) {
                'None', '', 'Novice', 'Non-BJCP', 'Experienced' => 'Non-BJCP Judge',
                'Professional Brewer', 'Beer Sommelier', 'Certified Cicerone',
                'Master Cicerone', 'Judge with Sensory Training' => $rank,
                default => 'BJCP '.$rank.' Judge',
            };
        }

        return $rank;
    }

    /** validate_bjcp_id() (output.lib.php:291). */
    private static function validateBjcpId(string $input): bool
    {
        if (preg_match('/^TEMP\d{4}$/i', $input)) {
            return true;
        }

        if (strlen($input) !== 5) {
            return false;
        }

        return (bool) preg_match('([a-zA-Z])', $input);
    }

    /**
     * Award / medal / winner-address labels — legacy output/labels.output.php
     * go=judging_scores&action=awards, dispatched on filter: default =
     * award-labels sheet, address = winner address labels, round = medal
     * labels (round). Winner content is built by {@see winningLabels()}; the
     * anonymised corpus has no scored entries, so the labels are empty but the
     * route still streams the correct sheet + filename.
     *
     * @return array{view_name: string, view: array<string, mixed>, filename: string}
     */
    public static function awardLabels(TenantContext $ctx, string $filter, string $psort): array
    {
        if ($filter === 'address') {
            $rows = DB::table('judging_scores as a')
                ->join('brewing as b', 'b.id', '=', 'a.eid')
                ->join('brewer as c', 'c.uid', '=', 'b.brewBrewerID')
                ->whereNotNull('a.scorePlace')
                ->select('b.brewBrewerID', 'c.brewerFirstName', 'c.brewerLastName',
                    'c.brewerAddress', 'c.brewerCity', 'c.brewerState', 'c.brewerZip', 'c.brewerCountry')
                ->orderBy('c.brewerLastName')->orderBy('c.brewerFirstName')
                ->get();

            $labels = [];
            $seen = [];
            foreach ($rows as $r) {
                if (isset($seen[$r->brewBrewerID])) {
                    continue;
                }
                $seen[$r->brewBrewerID] = true;
                $country = $r->brewerCountry !== 'United States' ? (string) $r->brewerCountry : '';
                $labels[] = [
                    self::ascii(html_entity_decode($r->brewerFirstName.' '.$r->brewerLastName)),
                    self::ascii(html_entity_decode((string) $r->brewerAddress)),
                    self::ascii(html_entity_decode(trim(sprintf('%s, %s %s', $r->brewerCity, $r->brewerState, $r->brewerZip), ', '))),
                    self::ascii(html_entity_decode($country)),
                ];
            }

            return [
                'view_name' => 'outputs.labels',
                'view' => ['perSheet' => $psort === '3422' ? 24 : 30, 'labels' => $labels],
                'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                    .'_Winner_Address_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf',
            ];
        }

        $labels = self::winningLabels($ctx, $filter);

        if ($filter === 'round') {
            return [
                'view_name' => 'outputs.labels_round',
                'view' => ['cells' => self::cellsFromLines($labels), 'psort' => $psort],
                'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                    .'_Medal_Labels_'.ucwords($psort).'.pdf',
            ];
        }

        return [
            'view_name' => 'outputs.labels',
            'view' => ['perSheet' => $psort === '3422' ? 24 : 30, 'labels' => $labels],
            'filename' => str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
                .'_Award_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf',
        ];
    }

    /**
     * Winner label text per prefsWinnerMethod (1 = category, 2 = subcategory,
     * 3 = table) plus best-of-show rows — legacy output_labels_awards.db.php.
     *
     * @return list<list<string>>
     */
    public static function winningLabels(TenantContext $ctx, string $filter): array
    {
        $characterLimit = $filter === 'round' ? 18 : 31;
        $stylesSelected = json_decode((string) $ctx->prefsStr('prefsSelectedStyles'), true) ?: [];
        $styleSet = (string) $ctx->prefsStr('prefsStyleSet');
        $method = (int) $ctx->prefsStr('prefsWinnerMethod');

        $labels = [];

        // Best-of-show rows.
        $bos = DB::table('judging_scores_bos')->orderBy('scoreType')->orderBy('scorePlace')->get();
        foreach ($bos as $rowBos) {
            if ((string) $rowBos->scorePlace === '') {
                continue;
            }
            $entry = DB::table('brewing')->where('id', $rowBos->eid)->first();
            if ($entry === null) {
                continue;
            }
            $typeName = (string) (DB::table('style_types')->where('id', $rowBos->scoreType)->value('styleTypeName') ?? '');
            $labels[] = [
                self::displayPlace((string) $rowBos->scorePlace, 1).' - Best of Show ('.$typeName.')',
                self::ascii($entry->brewBrewerFirstName.' '.$entry->brewBrewerLastName),
                "'".self::ascii(trim((string) $entry->brewName))."' ".self::ascii((string) $entry->brewStyle),
            ];
        }

        if ($method === 1) {
            $groups = [];
            foreach (DB::table('styles')->where('brewStyleVersion', $styleSet)->orWhere('brewStyleOwn', 'custom')
                ->get(['id', 'brewStyleGroup']) as $s) {
                if (array_key_exists($s->id, $stylesSelected)) {
                    $groups[] = $s->brewStyleGroup;
                }
            }
            foreach (array_values(array_unique($groups)) as $group) {
                $entryCount = DB::table('brewing')->where('brewCategorySort', $group)->where('brewReceived', '1')->count();
                $scoreRows = DB::table('judging_scores as a')->join('brewing as b', 'b.id', '=', 'a.eid')
                    ->join('brewer as c', 'c.uid', '=', 'b.brewBrewerID')
                    ->where('b.brewCategorySort', $group)
                    ->whereNot('a.scorePlace', '')->orderBy('a.scorePlace')
                    ->select('a.scorePlace', 'b.brewName', 'b.brewCategorySort', 'b.brewSubCategory', 'b.brewStyle', 'c.brewerLastName', 'c.brewerFirstName', 'c.brewerClubs')
                    ->get();
                if ($entryCount > 0 && ! $scoreRows->isEmpty()) {
                    foreach ($scoreRows as $r) {
                        $labels[] = [
                            self::displayPlace((string) $r->scorePlace, 1),
                            self::styleCategoryDisplay((string) $r->brewCategorySort, $styleSet),
                            self::truncate($r->brewerFirstName.' '.$r->brewerLastName, $characterLimit, '...'),
                            "'".self::truncate(trim((string) $r->brewName), $characterLimit, '...')."'",
                            self::truncate((string) $r->brewStyle, $characterLimit),
                        ];
                    }
                }
            }
        } elseif ($method === 2) {
            $groups = [];
            foreach (DB::table('styles')->where('brewStyleVersion', $styleSet)->orWhere('brewStyleOwn', 'custom')
                ->get(['id', 'brewStyleGroup', 'brewStyleNum', 'brewStyle']) as $s) {
                if (array_key_exists($s->id, $stylesSelected)) {
                    $groups[] = [$s->brewStyleGroup, $s->brewStyleNum, $s->brewStyle];
                }
            }
            foreach (array_unique($groups, SORT_REGULAR) as [$group, $num, $name]) {
                $entryCount = DB::table('brewing')->where('brewCategorySort', $group)->where('brewSubCategory', $num)->where('brewReceived', '1')->count();
                $scoreRows = DB::table('judging_scores as a')->join('brewing as b', 'b.id', '=', 'a.eid')
                    ->join('brewer as c', 'c.uid', '=', 'b.brewBrewerID')
                    ->where('b.brewCategorySort', $group)->where('b.brewSubCategory', $num)
                    ->whereNot('a.scorePlace', '')->orderBy('a.scorePlace')
                    ->select('a.scorePlace', 'b.brewName', 'b.brewCategory', 'b.brewSubCategory', 'b.brewStyle', 'c.brewerLastName', 'c.brewerFirstName', 'c.brewerClubs')
                    ->get();
                if ($entryCount > 0 && ! $scoreRows->isEmpty()) {
                    foreach ($scoreRows as $r) {
                        $subcategory = (string) preg_replace('/[0-9]+/', '', (string) $r->brewSubCategory);
                        $style = strtoupper((string) $r->brewCategory).$subcategory;
                        if ($filter === 'round') {
                            $labels[] = [
                                self::displayPlace((string) $r->scorePlace, 1),
                                self::truncate((string) $r->brewStyle, $characterLimit, '...'),
                                self::truncate($r->brewerFirstName.' '.$r->brewerLastName, $characterLimit, '...'),
                                "'".self::truncate(trim((string) $r->brewName), $characterLimit, '...')."'",
                            ];
                        } else {
                            $labels[] = [
                                self::displayPlace((string) $r->scorePlace, 1),
                                $style.': '.self::truncate((string) $r->brewStyle, $characterLimit, '...'),
                                self::truncate($r->brewerFirstName.' '.$r->brewerLastName, $characterLimit, '...'),
                                "'".self::truncate(trim((string) $r->brewName), $characterLimit, '...')."'",
                            ];
                        }
                    }
                }
            }
        } else {
            foreach (DB::table('judging_tables')->orderBy('tableNumber')->get() as $table) {
                $scoreRows = DB::table('judging_scores')->where('scoreTable', $table->id)
                    ->whereIn('scorePlace', ['1', '2', '3', '4', '5'])->orderBy('scorePlace')->get();
                foreach ($scoreRows as $rowScores) {
                    $entry = DB::table('brewing')->where('id', $rowScores->eid)->first();
                    if ($entry === null) {
                        continue;
                    }
                    $labels[] = [
                        self::displayPlace((string) $rowScores->scorePlace, 1),
                        self::truncate((string) $table->tableName, $characterLimit - 3),
                        self::truncate($entry->brewBrewerFirstName.' '.$entry->brewBrewerLastName, $characterLimit, '...'),
                        "'".self::truncate(trim((string) $entry->brewName), $characterLimit, '...')."'",
                        self::truncate((string) $entry->brewStyle, $characterLimit, '...'),
                    ];
                }
            }
        }

        return $labels;
    }

    /** display_place($place, 1) (common.lib.php:2659). */
    private static function displayPlace(string $place, int $method): string
    {
        if ($method === 1) {
            return match ($place) {
                '1' => OutputFormat::ordinal($place),
                '2' => OutputFormat::ordinal($place),
                '3' => OutputFormat::ordinal($place),
                '4' => OutputFormat::ordinal($place),
                '5', 'HM' => 'HM',
                default => 'N/A',
            };
        }

        return OutputFormat::ordinal($place);
    }

    /**
     * style_convert($group, 1) approximation — the award-label category line.
     * The full legacy style_convert is not ported; this returns the group's
     * brewStyleCategory name (unexercised on the anonymised corpus, which has
     * no winners).
     */
    private static function styleCategoryDisplay(string $group, string $styleSet): string
    {
        $name = DB::table('styles')->where('brewStyleGroup', $group)->value('brewStyleCategory') ?? '';

        return self::ascii((string) $name);
    }

    /**
     * @param  list<list<string>>  $labels
     * @return list<list<string>>
     */
    private static function cellsFromLines(array $labels): array
    {
        return array_map(static fn (array $lines): array => $lines, $labels);
    }
}
