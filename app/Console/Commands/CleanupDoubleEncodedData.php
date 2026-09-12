<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upstream 3.1.0 `run_update.php` port: unwinds data left double/triple
 * HTML-entity-encoded by the historical purify()-then-sterilize() pipeline
 * (a plain-text field run through both, in that order, gained an extra
 * encoding layer per pass, so "&" displayed literally as "&amp;").
 *
 * Detection and normalization mirror upstream:
 *   - fully_decode()   run_update.php:4766-4777 (3.0.4 block)
 *   - 3.0.4 table set  run_update.php:4801-4825
 *   - 3.1.0 table set  run_update.php:4898-4956 (incl. the *_<suffix>
 *                      archived-competition tables, 4918-4956)
 *   - fingerprints     lib/common.lib.php:66-85 (same table/column set)
 *
 * PORT DIVERGENCE: upstream's normalizer re-encodes the fully-decoded value
 * through HTMLPurifier (run_update.php:4798) because legacy stored these
 * columns HTML-escaped and echoed them raw. This port has no HTMLPurifier and
 * stores plain text (Blade escapes at render, see
 * SitePreferencesParityTest "Allow BCOE&amp;M ..."), so the re-encode step is
 * the identity function here and the repair is the full decode itself.
 * Upstream's plain normalizer, trim(strip_tags()) (run_update.php:4799), is
 * kept verbatim for the columns upstream applies it to.
 *
 * Idempotent: fully_decode() iterates to a fixed point and both normalizers
 * are stable on their own output, so a second --apply changes nothing.
 * Default is a dry run; pass --apply to write.
 */
final class CleanupDoubleEncodedData extends Command
{
    protected $signature = 'bcoem:cleanup-double-encoded-data
                            {--apply : Write the corrections (default: dry run)}';

    protected $description = 'Unwind double/triple HTML-entity encoding in plain-text columns (upstream 3.1.0 repair).';

    /**
     * Base table name => [affected plain-text columns, use upstream's plain
     * (trim+strip_tags) normalizer instead of the re-encode step]. Tables
     * ending in "_<suffix>" (archived competitions) are picked up from the
     * live schema, matching upstream's $archive_suffixes loop.
     */
    private const TARGETS = [
        'brewer' => [
            [
                'brewerJudgeID', 'brewerBreweryName', 'brewerJudgeNotes', 'brewerFirstName',
                'brewerLastName', 'brewerAddress', 'brewerCity', 'brewerState',
            ],
            false,
        ],
        'brewing' => [
            [
                'brewName', 'brewComments', 'brewCoBrewer', 'brewPossAllergens', 'brewAdminNotes',
                'brewStaffNotes', 'brewBoxNum', 'brewPouring', 'brewInfo', 'brewInfoOptional',
            ],
            false,
        ],
        'judging_tables' => [['tableName'], true],
        'style_types' => [['styleTypeName'], true],
        'preferences' => [['prefsBestBrewerTitle', 'prefsBestClubTitle'], false],
        'special_best_info' => [['sbi_name', 'sbi_description'], true],
        'special_best_data' => [['sbd_comments'], true],
        'mods' => [['mod_name', 'mod_description'], true],
        'sponsors' => [['sponsorName', 'sponsorText'], false],
        'evaluation' => [
            [
                'evalSpecialIngredients', 'evalOtherNotes', 'evalAromaComments', 'evalAppearanceComments',
                'evalFlavorComments', 'evalMouthfeelComments', 'evalOverallComments', 'evalBottleNotes',
            ],
            false,
        ],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? 'Applying double/triple HTML-entity-encoding corrections.'
            : 'Dry run — nothing will be written. Re-run with --apply to write.');

        $logical = $this->logicalTables();
        $tableCount = 0;
        $fieldCount = 0;

