<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Table cards / sorting placards (spec §7 P5.2). Legacy:
 * output/table_cards.output.php with three psort modes.
 *
 * Quirks mirrored:
 *  - No tables defined → legacy prints a "no table information" HTML
 *    notice (:17-23); the port renders the same message as a one-page PDF
 *    so the response type stays application/pdf (divergence in medium only).
 *  - psort=sorting-placards: one placard per category that has confirmed
 *    entries — count line uses singular "Entry"/plural "Entries" and the
 *    checklist is %06d entry numbers + sort+sub code, one page each
 *    (:51-73; entries_by_style.db.php confirmed-only list ordered
 *    brewCategorySort,brewSubCategory,id). Category name comes from the
 *    styles table's brewStyleCategory for the group — legacy reads it from
 *    the hardcoded $style_sets map in includes/styles.inc.php; same names
 *    for shipped sets (divergence noted).
 *  - Numeric category numbers are zero-padded to two digits; alpha ones
 *    pass through (:53-54).
 *  - psort=sorting-tables + view=master-list: bordered table of
 *    Table / Styles / Entry Count / Session with legacy's legend copy about
 *    pre-check-in counts and changeable sessions (:90-92). Entry count sums
 *    per-style counts over the table's tableStyles CSV, each matching
 *    brewCategorySort+brewSubCategory and adding brewReceived='1' only when
 *    jPrefsTablePlanning != 1 (common.lib.php get_table_info count_total).
 *    Style codes join ",&nbsp;" then rtrimmed (:117-119).
 *  - psort=default tent cards: Table N / name / location line plus the
 *    assignment roster; Steward vs Judge label from the assignment column,
 *    Round/Flight prefixed labels, flight column only when
 *    jPrefsQueued == 'N' (:186-204; output_table_cards.db.php filters
 *    assignTable (+assignRound when a round is given), orders
 *    assignRound,assignFlight).
 *  - Judge rank "Novice" displays as "Non-BJCP" (:192); role codes HJ/LJ/
 *    MBOS display as Head Judge/Lead Judge/Mini-BOS Judge (:149-150).
 */
