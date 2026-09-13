<?php

declare(strict_types=1);

namespace App\Services\Installation;

use App\Services\Installation\Data\BackupResult;
use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\Exceptions\BackupFailedException;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\Exceptions\NotInstalledException;
use App\Services\Installation\Exceptions\UpgradeException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * The one place that knows how to safely back up a database and run
 * migrations during an upgrade.
 *
 * `install()` in InstallationService is the only other caller of the migrator;
 * it is a fresh-schema concern, not a second copy of this one.
 */
class UpgradeService
{
    /** Minimum free space (bytes) the backup check insists on. */
    private const MIN_FREE_BYTES = 50 * 1024 * 1024;

    private string $rootPath;

    private ?string $mysqldumpPath;

    private UpgradeFixups $fixups;

    public function __construct(
        private readonly InstallationService $installation = new InstallationService,
        ?string $rootPath = null,
        ?string $mysqldumpPath = null,
        ?UpgradeFixups $fixups = null,
    ) {
        $this->rootPath = rtrim($rootPath ?? base_path(), '/');
        $this->mysqldumpPath = $mysqldumpPath;
        $this->fixups = $fixups ?? new UpgradeFixups;
    }

    public function getCurrentVersion(): string
    {
        try {
            if (! Schema::hasTable('bcoem_sys')) {
                return '';
            }

            return (string) (DB::table('bcoem_sys')->where('id', 1)->value('version') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function getIncomingVersion(): string
    {
        return InstallationService::versionIn($this->rootPath);
    }

    public function needsUpgrade(): bool
    {
        return version_compare($this->getCurrentVersion(), $this->getIncomingVersion(), '<');
    }

    public function checkPreconditions(): PreconditionResult
    {
        $checks = $this->installation->checkPreconditions()->checks;
        $checks[] = $this->checkDiskSpace();

        return new PreconditionResult($checks);
    }

    public function takeBackup(): BackupResult
    {
        $directory = storage_path('app/backups');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/pre-upgrade-'.$this->getCurrentVersion().'-to-'.$this->getIncomingVersion().'-'.date('YmdHis').'.sql';

        $binary = $this->mysqldumpPath ?? $this->locateMysqldump();
        if ($binary !== null && $this->runMysqldump($binary, $path)) {
            return new BackupResult($path, (int) filesize($path), 'mysqldump');
        }

        return $this->phpExport($path);
    }

    public function verifyBackup(BackupResult $result): bool
    {
        return is_file($result->path) && (int) filesize($result->path) > 0;
    }

    /**
     * PHP-only export of every table's schema and rows. Used when `mysqldump`
     * cannot be executed at all (some shared hosts block shell binaries), so a
     * backup still exists before any migration runs.
     */
    public function phpExport(string $path): BackupResult
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();

        // TIMESTAMP values are session-timezone dependent. Read them in UTC and
        // tell the restore session the same, or the restored rows shift.
        $pdo->exec("SET time_zone = '+00:00'");

        $tables = array_map(
            static fn (object|array $row): string => (string) array_values((array) $row)[0],
            $connection->select('SHOW TABLES'),
        );

        $sql = "-- bcoem PHP export\nSET TIME_ZONE='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n";

        foreach ($tables as $table) {
            $createRow = (array) $connection->select('SHOW CREATE TABLE `'.$table.'`')[0];
            $sql .= 'DROP TABLE IF EXISTS `'.$table."`;\n".(string) $createRow['Create Table'].";\n";

            $rows = $connection->select('SELECT * FROM `'.$table.'`');
            foreach (array_chunk($rows, 500) as $chunk) {
                /** @var list<array<string, mixed>> $chunk */
                $columns = '`'.implode('`,`', array_keys((array) $chunk[0])).'`';
                $values = [];
                foreach ($chunk as $row) {
                    $values[] = '('.implode(',', array_map(
                        fn (mixed $value): string => $this->quote($pdo, $value),
                        array_values((array) $row),
                    )).')';
                }
                $sql .= 'INSERT INTO `'.$table.'` ('.$columns.") VALUES\n".implode(",\n", $values).";\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($path, $sql);

        return new BackupResult($path, (int) filesize($path), 'php_export');
    }

    /**
     * @param  (callable(string, BackupResult|null): void)|null  $onStep
     */
    public function upgrade(?callable $onStep = null): void
    {
        $state = [];

        foreach ($this->steps() as $step) {
            $this->step($onStep, $step['label']);

            try {
                ($step['run'])($state);
            } catch (\Throwable $e) {
                $failure = $this->describeFailure($state, $e);

                // Typed failures (not installed, backup failed) travel as they
                // are; anything else after maintenance started is wrapped so
                // the backup path reaches the operator.
                if ($e instanceof InstallationException) {
                    throw $e;
                }

                throw new UpgradeException(
                    $failure['technical'],
                    $failure['plain'],
                    (int) $e->getCode(),
                    $e,
                    $failure['backup_path'],
                );
            }
        }
    }

    /**
     * The upgrade sequence as individually runnable units. `upgrade()` loops
     * this same list, so the CLI and the resumable wizard share one order.
     *
     * Order is the contract: backup → verify → maintenance → migrate → fixups
     * → caches → marker → exit maintenance.
     *
     * @return list<array{label: string, run: \Closure(array<string, mixed> &$state): void}>
     */
    public function steps(): array
    {
        return [
            [
                'label' => 'Backing up your data…',
                'run' => function (array &$state): void {
                    if (! $this->installation->isAlreadyInstalled()) {
                        throw new NotInstalledException(
                            'Refusing to upgrade: bcoem_sys.setup is not 1.',
                            'This site isn\'t installed yet, so there\'s nothing to update.',
                        );
                    }

                    $backup = $this->takeBackup();

                    if (! $this->verifyBackup($backup)) {
                        throw new BackupFailedException(
                            'Backup verification failed for '.$backup->path.'.',
                            'We couldn\'t finish the backup, so nothing has been changed. Your site is exactly as it was — please try again.',
                        );
                    }

                    $state['backup_path'] = $backup->path;
                    $state['backup_size'] = $backup->sizeBytes;
                },
            ],
            [
                'label' => 'Entering maintenance mode…',
                'run' => function (array &$state): void {
                    $this->enterMaintenance();
                    $state['maintenance'] = true;
                },
            ],
            [
                'label' => 'Applying updates…',
                'run' => function (array &$state): void {
                    $this->runMigrations();

                    foreach ($this->fixups->for($this->getCurrentVersion(), $this->getIncomingVersion()) as $fixup) {
                        $fixup->run();
                    }
                },
            ],
            [
                'label' => 'Tidying up…',
                'run' => function (array &$state): void {
                    $this->clearCaches();
                },
            ],
            [
                'label' => 'Your site is back online.',
                'run' => function (array &$state): void {
                    $this->writeVersionMarker($this->getIncomingVersion());
                    $this->exitMaintenance();
                    $state['maintenance'] = false;
                },
            ],
        ];
    }

    /**
     * Plain + technical pair for the marker, and the one place that decides
     * whether a failure must leave maintenance mode.
     *
     * @param  array<string, mixed>  $state
     * @return array{plain: string, technical: string, backup_path: ?string}
     */
    public function describeFailure(array $state, \Throwable $e): array
    {
        $backupPath = isset($state['backup_path']) && is_string($state['backup_path']) ? $state['backup_path'] : null;

        if (($state['maintenance'] ?? false) === true) {
            try {
                $this->exitMaintenance();
            } catch (\Throwable) {
                // Leaving the site down is the safe failure: a human resolves it.
            }
        }

        if ($e instanceof UpgradeException) {
            return [
                'plain' => $e->plainMessage,
                'technical' => $e->getMessage(),
                'backup_path' => $e->backupPath ?? $backupPath,
            ];
        }

        if ($e instanceof InstallationException) {
            return [
                'plain' => $e->plainMessage,
                'technical' => $e->getMessage(),
                'backup_path' => $backupPath,
            ];
        }

        return [
            'plain' => 'Something went wrong while applying the update, so the site may be in maintenance mode. '
                .'Your data was backed up to '.($backupPath ?? 'the backup file').'. Contact support with this message before trying again.',
            'technical' => 'Upgrade failed: '.$e->getMessage(),
            'backup_path' => $backupPath,
        ];
    }

    /**
     * Runs pending migrations. Kept as a seam so the failure path is testable.
     */
    protected function runMigrations(): void
    {
        $this->installation->runPendingMigrations();
    }

    private function checkDiskSpace(): PreconditionCheck
    {
        $free = @disk_free_space(storage_path('app'));
        $enough = $free === false || $free >= self::MIN_FREE_BYTES;

        return new PreconditionCheck(
            'disk_space',
            $enough,
            match (true) {
                $free === false => 'We couldn\'t read the free disk space, so we\'ll rely on the backup check.',
                $enough => 'There is enough free disk space for a backup.',
                default => 'Only '.$this->formatBytes((int) $free).' of disk space is free. Free up at least 50 MB before updating.',
            },
        );
    }

    private function locateMysqldump(): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $path = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    private function runMysqldump(string $binary, string $path): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $connection = (array) config('database.connections.mysql');
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s --add-drop-table --skip-lock-tables --single-transaction %s > %s 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($connection['port'] ?? '3306')),
            escapeshellarg((string) ($connection['username'] ?? 'root')),
            escapeshellarg((string) ($connection['password'] ?? '')),
            escapeshellarg((string) ($connection['database'] ?? '')),
            escapeshellarg($path),
        );

        exec($command, $output, $code);

        return $code === 0 && is_file($path) && (int) filesize($path) > 0;
    }

    private function quote(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    private function writeVersionMarker(string $version): void
    {
        $values = [
            'version' => $version,
            'version_date' => now()->toDateString(),
            'update_date' => (string) time(),
        ];

        if (DB::table('bcoem_sys')->where('id', 1)->exists()) {
            DB::table('bcoem_sys')->where('id', 1)->update($values);
        } else {
            DB::table('bcoem_sys')->insert(array_merge(['id' => 1, 'setup' => 1], $values));
        }
    }

    private function enterMaintenance(): void
    {
        app(Application::class)->maintenanceMode()->activate([]);
    }

    private function exitMaintenance(): void
    {
        app(Application::class)->maintenanceMode()->deactivate();
    }

    private function clearCaches(): void
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * @param  (callable(string, BackupResult|null): void)|null  $onStep
     */
    private function step(?callable $onStep, string $label, ?BackupResult $backup = null): void
    {
        if ($onStep !== null) {
            $onStep($label, $backup);
        }
    }
}
