<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-table pull sheets (spec §7 P5.1). Pipeline validation case for all
 * Slice D outputs — pattern: single-action controller, admin gate, Blade
 * views → StreamPdf (see ledger/outputs.md).
 *
 * Legacy: output/pullsheets.output.php (+ output_pullsheets*.db.php),
 * dispatched on ?go=judging_tables|judging_locations|judging_scores_bos|
 * mini_bos|all_entry_info. Quirks mirrored are catalogued in
 * ledger/outputs.md; divergences are marked inline.
 *
 * The `flightEntryOrder` column is absent on the anonymised corpus DB
 * (anon-base.sql). Legacy tolerates that by treating every flight as
 * "no manual order saved" (flight_entry_orders() map empty) and falling
 * back to style-grouped / judging-number order — so the port reads the
 * column with `?? null` instead of dereferencing it.
 */
final class PullsheetsController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        // Tables Planning Mode exists to assemble tables/flights before
        // entries are marked paid and received, and its counts deliberately
        // include unreceived entries — so a pull sheet produced now is not
        // the official document. The dashboard and the judging-tables page
        // both tell the admin pullsheets are unavailable in this mode; this
        // is that promise, enforced for direct URLs too.
        if ($ctx->judgingStr('jPrefsTablePlanning') === '1') {
            abort(403, 'Pullsheets are not available while the competition is in Tables Planning Mode. Switch to Tables Competition Mode to generate them.');
        }

        $p = [
            'go' => (string) $request->query('go', 'judging_tables'),
            'view' => (string) $request->query('view', 'default'),
            'filter' => (string) $request->query('filter', 'default'),
            'action' => (string) $request->query('action', 'default'),
            'id' => (string) $request->query('id', 'default'),
            'location' => (string) $request->query('location', 'default'),
            'round' => (string) $request->query('round', 'default'),
            'sort' => (string) $request->query('sort', 'default'),
        ];

        return match ($p['go']) {
            'mini_bos' => StreamPdf::response('outputs.pullsheets_mini', self::miniBosReport($ctx, $p), 'pullsheets.pdf'),
            'judging_scores_bos' => StreamPdf::response('outputs.pullsheets_bos', self::bosReport($ctx, $p), 'pullsheets.pdf'),
            'all_entry_info' => StreamPdf::response('outputs.pullsheets_all_info', self::allInfoReport($ctx, $p), 'pullsheets.pdf'),
            default => StreamPdf::response('outputs.pullsheets', self::tableReport($ctx, $p), 'pullsheets.pdf'),
        };
    }

    /**
     * View payload builder for the default "all tables" pull sheet
     * (go=judging_tables&id=default, no filter). Public so the feature test
     * can assert the exact rows that feed the PDF.
     *
     * @return array{tables: list<array<string, mixed>>, queued: bool, miniBos: bool, view: string, round: string, styleSet: string}
     */
    public static function build(TenantContext $ctx): array
    {
        return self::tableReport($ctx, [
            'go' => 'judging_tables', 'view' => 'default', 'filter' => 'default',
            'action' => 'default', 'id' => 'default', 'location' => 'default',
            'round' => 'default', 'sort' => 'default',
        ]);
    }

    /**
     * go=judging_tables / go=judging_locations — per-table pull sheets.
     *
     * @param  array<string, string>  $p
     * @return array{tables: list<array<string, mixed>>, queued: bool, miniBos: bool, view: string, round: string, styleSet: string}
     */
    public static function tableReport(TenantContext $ctx, array $p): array
    {
        $queued = $ctx->judgingStr('jPrefsQueued') === 'Y';
        $tables = [];

        foreach (self::tableList($ctx, $p) as $table) {
            $tables[] = self::buildTable($ctx, (array) $table, $queued, $p);
        }

        return [
            'queued' => $queued,
            'tables' => $tables,
            'miniBos' => $p['filter'] === 'mini_bos',
            'view' => $p['view'],
            'round' => $p['round'],
            'styleSet' => self::styleSet($ctx),
        ];
    }

    /**
     * Table selection, mirroring output_pullsheets.db.php:
     * go=judging_locations filters by tableLocation; id != "default" picks
     * one table; otherwise ALL tables ordered by tableNumber ASC.
     *
     * @param  array<string, string>  $p
     * @return Collection<int, \stdClass>
     */
    private static function tableList(TenantContext $ctx, array $p)
    {
        $q = DB::table('judging_tables');

        if ($p['go'] === 'judging_locations' && $p['location'] !== 'default') {
            $q->where('tableLocation', (int) $p['location']);
        }

        if ($p['id'] !== 'default') {
            $q->where('id', (int) $p['id']);
        } else {
            $q->orderBy('tableNumber', 'asc');
        }

        return $q->get();
    }

    /**
     * Build one table's report payload.
     *
     * @param  array<string, mixed>  $table
     * @param  array<string, string>  $p
     * @return array<string, mixed>
     */
    private static function buildTable(TenantContext $ctx, array $table, bool $queued, array $p): array
    {
        $view = $p['view'] === 'entry' ? 'entry' : 'default';
        $filter = $p['filter'];
        $round = $p['round'];

        // Style ids from the table's tableStyles CSV, de-duplicated in order.
        $styleIds = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $table['tableStyles'])))));
        if ($styleIds === []) {
            $styleIds = [0];
        }

        // Per-style entry lists, grouped in tableStyles CSV order and, within
        // a style, ordered by the "#" column per view — verbatim legacy
        // iteration over output_pullsheets_entries.db.php (style-grouped, not
        // a flat judging-number sort).
        $styleEntryLists = [];
        foreach ($styleIds as $sid) {
            $style = DB::table('styles')->where('id', $sid)->first();
            if ($style === null) {
                continue;
            }
            $entries = self::styleEntries($ctx, $style, $view, $filter);
            if ($entries === []) {
                continue;
            }
            $styleEntryLists[] = [$style, $entries];
        }

        $entryCount = array_sum(array_map('count', array_column($styleEntryLists, 1)));

        // flightTable-scoped flight rows: entry id => flights row.
        $flightRows = [];
        foreach (DB::table('judging_flights')->where('flightTable', (int) $table['id'])->get() as $f) {
            $flightRows[(int) $f->flightEntryID] = $f;
        }

        $flights = [];
        if ($queued) {
            // One flat style-grouped list per table (legacy queued mode).
            $rows = [];
            foreach ($styleEntryLists as [$style, $entries]) {
                foreach ($entries as $e) {
                    $rows[] = $e;
                }
            }
            $flights[] = ['label' => null, 'round' => null, 'rows' => $rows];
        } else {
            $maxFlight = self::numFlights((int) $table['id']);
            for ($i = 1; $i <= $maxFlight; $i++) {
                $rows = [];
                $roundVal = null;
                foreach ($styleEntryLists as [$style, $entries]) {
                    foreach ($entries as $entry) {
                        $f = $flightRows[(int) $entry['id']] ?? null;
                        if ($f === null || (int) $f->flightNumber !== $i) {
                            continue;
                        }
                        $flightRound = (string) $f->flightRound;
                        if (! self::checkRound($flightRound, $round)) {
                            continue;
                        }
                        $roundVal = $flightRound;
                        $entry['order'] = $f->flightEntryOrder ?? null;
                        $rows[] = $entry;
                    }
                }
                if ($rows === []) {
                    continue;
                }

                // Manual pull order map for this flight (empty when none saved).
                $manual = [];
                foreach ($flightRows as $eid => $f) {
                    if ((int) $f->flightNumber === $i && ($f->flightEntryOrder ?? null) !== null) {
                        $manual[(int) $eid] = (int) ($f->flightEntryOrder ?? 0);
                    }
                }

                // When manual order exists, render one flat list across styles
                // (order ASC, NULL -> PHP_INT_MAX last, ties on the # column);
                // otherwise keep the style-grouped collection order.
                if ($manual !== []) {
                    usort($rows, function (array $a, array $b) use ($manual): int {
                        $oa = $manual[$a['id']] ?? PHP_INT_MAX;
                        $ob = $manual[$b['id']] ?? PHP_INT_MAX;
                        if ($oa !== $ob) {
                            return $oa <=> $ob;
                        }

                        return strnatcmp((string) $a['numCol'], (string) $b['numCol']);
                    });
                }

                $flights[] = [
                    'label' => sprintf('Flight %d, Round %s', $i, $roundVal),
                    'round' => $roundVal,
                    'rows' => $rows,
                ];
            }
        }

        return [
            'id' => (int) $table['id'],
            'number' => (int) $table['tableNumber'],
            'name' => (string) $table['tableName'],
            'locationLine' => self::locationLine($ctx, $table),
            'entryCount' => $entryCount,
            'flightCount' => $queued ? null : self::numFlights((int) $table['id']),
            'flights' => $flights,
        ];
    }

    /**
     * Entries for one style id (legacy output_pullsheets_entries.db.php,
     * non-mini-bos path): received, matching group+sub, ordered by the "#"
     * column per view.
     *
     * @return list<array<string, mixed>>
     */
    private static function styleEntries(TenantContext $ctx, \stdClass $style, string $view, string $filter): array
    {
        if ($filter === 'mini_bos') {
            // Only mini-bos-scored entries (scoreMiniBOS='1').
            $rows = DB::table('judging_scores as a')
                ->join('brewing as b', 'a.eid', '=', 'b.id')
                ->where('b.brewCategorySort', (string) $style->brewStyleGroup)
                ->where('b.brewSubCategory', (string) $style->brewStyleNum)
                ->where('a.scoreMiniBOS', '1')
                ->orderBy($view === 'entry' ? 'b.id' : 'b.brewJudgingNumber')
                ->get();
        } else {
            $rows = DB::table('brewing')
                ->where('brewCategorySort', (string) $style->brewStyleGroup)
                ->where('brewSubCategory', (string) $style->brewStyleNum)
                ->where('brewReceived', '1')
                ->orderBy($view === 'entry' ? 'id' : 'brewJudgingNumber')
                ->get();
        }

        $out = [];
        foreach ($rows as $e) {
            $e = (object) $e;
            if ((string) $e->brewCategorySort === '') {
                continue;
            }
            $out[] = self::entryRow($ctx, $e, $style, $view, $filter);
        }

        return $out;
    }

    /**
     * Display-shaped row for one brewing row under a style.
     *
     * @return array<string, mixed>
     */
    private static function entryRow(TenantContext $ctx, \stdClass $e, ?\stdClass $style, string $view, string $filter): array
    {
        $styleSet = self::styleSet($ctx);
        $id = (int) $e->id;
        $judgingNo = (string) $e->brewJudgingNumber;
        $styleNum = self::styleNumber((string) $e->brewCategorySort, (string) $e->brewSubCategory, $styleSet);
        $category = self::categoryName($ctx, (string) $e->brewCategorySort);

        $score = DB::table('judging_scores')->where('eid', $id)->first();

        return [
            'id' => $id,
            'entryNo' => sprintf('%06d', $id),
            'judgingNo' => sprintf('%06s', $judgingNo),
            // Legacy "#" column: entry id (view=entry) else judging number.
            'numCol' => $view === 'entry' ? sprintf('%06d', $id) : sprintf('%06s', $judgingNo),
            'styleNum' => $styleNum,
            'brewStyle' => (string) $e->brewStyle,
            'category' => $category,
            'style' => trim($styleNum.' '.(string) $e->brewStyle.' '.$category),
            'brewer' => trim(((string) $e->brewBrewerFirstName).' '.(string) $e->brewBrewerLastName),
            'box' => (string) ($e->brewBoxNum ?? ''),
            'miniBos' => ($score->scoreMiniBOS ?? null) == 1,
            'proAm' => (int) ($e->brewerProAm ?? 0),
            'scorePlace' => (string) ($score->scorePlace ?? ''),
            'requiredInfo' => (string) ($e->brewInfo ?? ''),
            'optionalInfo' => (string) ($e->brewInfoOptional ?? ''),
            'specifics' => (string) ($e->brewComments ?? ''),
            'allergens' => (string) ($e->brewPossAllergens ?? ''),
            'notes' => (string) ($e->brewStaffNotes ?? ''),
            'info' => self::infoRows($e, $style, $styleSet),
        ];
    }

    /**
     * table_location(): location name + long-form local date-time, only
     * when the table points at exactly one location row with a date.
     *
     * @param  array<string, mixed>  $table
     */
    private static function locationLine(TenantContext $ctx, array $table): ?string
    {
        if (empty($table['tableLocation'])) {
            return null;
        }

        $location = DB::table('judging_locations')->where('id', $table['tableLocation'])->first();
        if ($location === null || ! is_numeric($location->judgingDate)) {
            return null;
        }

        $when = DateFmt::dateTime(
            (string) $location->judgingDate,
            $ctx->prefsStr('prefsTimeZone'),
            $ctx->prefsStr('prefsDateFormat'),
            $ctx->prefsStr('prefsTimeFormat'),
            'long',
            withZone: false,
        );

        return $when === null ? $location->judgingLocName : $location->judgingLocName.', '.$when;
    }

    /** number_of_flights(): MAX(flightNumber) for the table. */
    private static function numFlights(int $tableId): int
    {
        return (int) (DB::table('judging_flights')->where('flightTable', $tableId)->max('flightNumber') ?? 0);
    }

    /** check_flight_round(): entry passes the round filter. */
    private static function checkRound(string $flightRound, string $round): bool
    {
        if ($round === 'default') {
            return $flightRound !== '';
        }

        return $flightRound !== '' && $flightRound === $round;
    }

    /** style_number_const(method 1): group + style-set separator + sub. */
    private static function styleNumber(string $group, string $sub, string $styleSet): string
    {
        if ($styleSet === 'BA') {
            return '';
        }

        return $group.self::styleSeparator($styleSet).$sub;
    }

    /** Style-set display separator (styles.inc.php). */
    private static function styleSeparator(string $styleSet): string
    {
        return match ($styleSet) {
            'AABC', 'AABC2022', 'AABC2025' => '.',
            'NWCiderCup' => '-',
            default => '',
        };
    }

    /** style_convert(group, 1): group category name (brewStyleCategory col). */
    private static function categoryName(TenantContext $ctx, string $group): string
    {
        $row = ParticipantSummaryController::styleRow($group, null, $ctx);

        return $row === null ? '' : (string) ($row->brewStyleCategory ?? '');
    }

    private static function styleSet(TenantContext $ctx): string
    {
        return $ctx->prefsStr('prefsStyleSet') ?? '';
    }

    /**
     * go=mini_bos — one flat Mini-BOS pull sheet (legacy output_pullsheets_mini_bos.db.php).
     *
     * @param  array<string, string>  $p
     * @return array<string, mixed>
     */
    public static function miniBosReport(TenantContext $ctx, array $p): array
    {
        $view = $p['view'] === 'entry' ? 'entry' : 'default';
        $styleSet = self::styleSet($ctx);

        $rows = DB::table('judging_scores as a')
            ->join('brewing as b', 'a.eid', '=', 'b.id')
            ->where('a.scoreMiniBOS', '1')
            ->orderBy($view === 'entry' ? 'b.id' : 'b.brewJudgingNumber')
            ->get();

        $entries = [];
        foreach ($rows as $e) {
            $e = (object) $e;
            $style = DB::table('styles')->where('brewStyleGroup', (string) $e->brewCategorySort)
                ->where('brewStyleNum', (string) $e->brewSubCategory)->first();
            $entries[] = self::entryRow($ctx, $e, $style, $view, 'mini_bos');
        }

        return ['view' => $view, 'styleSet' => $styleSet, 'rows' => $entries];
    }

    /**
     * go=judging_scores_bos — BOS pull sheets per style type
     * (legacy output_pullsheets_bos.db.php).
     *
     * @param  array<string, string>  $p
     * @return array<string, mixed>
     */
    public static function bosReport(TenantContext $ctx, array $p): array
    {
        $view = $p['view'] === 'entry' ? 'entry' : 'default';
        $styleSet = self::styleSet($ctx);
        $action = $p['action'];
        $filter = $p['filter'];

        if ($p['id'] === 'default') {
            $types = DB::table('style_types')->orderBy('id')->get();
        } else {
            $types = DB::table('style_types')->where('id', (int) $p['id'])->get();
        }

        $groups = [];
        foreach ($types as $type) {
            $type = (object) $type;
            if ($type->styleTypeBOS !== 'Y') {
                continue;
            }
            $groups[] = self::bosGroup($ctx, $type, $view, $action, $filter, $styleSet);
        }

        return ['view' => $view, 'styleSet' => $styleSet, 'groups' => $groups];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bosGroup(TenantContext $ctx, \stdClass $type, string $view, string $action, string $filter, string $styleSet): array
    {
        $query = DB::table('judging_scores as a')
            ->join('brewing as b', 'a.eid', '=', 'b.id')
            ->join('brewer as c', 'c.uid', '=', 'b.brewBrewerID')
            ->where('a.scoreType', (int) $type->id);

        if ($action === 'pro-am' && $filter !== 'default') {
            if ($filter === '1') {
                $query->where('a.scorePlace', '1');
            } elseif ($filter === '2') {
                $query->whereIn('a.scorePlace', ['1', '2']);
            } elseif ($filter === '3') {
                $query->whereIn('a.scorePlace', ['1', '2', '3']);
            }
        } elseif ((string) $type->styleTypeBOSMethod === '1') {
            $query->where('a.scorePlace', '1');
        } elseif ((string) $type->styleTypeBOSMethod === '2') {
            $query->whereIn('a.scorePlace', ['1', '2']);
        } elseif ((string) $type->styleTypeBOSMethod === '3') {
            $query->whereIn('a.scorePlace', ['1', '2', '3']);
        }

        $rows = $query->orderBy('a.scoreTable')->get();

        $entries = [];
        foreach ($rows as $e) {
            $e = (object) $e;
            $style = DB::table('styles')->where('brewStyleGroup', (string) $e->brewCategorySort)
                ->where('brewStyleNum', (string) $e->brewSubCategory)->first();
            $row = self::entryRow($ctx, $e, $style, $view, 'default');
            $row['scorePlace'] = (string) ($e->scorePlace ?? '');
            $entries[] = $row;
        }

        return ['name' => (string) $type->styleTypeName, 'rows' => $entries];
    }

    /**
     * go=all_entry_info — "Entries with Additional Info" (view=default) or
     * judge inventories (view=judge_inventory).
     *
     * @param  array<string, string>  $p
     * @return array<string, mixed>
     */
    public static function allInfoReport(TenantContext $ctx, array $p): array
    {
        $view = $p['view'];
        if ($view === 'judge_inventory') {
            return self::judgeInventory($ctx, $p);
        }

        $styleSet = self::styleSet($ctx);
        $viewEntry = $p['view'] === 'entry' ? 'entry' : 'default';
        $tables = [];

        foreach (self::tableList($ctx, $p) as $table) {
            $table = (array) $table;
            $styleIds = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $table['tableStyles'])))));
            if ($styleIds === []) {
                $styleIds = [0];
            }

            $rows = [];
            foreach ($styleIds as $sid) {
                $style = DB::table('styles')->where('id', $sid)->first();
                if ($style === null) {
                    continue;
                }
                foreach (self::styleEntries($ctx, $style, $viewEntry, 'default') as $entry) {
                    if (! self::hasAdditionalInfo($entry)) {
                        continue;
                    }
                    $rows[] = $entry;
                }
            }

            $tables[] = [
                'id' => (int) $table['id'],
                'number' => (int) $table['tableNumber'],
                'name' => (string) $table['tableName'],
                'locationLine' => self::locationLine($ctx, $table),
                'rows' => $rows,
            ];
        }

        return ['view' => $viewEntry, 'styleSet' => $styleSet, 'tables' => $tables];
    }

    /**
     * Legacy show_record guard: only entries with any additional info text.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function hasAdditionalInfo(array $entry): bool
    {
        $info = $entry['info'];
        foreach ($info as $i) {
            if ((string) $i['value'] !== '') {
                return true;
            }
        }

        return false;
    }

    /** judge_info(): judge's brewer first/last name by uid. */
    private static function judgeName(int $bid, bool $first): string
    {
        $row = DB::table('brewer')->where('uid', $bid)->first();

        return $row === null
            ? ''
            : (string) ($first ? $row->brewerFirstName : $row->brewerLastName);
    }

    /**
     * view=judge_inventory — one inventory per judge assignment.
     *
     * @param  array<string, string>  $p
     * @return array<string, mixed>
     */
    private static function judgeInventory(TenantContext $ctx, array $p): array
    {
        $styleSet = self::styleSet($ctx);
        $view = $p['sort'] === 'entry' ? 'entry' : 'default';
        $filter = $p['filter'];

        $assignments = DB::table('judging_assignments')->get();
        $inv = [];

        foreach ($assignments as $a) {
            $a = (object) $a;
            if ($filter === 'J' && $a->assignment !== 'J') {
                continue;
            }
            $tableInfo = DB::table('judging_tables')->where('id', $a->assignTable)->first();
            if ($tableInfo === null) {
                continue;
            }
            $tableInfo = (array) $tableInfo;

            $rows = [];
            $found = false;
            $styleIds = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $tableInfo['tableStyles'])))));
            foreach ($styleIds as $sid) {
                $style = DB::table('styles')->where('id', $sid)->first();
                if ($style === null) {
                    continue;
                }
                foreach (self::styleEntries($ctx, $style, $view, 'default') as $entry) {
                    $jid = (int) DB::table('judging_flights')->where('flightEntryID', $entry['id'])->value('flightNumber');
                    if ($jid !== (int) $a->assignFlight) {
                        continue;
                    }
                    $rows[] = $entry;
                    $found = true;
                }
            }

            if (! $found) {
                continue;
            }

            $inv[] = [
                'judgeFirst' => self::judgeName((int) $a->bid, true),
                'judgeLast' => self::judgeName((int) $a->bid, false),
                'tableNumber' => (string) $tableInfo['tableNumber'],
                'tableName' => (string) $tableInfo['tableName'],
                'locationLine' => self::locationLine($ctx, $tableInfo),
                'flight' => (string) $a->assignFlight,
                'round' => (string) $a->assignRound,
                'roles' => (string) ($a->assignRoles ?? ''),
                'entryCount' => count($rows),
                'rows' => $rows,
            ];
        }

        return [
            'view' => $view,
            'styleSet' => $styleSet,
            'inventory' => $inv,
        ];
    }

    /**
     * Special-ingredient cell contents. Legacy labels come from the language
     * file; the standalone build hardcodes English.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function infoRows(\stdClass $entry, ?\stdClass $style, string $styleSet): array
    {
        $rows = [];

        // brewInfo shows only when the style demands special-ingredient info
        // (brewStyleReqSpec=1), labelled "Regional Variation" for BJCP2021/25
        // style 2A (legacy style_convert case "9" exception).
        if ((string) ($entry->brewInfo ?? '') !== '' && $style !== null && (int) $style->brewStyleReqSpec === 1) {
            $regional = in_array($styleSet, ['BJCP2021', 'BJCP2025'], true)
                && ltrim((string) $entry->brewCategorySort, '0') === '2'
                && (string) $entry->brewSubCategory === 'A';
            $rows[] = [
                'label' => $regional ? 'Regional Variation' : 'Required Info',
                'value' => str_replace('^', ' | ', (string) $entry->brewInfo),
            ];
        }

        foreach ([
            ['Optional Info', $entry->brewInfoOptional ?? ''],
            ['Brewer Specifics', $entry->brewComments ?? ''],
            ['Carbonation', $entry->brewMead1 ?? ''],
            ['Sweetness', $entry->brewMead2 ?? ''],
            ['Strength', $entry->brewMead3 ?? ''],
            ['Possible Allergens', $entry->brewPossAllergens ?? ''],
            ['ABV', $entry->brewABV ?? ''],
        ] as [$label, $value]) {
            if ((string) $value !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        // brewSweetnessLevel JSON {OG, FG} (non-NWCiderCup branch).
        $sweetness = json_decode((string) ($entry->brewSweetnessLevel ?? ''), true);
        if (is_array($sweetness)) {
            foreach ([['OG', 'Original Gravity'], ['FG', 'Final Gravity']] as [$key, $label]) {
                if (! empty($sweetness[$key])) {
                    $rows[] = ['label' => $label, 'value' => (string) $sweetness[$key]];
                }
            }
        }

        if ((string) ($entry->brewStaffNotes ?? '') !== '') {
            $rows[] = ['label' => 'Notes', 'value' => (string) $entry->brewStaffNotes];
        }

        return $rows;
    }
}
