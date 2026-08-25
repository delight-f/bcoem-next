<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "All Entries: All Data" CSV export (spec §7 P5.3) — the one artifact class
 * spec §8.3 byte-compares at graduation.
 *
 * Legacy: output/export.output.php ($section=export-entries, $go=csv,
 * $action=all, $tb=all) + includes/db/output_entries_export*.db.php.
 *
 * Byte-parity contract mirrored from the oracle:
 *  - UTF-8 BOM, then header row, then one row per brewing row;
 *  - rows are written with PHP's native fputcsv() defaults (LF endings,
 *    '"' enclosure, '\' escape — quoting fires on delimiter/enclosure/
 *    escape/CR/LF/TAB and, since PHP 8.x, on spaces). The polyfill in
 *    export.output.php is dead code behind function_exists();
 *  - column order = SELECT * order of the brewing table, so the port reads
 *    Schema::getColumnListing() instead of hardcoding names;
 *  - header naming replicates ltrim($name, "brew") — a CHARLIST strip that
 *    eats any leading b/r/e/w characters (brewBrewerID → "ID", not
 *    "BrewerID") — then the label chain from lang/en/en-US.lang.php;
 *  - unjudged entries (no flight row or no score row) emit NO trailing
 *    Table/Flight/Round/Score/Place/BOS Place/Style Type/Location fields,
 *    so rows have variable length exactly like legacy;
 *  - score is sprintf("%02s", …): space-padded to width 2 ("5" → " 5").
 *
 * XLSX flavor: verified NOT to exist — every "Excel" link in the legacy UI
 * downloads CSV (fa-file-excel icons pointing at go=csv URLs); there is no
 * HTML-table-with-xls-mime trick anywhere in export.output.php.
 *
 * Divergences from legacy (documented per ticket):
 *  - admin gate only. Legacy also serves this branch publicly while
 *    judging hasn't started and both windows are open
 *    ($judging_past==0 && $registration_open==2 && $entry_window_open==2);
 *    the pre-wired route sits behind auth, so that anonymous leg is dropped.
 *  - data_integrity_check() is not run before export.
 *  - archive flavors ($filter=<suffix> against *_<suffix> tables), the
 *    tab/winners/circuit/mhp/email/required/paid/nopay variants and the
 *    other export sections are not ported under this ticket; only the
 *    byte-compared csv/all/all artifact answers here.
 */
