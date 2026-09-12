<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\InstallationService;
use Illuminate\Console\Command;

/**
 * `php artisan app:install` — thin caller of InstallationService.
 *
 * Fully non-interactive via flags (Part 3's SSH script depends on this); with
 * no flags it prompts. All install logic lives in the service.
 */
final class InstallCommand extends Command
{
    protected $signature = 'app:install
        {--db-host= : Database host}
        {--db-port= : Database port}
        {--db-name= : Database name}
        {--db-username= : Database username}
        {--db-password= : Database password}
        {--app-url= : Public site URL}
        {--admin-name= : Administrator name}
        {--admin-email= : Administrator email}
        {--admin-password= : Administrator password}';

    protected $description = 'Install BCOEM on a fresh database.';

    public function handle(InstallationService $service): int
    {
        $interactive = $this->input->isInteractive();

        $values = [
            'db-host' => (string) ($this->option('db-host') ?: ($interactive ? $this->ask('Database host', '127.0.0.1') : '')),
            'db-port' => (string) ($this->option('db-port') ?: ($interactive ? $this->ask('Database port', '3306') : '')),
            'db-name' => (string) ($this->option('db-name') ?: ($interactive ? $this->ask('Database name') : '')),
            'db-username' => (string) ($this->option('db-username') ?: ($interactive ? $this->ask('Database username') : '')),
            'db-password' => (string) ($this->option('db-password') ?: ($interactive ? (string) $this->secret('Database password') : '')),
            'app-url' => (string) ($this->option('app-url') ?: ($interactive ? $this->ask('Site URL', 'http://localhost') : '')),
            'admin-name' => (string) ($this->option('admin-name') ?: ($interactive ? $this->ask('Administrator name') : '')),
            'admin-email' => (string) ($this->option('admin-email') ?: ($interactive ? $this->ask('Administrator email') : '')),
            'admin-password' => (string) ($this->option('admin-password') ?: ($interactive ? (string) $this->secret('Administrator password') : '')),
        ];

        $missing = array_keys(array_filter($values, static fn (string $v): bool => $v === ''));
        if ($missing !== []) {
            $this->error('Missing required value(s): '.implode(', ', $missing).'. Pass them as flags or run interactively.');

            return self::FAILURE;
        }

        $preconditions = $service->checkPreconditions();
        foreach ($preconditions->checks as $check) {
            $this->line(($check->passed ? '  [ok] ' : '  [!!] ').$check->message);
        }
        if (! $preconditions->passed()) {
            $this->error('Preconditions failed; nothing has been changed.');

            return self::FAILURE;
        }

        $credentials = new DbCredentials(
            $values['db-host'],
            $values['db-port'],
            $values['db-name'],
            $values['db-username'],
            $values['db-password'],
        );

        $connection = $service->testDatabaseConnection($credentials);
        if (! $connection->success) {
            $this->error($connection->message);

            return self::FAILURE;
        }
        $this->info($connection->message);

        $input = new InstallInput(
            $credentials,
            $values['app-url'],
            $values['admin-name'],
            $values['admin-email'],
            $values['admin-password'],
        );

        try {
            $service->install($input, function (string $label): void {
                $this->line('  '.$label);
            });
        } catch (InstallationException $e) {
            $this->error($e->getMessage());
            $this->line($e->plainMessage);

            return self::FAILURE;
        }

        $this->info('Installation complete. Visit '.$values['app-url'].' and log in as '.$values['admin-email'].'.');

        return self::SUCCESS;
    }
}
