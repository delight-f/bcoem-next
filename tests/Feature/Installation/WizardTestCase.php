<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PublicSurfaceTestCase;

/**
 * Base for the wizard tests: flips the baseline `bcoem_sys` row between
 * installed / not-installed and hands out throwaway accounts, restoring both
 * afterwards. MySQL-backed like the rest of the public-surface suite, so the
 * middleware's real `bcoem_sys` reads are exercised.
 */
abstract class WizardTestCase extends PublicSurfaceTestCase
{
    /** @var array<string, mixed> */
    private array $originalSys = [];

    /** @var list<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = DB::table('bcoem_sys')->where('id', 1)->first();
        if ($row === null) {
            $this->markTestSkipped('bcoem_sys baseline row is missing.');
        }

        $this->originalSys = (array) $row;
    }

    protected function tearDown(): void
    {
        if ($this->originalSys !== []) {
            DB::table('bcoem_sys')->where('id', 1)->update($this->originalSys);
        }
        if ($this->createdUsers !== []) {
            DB::table('users')->whereIn('id', $this->createdUsers)->delete();
        }

        parent::tearDown();
    }

    protected function setInstalled(bool $installed, string $version): void
    {
        DB::table('bcoem_sys')->where('id', 1)->update([
            'setup' => $installed ? 1 : 0,
            'version' => $version,
        ]);
    }

    protected function user(string $level): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'user_name' => 'wizard.'.bin2hex(random_bytes(4)).'@example.test',
            'password' => app('hash')->make('secret'),
            'userLevel' => $level,
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->createdUsers[] = $id;

        return User::query()->findOrFail($id);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    protected function credentials(): array
    {
        $connection = (array) config('database.connections.mysql');

        return [
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '3306'),
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? 'root'),
            'password' => (string) ($connection['password'] ?? ''),
        ];
    }
}