final class ExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        // Download filename mirrors export.output.php:246-251. The trailing
        // date segment is today in the tenant's timezone (legacy's
        // $date_downloaded; the ?sort override has no clean-URL equivalent).
        $contest = str_replace(' ', '_', (string) $ctx->contestStr('contestName'));
        $tbQuery = $request->query('tb', 'default');
        $filterFilename = is_string($tbQuery) && $tbQuery !== 'default'
            ? $tbQuery
            : 'default'; // filter param stays "default" on this route
        $dateDownloaded = DateFmt::date(
            time(),
            $ctx->prefsStr('prefsTimeZone'),
            $ctx->prefsStr('prefsDateFormat'),
            'system',
        ) ?? '';
        $actionQuery = $request->query('action', 'all');
        $viewQuery = $request->query('view', 'default');
        $filename = ltrim(
            self::filenameSegment($contest)
            .'_Entries'
            .self::filenameSegment($filterFilename)
            .self::filenameSegment(is_string($actionQuery) ? $actionQuery : 'all')
            .self::filenameSegment(is_string($viewQuery) ? $viewQuery : 'default')
            .self::filenameSegment($dateDownloaded)
            .'.csv',
            '_',
        );

        $proEdition = (int) ($ctx->prefsStr('prefsProEdition') ?? 0);
        $styleSet = (string) ($ctx->prefsStr('prefsStyleSet') ?? '');
        $styleTypeNames = DB::table('style_types')->pluck('styleTypeName', 'id');
        $columns = array_values(Schema::getColumnListing('brewing'));

        return response()->stream(function () use ($columns, $proEdition, $styleSet, $styleTypeNames): void {
            $fp = fopen('php://output', 'w');
            if ($fp === false) {
                return; // output stream unavailable — nothing can be written
            }

            // Legacy gates the ENTIRE table (header included) behind
            // `if ($fp && $rows_sql)` — an empty brewing table yields a
            // BOM-only body (export.output.php:318-461).
            fwrite($fp, "\xEF\xBB\xBF"); // legacy prints the BOM before fputcsv

            $rows = DB::table('brewing')->get();

            if ($rows->isNotEmpty()) {
                fputcsv($fp, self::headers($columns, $proEdition), ',', '"', '\\');
            }

            foreach ($rows as $row) {
                /** @var array<string, mixed> $entry */
                $entry = (array) $row;

                $fields = self::brewerFields($entry, $proEdition);

                foreach ($entry as $key => $value) {
                    if ($key === 'brewPouring') {
                        $pouring = self::jsonDecoded($value);
                        $fields[] = isset($pouring['pouring']) ? self::csvText($pouring['pouring']) : '';
                        $fields[] = isset($pouring['pouring_rouse']) ? self::csvText($pouring['pouring_rouse']) : '';
                    } elseif ($key === 'brewJuiceSource') {
                        $juice = self::jsonDecoded($value);
                        $fields[] = isset($juice['juice_src']) ? self::csvText(implode(', ', (array) $juice['juice_src'])) : '';
                        $fields[] = isset($juice['juice_src_other']) ? self::csvText(implode(', ', (array) $juice['juice_src_other'])) : '';
                    } elseif ($key === 'brewStyleType') {
                        // prefsStyleSet == NWCiderCup pins the literal "Cider"
                        // regardless of the style_types row (export.output.php:434).
                        $fields[] = $styleSet === 'NWCiderCup'
                            ? 'Cider'
                            : self::csvText((string) ($styleTypeNames[$value] ?? ''));
                    } else {
                        $fields[] = self::csvText($value);
                    }
                }

                // Trailing judging fields only when BOTH flight and score rows
                // exist (export.output.php:444) — otherwise the row simply ends.
                $judging = self::judgingFields($entry);
                if ($judging !== null) {
                    array_push($fields, ...$judging);
                }

                fputcsv($fp, $fields, ',', '"', '\\');
            }

            fclose($fp);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Header row: participant labels, then one per brewing column (with the
     * JuiceSource/Pouring columns each expanding to two), then the eight
     * judging labels (export.output.php:264-311).
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function headers(array $columns, int $proEdition): array
    {
        // Labels verbatim from lang/en/en-US.lang.php (the only locale the
        // parity corpus runs).
        $headers = ['First Name', 'Last Name'];
        if ($proEdition === 1) {
            array_push($headers, 'Organization', 'TTB Number', 'Yearly Volume');
        }
        array_push(
            $headers,
            'Email Address', 'Address', 'City', 'State/Province', 'Zip/Postal Code', 'Country',
        );
        if ($proEdition !== 1) {
            $headers[] = 'Club';
        }

        foreach ($columns as $column) {
            // Legacy quirk kept verbatim: ltrim strips any leading b/r/e/w
            // CHARACTER, not the word "brew" (brewBrewerID → "ID").
            $name = ltrim($column, 'brew');

            $headers[] = match ($name) {
                'id' => 'Entry Number',
                'Name' => 'Entry Name',
                'Info' => 'Required Info',
                'InfoOptional' => 'Optional Info',
                'Mead1' => 'Carbonation',
                'Mead2' => 'Sweetness',
                'Mead3' => 'Strength',
                'Comments' => "Brewer's Specifics",
                'Updated' => 'Updated',
                'SweetnessLevel' => 'Final Gravity',
                'JuiceSource' => 'Juice Source|Juice Source Other',
                'Pouring' => 'Pouring Inst|Rouse Yeast',
                default => (string) preg_replace(
                    ['/(?<=[^A-Z])([A-Z])/', '/(?<=[^0-9])([0-9])/'],
                    ' $0',
                    $name,
                ),
            };
        }

        // JuiceSource/Pouring each expand to two columns.
        $expanded = [];
        foreach ($headers as $header) {
            foreach (explode('|', $header) as $part) {
                $expanded[] = $part;
            }
        }

        return [...$expanded, ...[
            'Table', 'Flight', 'Round', 'Score', 'Place', 'Best of Show Place', 'Style Type', 'Location',
        ]];
    }

    /**
     * Leading participant fields from the brewer table lookup
     * (common.lib.php brewer_info(): uid first, id fallback; missing row →
     * all-empty fields, mirroring legacy's suppressed-null concatenation).
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function brewerFields(array $entry, int $proEdition): array
    {
        $uid = (string) ($entry['brewBrewerID'] ?? '');

        $brewer = $uid === '' ? null : (
            DB::table('brewer')->where('uid', $uid)->first()
            ?? DB::table('brewer')->where('id', $uid)->first()
        );

        // The "^"-joined field string from brewer_info(), indices preserved:
        // 6 email, 8 clubs, 10 address, 11 city, 12 state, 13 zip, 14 country,
        // 15 brewery, 17 TTB, 18 yearly volume. "&nbsp;" sentinels kept where
        // legacy emits them (only index 8 leaks into the non-pro output).
        $info = [];
        $info[0] = (string) ($brewer->brewerFirstName ?? '');
        $info[1] = (string) ($brewer->brewerLastName ?? '');
        $info[2] = (string) ($brewer->brewerPhone1 ?? '');
        if (isset($brewer->brewerJudgeRank)) {
            $info[3] = (($brewer->brewerJudgeMead ?? '') === 'Y' && $brewer->brewerJudgeRank === 'Non-BJCP')
                ? 'Non-BJCP Beer'
                : (string) $brewer->brewerJudgeRank;
        } else {
            $info[3] = 'Non-BJCP';
        }
        $info[4] = isset($brewer->brewerJudgeID) ? (string) $brewer->brewerJudgeID : '&nbsp;';
        $info[5] = (string) ($brewer->brewerMHP ?? '');
        $info[6] = (string) ($brewer->brewerEmail ?? '');
        $info[7] = (string) ($brewer->uid ?? '');
        $info[8] = isset($brewer->brewerClubs) ? (string) $brewer->brewerClubs : '&nbsp;';
        $info[9] = isset($brewer->brewerDiscount) ? (string) $brewer->brewerDiscount : '&nbsp;';
        $info[10] = (string) ($brewer->brewerAddress ?? '');
        $info[11] = (string) ($brewer->brewerCity ?? '');
        $info[12] = (string) ($brewer->brewerState ?? '');
        $info[13] = (string) ($brewer->brewerZip ?? '');
        $info[14] = (string) ($brewer->brewerCountry ?? '');
        $info[15] = $proEdition === 1 ? (string) ($brewer->brewerBreweryName ?? '') : '&nbsp;';
        $info[16] = ($brewer !== null && ($brewer->brewerJudgeMead ?? '') === 'Y') ? 'Certified Mead Judge' : '&nbsp;';

        $ttb = [];
        if ($proEdition === 1 && isset($brewer->brewerBreweryInfo) && (string) $brewer->brewerBreweryInfo !== '') {
            $ttb = (array) json_decode((string) $brewer->brewerBreweryInfo, true);
        }
        $info[17] = ($proEdition === 1 && ($ttb['TTB'] ?? '') !== '') ? (string) $ttb['TTB'] : '&nbsp;';
        $info[18] = ($proEdition === 1 && ($ttb['Production'] ?? '') !== '') ? (string) $ttb['Production'] : '&nbsp;';

        $firstName = self::csvText($entry['brewBrewerFirstName'] ?? '');
        $lastName = self::csvText($entry['brewBrewerLastName'] ?? '');

        if ($proEdition === 1) {
            return [
                $firstName,
                $lastName,
                self::csvText($info[15]),
                self::csvText($info[17]),
                self::csvText($info[18]),
                self::csvText($info[6]),
                self::csvText($info[10]),
                self::csvText($info[11]),
                self::csvText($info[12]),
                self::csvText($info[13]),
                self::csvText($info[14]),
            ];
        }

        // Club uses "&nbsp;" as the sentinel for "absent" (export.output.php:361).
        $club = '';
        if ($info[8] !== '&nbsp;') {
            $club = self::csvText($info[8]);
        }

        return [
            $firstName,
            $lastName,
            self::csvText($info[6]),
            self::csvText($info[10]),
            self::csvText($info[11]),
            self::csvText($info[12]),
            self::csvText($info[13]),
            self::csvText($info[14]),
            $club,
        ];
    }

    /**
     * Trailing judging fields, or null when the entry carries no flight or no
     * score row (export.output.php:444 — the whole block collapses to []).
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>|null
     */
    private static function judgingFields(array $entry): ?array
    {
        $id = (string) ($entry['id'] ?? '');

        $score = DB::table('judging_scores')->where('eid', $id)->first();
        $flight = DB::table('judging_flights')->where('flightEntryID', $id)->first();

        if (! $flight || ! $score) {
            return null;
        }

        $bosPlace = '';
        $bos = DB::table('judging_scores_bos')->where('eid', $id)->first();
        if ($bos !== null) {
            $bosPlace = (string) $bos->scorePlace;
        }

        // scoreType → type name via style_type($type, 2, "bcoe") (common.lib.php:2172).
        $styleTypeEntry = match ((string) $score->scoreType) {
            '3' => 'Mead',
            '2' => 'Cider',
            '1', 'Lager', 'Ale', 'Mixed' => 'Beer',
            default => (string) $score->scoreType,
        };

        [$tableName, $locationName] = self::tableInfo((int) $flight->flightTable);

        return [
            self::csvText($tableName),
            self::csvText($flight->flightNumber),
            self::csvText($flight->flightRound),
            sprintf('%02s', self::csvText($score->scoreEntry)), // space-padding quirk
            self::csvText($score->scorePlace),
            self::csvText($bosPlace),
            self::csvText($styleTypeEntry),
            self::csvText($locationName),
        ];
    }

    /**
     * get_table_info(1,"basic",…)+(…,"location") reduced to what the export
     * emits: "%02s: tableName" and the location's judgingLocName.
     *
     * @return array{0: string, 1: string}
     */
    private static function tableInfo(int $tableId): array
    {
        $table = DB::table('judging_tables')->where('id', $tableId)->first();

        if ($table === null) {
            return ['00: Not Assigned to a Table', ''];
        }

        $location = DB::table('judging_locations')->where('id', $table->tableLocation)->first();

        return [
            sprintf('%02s', (string) $table->tableNumber).': '.html_entity_decode((string) $table->tableName),
            (string) ($location->judgingLocName ?? ''),
        ];
    }

    /** convert_to_entities() verbatim (includes/output.inc.php:21). */
    private static function csvText(mixed $input): string
    {
        if ($input === null) {
            return '';
        }

        $output = (string) preg_replace_callback(
            '/(&#[0-9]+;)/',
            fn (array $m): string => mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES'),
            (string) $input,
        );

        return html_entity_decode($output);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonDecoded(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** filename() from lib/output.lib.php:127. */
    private static function filenameSegment(string $input): string
    {
        if ($input === 'default') {
            return '';
        }

        return '_'.str_replace(' ', '_', ucwords(str_replace('_', ' ', $input)));
    }
}
