<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
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

    /**
     * Authenticate as an existing user without the real login round-trip.
     * Use where the login flow is not itself under test; keep `POST /login`
     * wherever session state produced by a real login is asserted.
     */
    protected function loginWith(int $userId): static
    {
        $this->actingAs(User::query()->findOrFail($userId));

        return $this;
    }

    /**
     * Authenticate by email without the real login round-trip.
     * See loginWith() for when to prefer a real `POST /login` instead.
     */
    protected function loginWithEmail(string $email): static
    {
        $this->actingAs(User::query()->where('user_name', $email)->firstOrFail());

        return $this;
    }
}