final class TableCardsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();
        $psortQuery = $request->query('psort', 'default');
        $psort = is_string($psortQuery) ? $psortQuery : 'default';

        $roundQuery = $request->query('round', 'default');
        // admin_common.db.php — all tables by tableNumber ASC; id selects one.
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get()
            ->filter(fn ($t) => $request->query('id', 'default') === 'default'
                || (int) $request->query('id') === (int) $t->id);

        if ($tables->isEmpty()) {
            return StreamPdf::response('outputs.table_cards', ['empty' => true], 'table_cards.pdf');
        }

        $data = match ($psort) {
            'sorting-placards' => ['mode' => 'placards', 'placards' => self::placards()],
            'sorting-tables' => [
                'mode' => 'tables',
                'masterList' => $request->query('view') === 'master-list',
                'rows' => self::tableRows($ctx, $tables),
            ],
            default => [
                'mode' => 'cards',
                // Legacy renders the Flight column only when
                // jPrefsQueued == 'N' (:174/:200).
                'showFlights' => $ctx->judgingStr('jPrefsQueued') === 'N',
                'rows' => self::cardRows($ctx, $tables, is_string($roundQuery) ? $roundQuery : 'default'),
            ],
        };

        $filename = str_replace(' ', '_', (string) $ctx->contestStr('contestName')).'_Table_Cards.pdf';

        return StreamPdf::response('outputs.table_cards', $data, $filename);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function placards(): array
    {
        // Confirmed entries grouped by category (entries_by_style.db.php:
        // brewConfirmed='1', ordered brewCategorySort,brewSubCategory,id).
        $entries = DB::table('brewing')->where('brewConfirmed', '1')
            ->orderBy('brewCategorySort')->orderBy('brewSubCategory')->orderBy('id')->get()
            ->groupBy('brewCategorySort');

        $placards = [];
        foreach ($entries as $cat => $group) {
            $catNumber = is_numeric($cat) ? sprintf('%02d', (int) $cat) : (string) $cat;
            $name = DB::table('styles')->where('brewStyleGroup', $cat)->value('brewStyleCategory') ?? '';

            $placards[] = [
                'number' => $catNumber,
                'name' => (string) $name,
                'count' => $group->count().' '.($group->count() === 1 ? 'Entry' : 'Entries'),
                'items' => $group->map(
                    fn ($e) => "\u{2610} Entry # ".sprintf('%06d', (int) $e->id).' – '.$e->brewCategorySort.$e->brewSubCategory,
                )->all(),
            ];
        }

        return $placards;
    }

    /**
     * sorting-tables rows (both master-list and card variants).
     *
     * @param  iterable<\stdClass>  $tables
     * @return list<array<string, mixed>>
     */
    public static function tableRows(TenantContext $ctx, iterable $tables): array
    {
        $planning = $ctx->judgingStr('jPrefsTablePlanning') === '1';

        $rows = [];
        foreach ($tables as $table) {
            $codes = [];
            $received = 0;

            // The styles table carries the same category code under multiple
            // set versions; count each distinct group+num pair once.
            $counted = [];

            foreach (self::tableStyleIds((string) $table->tableStyles) as $styleId) {
                $style = DB::table('styles')->where('id', $styleId)->first();
                if ($style === null) {
                    continue;
                }

                $codes[] = $style->brewStyleGroup.$style->brewStyleNum;
                $pair = $style->brewStyleGroup.'|'.$style->brewStyleNum;
                if (in_array($pair, $counted, true)) {
                    continue;
                }
                $counted[] = $pair;

                $q = DB::table('brewing')
                    ->where('brewCategorySort', $style->brewStyleGroup)
                    ->where('brewSubCategory', $style->brewStyleNum);
                if (! $planning) {
                    $q->where('brewReceived', '1');
                }
                $received += (int) $q->count();
            }

            $rows[] = [
                'number' => $table->tableNumber,
                'name' => $table->tableName,
                'styles' => rtrim(implode(', ', $codes), ', '),
                'received' => $received,
                'location' => self::locationLine($ctx, (int) $table->id),
            ];
        }

        return $rows;
    }

    /**
     * Tent-card rows with assignment rosters.
     *
     * @param  iterable<\stdClass>  $tables
     * @return list<array<string, mixed>>
     */
    private static function cardRows(TenantContext $ctx, iterable $tables, string $round): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $q = DB::table('judging_assignments')->where('assignTable', $table->id);
            if ($round !== 'default') {
                $q->where('assignRound', $round);
            }
            $assignments = $q->orderBy('assignRound')->orderBy('assignFlight')->get();

            $people = [];
            foreach ($assignments as $a) {
                $brewer = DB::table('brewer')->where('uid', $a->bid)->first();

                // Role codes → display names (legacy :149-150); CSV roles
                // are comma-joined in storage.
                $role = trim(str_replace(
                    ['HJ', 'LJ', 'MBOS'],
                    ['Head Judge', 'Lead Judge', 'Mini-BOS Judge'],
                    (string) $a->assignRoles,
                ));

                $rank = str_replace(',', ', ', (string) ($brewer->brewerJudgeRank ?? ''));
                $rank = str_replace('Novice', 'Non-BJCP', $rank);

                $people[] = [
                    'name' => ($brewer->brewerLastName ?? '').', '.($brewer->brewerFirstName ?? ''),
                    'rank' => $rank,
                    'role' => $role,
                    'assignment' => $a->assignment === 'S' ? 'Steward' : 'Judge',
                    'round' => $a->assignRound !== null && $a->assignRound !== '' ? 'Round '.$a->assignRound : '',
                    'flight' => $a->assignFlight !== null && $a->assignFlight !== '' ? 'Flight '.$a->assignFlight : '',
                ];
            }

            $rows[] = [
                'number' => $table->tableNumber,
                'name' => $table->tableName,
                'location' => self::locationLine($ctx, (int) $table->id),
                'people' => $people,
            ];
        }

        return $rows;
    }

    /** @return list<int> */
    private static function tableStyleIds(string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    /**
     * table_location(): location name + long-form local date of the
     * location's judgingDate (common.lib.php:2210-2237). Empty when no
     * location row exists.
     */
    private static function locationLine(TenantContext $ctx, int $tableId): ?string
    {
        $locId = DB::table('judging_tables')->where('id', $tableId)->value('tableLocation');
        if ($locId === null) {
            return null;
        }

        $location = DB::table('judging_locations')->where('id', $locId)->first();
        if ($location === null || $location->judgingLocName === null) {
            return null;
        }

        $date = DateFmt::date($location->judgingDate, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), 'long');

        return $date !== null ? $location->judgingLocName.', '.$date : $location->judgingLocName;
    }
}
