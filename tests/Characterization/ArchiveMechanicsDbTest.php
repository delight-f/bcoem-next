<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;

/**
 * Characterization: archive mechanics against real MySQL semantics (P1.10).
 *
 * Legacy process_archive.inc.php preserves competition history by
 *   RENAME TABLE <t> TO <t>_<suffix>;  CREATE TABLE <t> LIKE <t>_<suffix>;
 * i.e. history lives in sibling tables suffixed with the organizer's
 * archive label, and the live table is recreated EMPTY but structurally
 * identical (including AUTO_INCREMENT reset).
 *
 * This test exercises that exact two-step pattern on a THROWAWAY probe
 * table so CI proves the semantics without touching baseline fixtures or
 * any real competition data.
 */
final class ArchiveMechanicsDbTest extends MySqlTestCase
{
    private const SUFFIX = 'p1char';

    protected function tearDown(): void
    {
        if (! self::databaseAvailable()) {
            return; // skipped run: no connection to clean up
        }

        foreach (['bcoem_arch_probe', 'bcoem_arch_probe_'.self::SUFFIX] as $t) {
            self::db()->rawQuery("DROP TABLE IF EXISTS {$t}");
        }
    }
    public function test_rename_recreate_preserves_history_and_resets_live(): void
    {
        self::db()->rawQuery('CREATE TABLE bcoem_arch_probe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(32))');
        self::db()->rawQuery("INSERT INTO bcoem_arch_probe (label) VALUES ('history-row')");

        // The legacy two-step.
        self::db()->rawQuery('RENAME TABLE bcoem_arch_probe TO bcoem_arch_probe_'.self::SUFFIX);
        self::db()->rawQuery('CREATE TABLE bcoem_arch_probe LIKE bcoem_arch_probe_'.self::SUFFIX);

        $history = self::db()->rawQueryOne('SELECT COUNT(*) AS c FROM bcoem_arch_probe_'.self::SUFFIX);
        $live = self::db()->rawQueryOne('SELECT COUNT(*) AS c FROM bcoem_arch_probe');
        self::assertIsArray($history);
        self::assertIsArray($live);
        self::assertSame(1, (int) $history['c'], 'archive copy must retain every row');
        self::assertSame(0, (int) $live['c'], 'live table is recreated empty');

        // Fresh live table accepts inserts starting from id 1 again.
        self::db()->rawQuery("INSERT INTO bcoem_arch_probe (label) VALUES ('new-season')");
        $newRow = self::db()->rawQueryOne("SELECT id FROM bcoem_arch_probe WHERE label = 'new-season'");
        self::assertIsArray($newRow);
        self::assertSame(1, (int) $newRow['id'], 'AUTO_INCREMENT restarts in the recreated live table');

        // History and live are independent: purging live never touches the archive.
        self::db()->rawQuery('TRUNCATE bcoem_arch_probe');
        $stillThere = self::db()->rawQueryOne('SELECT COUNT(*) AS c FROM bcoem_arch_probe_'.self::SUFFIX);
        self::assertIsArray($stillThere);
        self::assertSame(1, (int) $stillThere['c']);
    }
}
