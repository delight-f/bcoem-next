<?php

declare(strict_types=1);

namespace BCOEM\Tests\Integration;

use MysqliDb;
use PHPUnit\Framework\TestCase;

/**
 * Base for MySQL-backed integration tests.
 *
 * CI (`.github/workflows/ci.yml`) runs a MySQL 8.0 service, loads
 * `sql/bcoem_baseline_3.0.X.sql` into database `bcoem_test` (tables
 * prefixed `baseline_`), and sets env vars. Local runs without MySQL
 * skip these tests rather than fail.
 *
 * Connection details can come from env (CI) or defaults matching the
 * workflow. Table prefix is `baseline_` to match the CI schema import.
 */
abstract class MySqlTestCase extends TestCase
{
    private static ?MysqliDb $db = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        if (! self::databaseAvailable()) {
            self::markTestSkipped('MySQL not available: '.self::$connectError);
        }
    }

    private static ?string $connectError = null;

    protected static function db(): MysqliDb
    {
        if (! self::$db instanceof MysqliDb) {
            $host = getenv('BCOEM_TEST_DB_HOST') ?: '127.0.0.1';
            $user = getenv('BCOEM_TEST_DB_USER') ?: 'root';
            $pass = getenv('BCOEM_TEST_DB_PASS') ?: 'root';
            $name = getenv('BCOEM_TEST_DB_NAME') ?: 'bcoem_test';
            self::$db = new MysqliDb($host, $user, $pass, $name);
            self::$db->setPrefix('baseline_');
        }

        return self::$db;
    }

    protected static function databaseAvailable(): bool
    {
        // CI (GitHub Actions) sets CI=true and runs a MySQL service. Locally,
        // only attempt a connection when explicitly requested via env.
        $ci = getenv('CI') !== false;
        $requested = getenv('BCOEM_TEST_DB') === '1';
        if (! $ci && ! $requested) {
            return false;
        }
        try {
            self::db()->connect();

            return true;
        } catch (\Throwable $e) {
            self::$connectError = $e->getMessage();

            return false;
        }
    }

    protected static function truncate(string $table): void
    {
        self::db()->rawQuery('TRUNCATE TABLE `baseline_'.$table.'`');
    }

    /**
     * Runs pending Laravel migrations once against the test MySQL database
     * (CI loads only the baseline SQL). Only callers that need port-added
     * tables (payments) invoke this — characterization tests pin baseline
     * schema truth and must not migrate implicitly.
     */
    public static function ensureMigrated(): void
    {
        if (self::$migrated) {
            return;
        }
        $env = [
            'DB_CONNECTION=mysql',
            'DB_HOST='.(getenv('BCOEM_TEST_DB_HOST') ?: '127.0.0.1'),
            'DB_PORT='.(getenv('BCOEM_TEST_DB_PORT') ?: '3306'),
            'DB_DATABASE='.(getenv('BCOEM_TEST_DB_NAME') ?: 'bcoem_test'),
            'DB_USERNAME='.(getenv('BCOEM_TEST_DB_USER') ?: 'root'),
            'DB_PASSWORD='.(getenv('BCOEM_TEST_DB_PASS') ?: 'root'),
            'DB_TABLE_PREFIX=baseline_',
        ];
        // The 0001_01_01_* Laravel defaults collide with baseline_ tables,
        // so plain `migrate` cannot run on this DB: migrate every
        // port-added migration by explicit --path instead (the migrator
        // still records them, so repeats are no-ops).
        $output = '';
        $output = '';
        $root = dirname(__DIR__, 2);
        foreach (glob($root.'/database/migrations/*.php') ?: [] as $file) {
            if (str_starts_with(basename((string) $file), '0001_')) {
                continue;
            }
            $output .= (string) shell_exec('cd '.escapeshellarg($root).' && env '
                .implode(' ', array_map('escapeshellarg', $env)).' '
                .escapeshellarg(PHP_BINARY).' artisan migrate --force --path='
                .escapeshellarg('database/migrations/'.basename((string) $file)));
        }
        // Fresh run prints "... DONE"; already-migrated prints
        // "Nothing to migrate." Anything else is a failure.
        if (! str_contains($output, 'DONE') && ! str_contains($output, 'Nothing to migrate')) {
            self::fail("migrate failed against test DB:\n".$output);
        }
        self::$migrated = true;
    }
}
