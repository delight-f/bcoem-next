<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\InstallationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;

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
        {--db-password-stdin : Read the database password from one line of STDIN}
        {--app-url= : Public site URL}
        {--admin-name= : Administrator name}
        {--admin-email= : Administrator email}
        {--admin-password= : Administrator password}
        {--admin-password-stdin : Read the administrator password from one line of STDIN}';

    protected $description = 'Install BCOEM on a fresh database.';

    /**
     * Resolve a secret without putting it on the command line. An explicit flag
     * wins, then one STDIN line, then the environment, then the interactive
     * prompt. Returns '' when genuinely absent so the caller's missing-value
     * guard still fires.
     */
    private function secretValue(string $flag, string $stdinFlag, string $envKey, string $prompt, bool $interactive): string
    {
        $value = $this->option($flag);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ((bool) $this->option($stdinFlag)) {
            return $this->readStdinLine();
        }

        $env = getenv($envKey);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return $interactive ? (string) $this->secret($prompt) : '';
    }

    private function readStdinLine(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $line = fgets($stream ?? STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    public function handle(InstallationService $service): int
    {
        $interactive = $this->input->isInteractive();

        $values = [
            'db-host' => (string) ($this->option('db-host') ?: ($interactive ? $this->ask('Database host', '127.0.0.1') : '')),
            'db-port' => (string) ($this->option('db-port') ?: ($interactive ? $this->ask('Database port', '3306') : '')),
            'db-name' => (string) ($this->option('db-name') ?: ($interactive ? $this->ask('Database name') : '')),
            'db-username' => (string) ($this->option('db-username') ?: ($interactive ? $this->ask('Database username') : '')),
            'db-password' => $this->secretValue('db-password', 'db-password-stdin', 'BCOEM_INSTALL_DB_PASSWORD', 'Database password', $interactive),
            'app-url' => (string) ($this->option('app-url') ?: ($interactive ? $this->ask('Site URL', 'http://localhost') : '')),
            'admin-name' => (string) ($this->option('admin-name') ?: ($interactive ? $this->ask('Administrator name') : '')),
            'admin-email' => (string) ($this->option('admin-email') ?: ($interactive ? $this->ask('Administrator email') : '')),
            'admin-password' => $this->secretValue('admin-password', 'admin-password-stdin', 'BCOEM_INSTALL_ADMIN_PASSWORD', 'Administrator password', $interactive),
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
