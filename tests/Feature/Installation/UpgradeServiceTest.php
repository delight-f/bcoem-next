<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\Exceptions\NotInstalledException;
use App\Services\Installation\Exceptions\UpgradeException;
use App\Services\Installation\InstallationService;
use App\Services\Installation\UpgradeService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

final class UpgradeServiceTest extends InstallationTestCase
{
    private function service(?string $mysqldumpPath = null): UpgradeService
    {
        return new UpgradeService(new InstallationService($this->root), $this->root, $mysqldumpPath);
    }

    private function writeVersion(string $version): void
    {
        file_put_contents($this->root.'/VERSION', $version);
    }

    private function restore(string $sqlPath, string $database): void
    {
        $this->serverPdo()->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $mysql = trim((string) shell_exec('command -v mysql 2>/dev/null')) ?: '/usr/bin/mysql';
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s < %s 2>/dev/null',
            escapeshellarg($mysql),
            escapeshellarg($this->server['host']),
            escapeshellarg($this->server['port']),
            escapeshellarg($this->server['user']),
            escapeshellarg($this->server['pass']),
            escapeshellarg($database),
            escapeshellarg($sqlPath),
        );
        exec($command, $output, $code);
        $this->assertSame(0, $code, 'restoring the backup with the mysql client failed');
    }

    public function test_mysqldump_backup_restores_to_identical_state(): void
    {
        $this->importBaseline();
        $this->writeVersion('4.0.0');
        $service = $this->service();

        $before = $this->fingerprint($this->serverPdo($this->database));

        $backup = $service->takeBackup();
        $this->assertSame('mysqldump', $backup->method);
        $this->assertTrue($service->verifyBackup($backup));
        $this->assertGreaterThan(0, $backup->sizeBytes);

        $restored = $this->database.'_r';
        $this->restore($backup->path, $restored);

        $this->assertSame($before, $this->fingerprint($this->serverPdo($restored)));
    }

    public function test_php_export_fallback_restores_to_identical_state(): void
    {
        $this->importBaseline();
        $this->writeVersion('4.0.0');
        $service = $this->service('/nonexistent/mysqldump');

        $before = $this->fingerprint($this->serverPdo($this->database));

        $backup = $service->takeBackup();
        $this->assertSame('php_export', $backup->method);
        $this->assertTrue($service->verifyBackup($backup));
        $this->assertGreaterThan(0, $backup->sizeBytes);

        $restored = $this->database.'_r';
        $this->restore($backup->path, $restored);

        $this->assertSame($before, $this->fingerprint($this->serverPdo($restored)));
    }

    public function test_version_marker_updates_on_successful_upgrade(): void
    {
        $this->importBaseline();
        $this->writeVersion('4.0.0');

        $service = $this->service();
        $this->assertTrue($service->needsUpgrade());
        $service->upgrade();

        $this->assertSame('4.0.0', $service->getCurrentVersion());
        $this->assertFalse(app(Application::class)->isDownForMaintenance());
    }

    public function test_version_marker_unchanged_and_backup_attached_on_failed_upgrade(): void
    {
        $this->importBaseline();
        $this->writeVersion('4.0.0');

        $service = new class(new InstallationService($this->root), $this->root) extends UpgradeService
        {
            protected function runMigrations(): void
            {
                throw new \RuntimeException('migration exploded');
            }
        };

        try {
            $service->upgrade();
            $this->fail('upgrade() should have thrown');
        } catch (UpgradeException $e) {
            $this->assertNotNull($e->backupPath);
            $this->assertFileExists((string) $e->backupPath);
        }

        $this->assertSame('3.0.1.0', (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));
        $this->assertFalse(app(Application::class)->isDownForMaintenance());
    }

    public function test_upgrade_refuses_when_not_installed(): void
    {
        $this->writeVersion('4.0.0');

        $this->expectException(NotInstalledException::class);
        $this->service()->upgrade();
    }

    public function test_upgrade_when_current_still_takes_backup(): void
    {
        $this->importBaseline();
        $this->writeVersion('3.0.1.0');

        $service = $this->service();
        $this->assertFalse($service->needsUpgrade());

        $service->upgrade();

        $this->assertNotEmpty(glob(storage_path('app/backups/pre-upgrade-*')) ?: []);
    }
}
