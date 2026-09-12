<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Upstream 3.1.0 double/triple HTML-entity-encoding repair
 * (run_update.php:4766-4777 fully_decode(), 4880-4956 the 3.1.0 table set
 * including the archived-competition sibling tables).
 *
 * The DB is shared with sibling suites, so every row seeded here is deleted in
 * a finally, and every assertion targets only a seeded row id (never a global
 * count) so a sibling's leftovers can neither pass nor fail this file.
 */
final class CleanupDoubleEncodedDataTest extends PublicSurfaceTestCase
{
    /** Marker entry name; the encoded value lives in brewComments. */
    private const DIRTY = 'CDE-dirty-entry';

    private const ARCHIVE_SUFFIX = 'cdearch';

    private const COMMAND = 'bcoem:cleanup-double-encoded-data';

    public function test_dry_run_reports_double_encoded_value_and_writes_nothing(): void
    {
        $id = $this->seedEntry('D &amp;amp; D');

        try {
            $exit = Artisan::call(self::COMMAND);
            $output = Artisan::output();

            self::assertSame(0, $exit);
            self::assertStringContainsString('nothing will be written', $output);
            self::assertStringContainsString((string) $id, $output);
            self::assertStringContainsString('brewComments: D &amp;amp; D => D & D', $output);

            // Dry run wrote nothing.
            self::assertSame('D &amp;amp; D', $this->brewComments($id));
        } finally {
            $this->remove($id);
        }
    }

    public function test_apply_normalizes_the_value_and_a_second_apply_is_a_no_op(): void
    {
        $id = $this->seedEntry('D &amp;amp; D');

        try {
            Artisan::call(self::COMMAND, ['--apply' => true]);
            self::assertSame('D & D', $this->brewComments($id));

            $dryAfter = Artisan::call(self::COMMAND);
            self::assertSame(0, $dryAfter);
            self::assertStringNotContainsString((string) $id, Artisan::output());

            Artisan::call(self::COMMAND, ['--apply' => true]);
            self::assertStringNotContainsString((string) $id, Artisan::output());

            // Idempotent: the second apply left the corrected value alone.
            self::assertSame('D & D', $this->brewComments($id));
        } finally {
            $this->remove($id);
        }
    }

    public function test_clean_values_are_left_untouched(): void
    {
        // Bare "&" is not an entity: a clean, plain-storage value stays as-is.
        $id = $this->seedEntry('C & C');

        try {
            Artisan::call(self::COMMAND, ['--apply' => true]);

            self::assertStringNotContainsString((string) $id, Artisan::output());
            self::assertSame('C & C', $this->brewComments($id));
        } finally {
            $this->remove($id);
        }
    }

    public function test_archived_competition_tables_are_covered(): void
    {
        $table = 'brewing_'.self::ARCHIVE_SUFFIX;
        $this->createArchiveTable($table);
        $id = DB::table($table)->insertGetId(['brewComments' => 'A &amp;amp; A']);

        try {
            Artisan::call(self::COMMAND, ['--apply' => true]);

            self::assertStringContainsString($table, Artisan::output());
            self::assertSame('A & A', DB::table($table)->where('id', $id)->value('brewComments'));
        } finally {
            DB::statement('DROP TABLE IF EXISTS `'.$this->prefixed($table).'`');
        }
    }

    /** Entry name is only a marker; the encoded value goes in brewComments. */
    private function seedEntry(string $encoded): int
    {
        return (int) DB::table('brewing')->insertGetId([
            'brewName' => self::DIRTY,
            'brewComments' => $encoded,
        ]);
    }

    private function brewComments(int $id): mixed
    {
        return DB::table('brewing')->where('id', $id)->value('brewComments');
    }

    private function remove(int $id): void
    {
        DB::table('brewing')->where('id', $id)->delete();
    }

    private function createArchiveTable(string $table): void
    {
        DB::statement('DROP TABLE IF EXISTS `'.$this->prefixed($table).'`');
        DB::statement('CREATE TABLE `'.$this->prefixed($table).'` LIKE `'.$this->prefixed('brewing').'`');
    }

    private function prefixed(string $table): string
    {
        return (string) DB::connection()->getTablePrefix().$table;
    }
}
