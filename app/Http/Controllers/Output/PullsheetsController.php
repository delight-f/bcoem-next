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
use Illuminate\Support\Facades\DB;

/**
 * Per-table pull sheets (spec §7 P5.1). Pipeline validation case for all
 * Slice D outputs — pattern: single-action controller, admin gate, Blade
 * view → StreamPdf (see ledger/outputs.md).
 *
 * Legacy: output/pullsheets.output.php (+ output_pullsheets*.db.php),
 * default "all tables" mode ($id == "default"). Quirks mirrored are
 * catalogued in ledger/outputs.md; divergences are marked inline.
 */
final class PullsheetsController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return StreamPdf::response('outputs.pullsheets', self::build(TenantContext::load()), 'pullsheets.pdf');
    }

    /**
     * View payload builder, public so the feature test can assert the exact
     * rows that feed the PDF (the PDF text itself is compressed binary).
     *
     * @return array{queued: bool, tables: list<array<string, mixed>>}
     */
    public static function build(TenantContext $ctx): array
    {
        $queued = $ctx->judgingStr('jPrefsQueued') === 'Y';

        // Legacy $id=default: every table ordered by tableNumber ASC
        // (includes/db/output_pullsheets.db.php).
        $tables = [];
        foreach (DB::table('judging_tables')->orderBy('tableNumber')->get() as $table) {
            $tables[] = self::buildTable($ctx, (array) $table, $queued);
        }

        return ['queued' => $queued, 'tables' => $tables];
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private static function buildTable(TenantContext $ctx, array $table, bool $queued): array
    {
        $entries = self::tableEntries($ctx, $table);

        $flightGroups = [];

        if (! $queued && count($entries) > 0) {
            // Flights mode: an entry without a judging_flights row is NOT
            // pulled at all (legacy check_flight_number() returns "" and
            // check_flight_round("", "default") is false).
            $byEntryId = [];
            foreach (DB::table('judging_flights')->where('flightTable', $table['id'])->get() as $f) {
                $byEntryId[(int) $f->flightEntryID] = $f;
            }

            foreach ($entries as $entry) {
                $f = $byEntryId[(int) $entry['id']] ?? null;
                if ($f === null) {
                    continue;
                }
                $flightGroups[(int) $f->flightNumber]['round'] = (string) $f->flightRound;
                $flightGroups[(int) $f->flightNumber]['rows'][] = ['order' => $f->flightEntryOrder] + $entry;
            }
            ksort($flightGroups);

            foreach ($flightGroups as $n => $group) {
                $rows = $group['rows'];
                // Pin #4/#5 semantics, verbatim legacy usort: manual
                // flightEntryOrder ASC with NULLs LAST, tie-break on the
                // zero-padded judging number, natural comparison.
                usort($rows, function (array $a, array $b): int {
                    if ($a['order'] !== $b['order']) {
                        return [$a['order'] === null ? 1 : 0, (int) $a['order']]
                            <=> [$b['order'] === null ? 1 : 0, (int) $b['order']];
                    }

                    return strnatcmp((string) $a['judgingNo'], (string) $b['judgingNo']);
                });
                $flightGroups[$n]['rows'] = $rows;
            }
        } else {
            // Queued mode: one flat list per table, no flight grouping.
            $flightGroups[0] = ['round' => null, 'rows' => $entries];
        }

        $flights = [];
        foreach ($flightGroups as $n => $group) {
            $flights[] = [
                'label' => $n === 0 ? null : sprintf('Flight %d, Round %s', $n, $group['round']),
                'rows' => array_map(fn (array $r): array => array_diff_key($r, ['order' => null]), $group['rows']),
            ];
        }

        return [
            'id' => (int) $table['id'],
            'number' => (int) $table['tableNumber'],
            'name' => (string) $table['tableName'],
            'locationLine' => self::locationLine($ctx, $table),
            'entryCount' => count($entries),
            // number_of_flights(): MAX(flightNumber) for the table.
            'flightCount' => $queued ? null : (int) (DB::table('judging_flights')->where('flightTable', $table['id'])->max('flightNumber') ?? 0),
            'flights' => $flights,
        ];
    }

    /**
     * Received entries matching a table's tableStyles CSV of style ids,
     * display-shaped rows in judging-number order. Legacy runs one query
     * per style id (includes/db/output_pullsheets_entries.db.php); one
     * OR-grouped query returns the same rows. Dangling style ids match
     * nothing. Rows lacking brewCategorySort were skipped by legacy's
     * `if (!empty($row_entries['brewCategorySort']))` guard — kept via the
     * pair join, which cannot match an empty sort.
     *
     * @param  array<string, mixed>  $table
     * @return list<array<string, mixed>>
     */
    private static function tableEntries(TenantContext $ctx, array $table): array
    {
        $styleIds = array_filter(array_map('trim', explode(',', (string) $table['tableStyles']))) ?: [0];

        $stylesByPair = [];
        foreach (DB::table('styles')->whereIn('id', $styleIds)->get() as $style) {
            $stylesByPair[(string) $style->brewStyleGroup.'|'.(string) $style->brewStyleNum] = $style;
        }

        $query = DB::table('brewing')->where('brewReceived', '1')->where(function ($q) use ($stylesByPair): void {
            foreach ($stylesByPair as $style) {
                $q->orWhere(function ($qq) use ($style): void {
                    $qq->where('brewCategorySort', $style->brewStyleGroup)
                        ->where('brewSubCategory', $style->brewStyleNum);
                });
            }
        });

        $entries = [];
        foreach ($query->get() as $e) {
            $e = (object) $e;
            $style = $stylesByPair[(string) $e->brewCategorySort.'|'.(string) $e->brewSubCategory] ?? null;

            $entries[] = [
                'id' => (int) $e->id,
                'entryNo' => sprintf('%06d', (int) $e->id),
                'judgingNo' => sprintf('%06s', (string) $e->brewJudgingNumber),
                'style' => trim(ltrim((string) $e->brewCategorySort, '0').' '.(string) $e->brewSubCategory.' '.(string) $e->brewStyle),
                'brewer' => trim(((string) $e->brewBrewerFirstName).' '.(string) $e->brewBrewerLastName),
                'info' => self::infoRows($e, $style, (string) $ctx->prefsStr('prefsStyleSet')),
            ];
        }

        // Final ordering key mirrors the legacy datatables sort / manual-order
        // tie-break: natural comparison of the zero-padded judging number.
        usort($entries, fn (array $a, array $b): int => strnatcmp((string) $a['judgingNo'], (string) $b['judgingNo']));

        return $entries;
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
