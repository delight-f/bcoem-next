<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Feature tests for the Phase 2 public surface. DB-gated like the rest of
 * the suite: skipped locally without MySQL, run in CI against the
 * baseline_-prefixed schema loaded by ci.yml.
 */
abstract class PublicSurfaceTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('BCOEM_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('BCOEM_TEST_DB_PORT') ?: '3306';
        $name = getenv('BCOEM_TEST_DB_NAME') ?: 'bcoem_test';
        $user = getenv('BCOEM_TEST_DB_USER') ?: 'root';
        $pass = getenv('BCOEM_TEST_DB_PASS') ?: 'root';

        try {
            new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: '.$e->getMessage());
        }

        Config::set('database.connections.mysql', array_merge(
            config('database.connections.mysql'),
            [
                'host' => $host,
                'port' => $port,
                'database' => $name,
                'username' => $user,
                'password' => $pass,
                'prefix' => 'baseline_',
            ],
        ));
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
    }
}
