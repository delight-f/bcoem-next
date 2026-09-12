<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\InstallationService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Base for the install/upgrade tests.
 *
 * Each test gets its own throwaway MySQL database (so nothing ever touches the
 * development or CI schema) and its own temporary root for `.env`/`VERSION`, so
 * a test install never rewrites the repo's real `.env`. Server credentials come
 * from the configured `mysql` connection, which reads `DB_*` from `.env`.
 */
abstract class InstallationTestCase extends TestCase
{
    /** @var array{host: string, port: string, user: string, pass: string} */
    protected array $server = ['host' => '', 'port' => '', 'user' => '', 'pass' => ''];

    protected string $database = '';

    protected string $root = '';

    /** @var list<string> */
    private array $existingBackups = [];

    /** @var mixed */
    private $originalDefault = null;

    /** @var array<string, mixed> */
    private array $originalMysql = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = (array) config('database.connections.mysql');
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $user = (string) ($connection['username'] ?? 'root');
        $pass = (string) ($connection['password'] ?? '');

        try {
            $server = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: '.$e->getMessage());
        }

        $this->server = compact('host', 'port', 'user', 'pass');
        $this->database = 'bcoem_part1_'.bin2hex(random_bytes(4));
        $server->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->originalDefault = config('database.default');
        $this->originalMysql = $connection;

        $this->useDatabase($this->database);

        $this->root = sys_get_temp_dir().'/'.$this->database;
        if (! is_dir($this->root)) {
            mkdir($this->root, 0700, true);
        }

        $this->existingBackups = glob(storage_path('app/backups/pre-upgrade-*')) ?: [];
    }

    protected function tearDown(): void
    {
        try {
            app(Application::class)->maintenanceMode()->deactivate();
        } catch (\Throwable) {
        }

        DB::purge('mysql');

        try {
            if ($this->database !== '') {
                $pdo = $this->serverPdo();
                $pdo->exec('DROP DATABASE IF EXISTS `'.$this->database.'`');
                $pdo->exec('DROP DATABASE IF EXISTS `'.$this->database.'_r`');
            }
        } catch (\Throwable) {
        }

        if ($this->root !== '' && is_dir($this->root)) {
            foreach (glob($this->root.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->root);
        }

        foreach (glob(storage_path('app/backups/pre-upgrade-*')) ?: [] as $backup) {
            if (! in_array($backup, $this->existingBackups, true)) {
                @unlink($backup);
            }
        }

        config()->set('database.default', $this->originalDefault);
        config()->set('database.connections.mysql', $this->originalMysql);
        DB::setDefaultConnection(is_string($this->originalDefault) ? $this->originalDefault : 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    protected function serverPdo(?string $database = null): PDO
    {
        $dsn = "mysql:host={$this->server['host']};port={$this->server['port']}";
        if ($database !== null) {
            $dsn .= ";dbname={$database}";
        }

        return new PDO($dsn, $this->server['user'], $this->server['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    protected function useDatabase(string $database): void
    {
        config()->set('database.connections.mysql', array_merge((array) config('database.connections.mysql'), [
            'host' => $this->server['host'],
            'port' => $this->server['port'],
            'database' => $database,
            'username' => $this->server['user'],
            'password' => $this->server['pass'],
            'prefix' => '',
        ]));
        config()->set('database.default', 'mysql');
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    protected function credentials(string $database = ''): DbCredentials
    {
        return new DbCredentials(
            $this->server['host'],
            $this->server['port'],
            $database !== '' ? $database : $this->database,
            $this->server['user'],
            $this->server['pass'],
        );
    }

    protected function importBaseline(): void
    {
        (new InstallationService($this->root))->importBaseSchema();
    }

    /**
     * Row count plus MySQL's own table checksum for every table, so "the same
     * data" is compared by the database rather than by eye.
     *
     * @return array<string, array{int, string|null}>
     */
    protected function fingerprint(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW TABLES');
        $this->assertNotFalse($tables);

        $names = $tables->fetchAll(PDO::FETCH_COLUMN);
        sort($names);

        $fingerprint = [];
        foreach ($names as $table) {
            $countStatement = $pdo->query('SELECT COUNT(*) FROM `'.$table.'`');
            $this->assertNotFalse($countStatement);

            $checksumStatement = $pdo->query('CHECKSUM TABLE `'.$table.'`');
            $this->assertNotFalse($checksumStatement);

            $checksum = $checksumStatement->fetch(PDO::FETCH_ASSOC)['Checksum'] ?? null;
            $fingerprint[(string) $table] = [(int) $countStatement->fetchColumn(), $checksum === null ? null : (string) $checksum];
        }

        return $fingerprint;
    }
}