        foreach (self::TARGETS as $base => [$columns, $plain]) {
            foreach ($logical as $table) {
                if ($table !== $base && ! str_starts_with($table, $base.'_')) {
                    continue;
                }

                $present = array_values(array_filter(
                    $columns,
                    static fn (string $column): bool => Schema::hasColumn($table, $column),
                ));

                // brewerBreweryInfo is JSON; only its 'TTB' key was affected.
                $ttb = $base === 'brewer' && Schema::hasColumn($table, 'brewerBreweryInfo');

                if ($present === [] && ! $ttb) {
                    continue;
                }

                $changes = $this->changes($table, $present, $plain, $ttb);
                if ($changes === []) {
                    continue;
                }

                $tableCount++;
                $fieldCount += count($changes);

                $this->line(sprintf(
                    '%s: %d field(s) in %d row(s) %s',
                    $table,
                    count($changes),
                    count(array_unique(array_column($changes, 'id'))),
                    $apply ? 'corrected' : 'would change',
                ));

                foreach (array_slice($changes, 0, 2) as $change) {
                    $this->line(sprintf(
                        '  id=%d %s: %s => %s',
                        $change['id'],
                        $change['column'],
                        $change['before'],
                        $change['after'],
                    ));
                }

                if ($apply) {
                    foreach ($changes as $change) {
                        DB::table($table)->where('id', $change['id'])->update([$change['column'] => $change['after']]);
                    }
                }
            }
        }

        $this->line($tableCount === 0
            ? 'Nothing to do: no double/triple-encoded data found.'
            : sprintf('Total: %d field(s) in %d table(s) %s.', $fieldCount, $tableCount, $apply ? 'corrected' : 'would change'));

        return self::SUCCESS;
    }

    /**
     * Live table names with the connection's table prefix stripped.
     *
     * @return list<string>
     */
    private function logicalTables(): array
    {
        $prefix = (string) DB::connection()->getTablePrefix();
        $tables = [];

        $rows = DB::select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        foreach ($rows as $row) {
            $name = (string) $row->name;
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            $tables[] = substr($name, strlen($prefix));
        }

        return $tables;
    }

    /**
     * @param  list<string>  $columns
     * @return list<array{id: int, column: string, before: string, after: string}>
     */
    private function changes(string $table, array $columns, bool $plain, bool $ttb): array
    {
        $select = array_merge(['id'], $columns, $ttb ? ['brewerBreweryInfo'] : []);
        $changes = [];

        foreach (DB::table($table)->get($select) as $result) {
            $row = (array) $result;

            foreach ($columns as $column) {
                $before = $row[$column] ?? null;
                if (! is_scalar($before)) {
                    continue;
                }
                $before = (string) $before;
                if ($before === '') {
                    continue;
                }

                $after = $this->normalize($before, $plain);
                if ($after !== $before) {
                    $changes[] = ['id' => (int) $row['id'], 'column' => $column, 'before' => $before, 'after' => $after];
                }
            }

            if ($ttb && is_string($row['brewerBreweryInfo'] ?? null)) {
                $json = $row['brewerBreweryInfo'];
                $after = $this->normalizeTtb($json);
                if ($after !== null) {
                    $changes[] = ['id' => (int) $row['id'], 'column' => 'brewerBreweryInfo', 'before' => $json, 'after' => $after];
                }
            }
        }

        return $changes;
    }

    private function normalize(string $value, bool $plain): string
    {
        $decoded = $this->fullyDecode($value);

        return $plain ? trim(strip_tags($decoded)) : $decoded;
    }

    /**
     * "brewerBreweryInfo is a JSON column; only its 'TTB' key was affected."
     * run_update.php:4808-4821 and 4880-4896.
     */
    private function normalizeTtb(string $json): ?string
    {
        $info = json_decode($json, true);
        if (! is_array($info) || ! isset($info['TTB']) || ! is_string($info['TTB']) || $info['TTB'] === '') {
            return null;
        }

        $after = strtoupper($this->fullyDecode($info['TTB']));
        if ($after === $info['TTB']) {
            return null;
        }

        $info['TTB'] = $after;

        return json_encode($info) ?: null;
    }

    /** run_update.php:4766-4777, including the 6-pass cap. */
    private function fullyDecode(string $value): string
    {
        $previous = null;
        $current = $value;

        for ($i = 0; $current !== $previous && $i < 6; $i++) {
            $previous = $current;
            $current = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
        }

        return $current;
    }
}
