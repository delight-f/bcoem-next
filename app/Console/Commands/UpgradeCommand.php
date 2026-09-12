<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\UpgradeService;
use Illuminate\Console\Command;

/**
 * `php artisan app:upgrade` — thin caller of UpgradeService. No queue wrapper:
 * a terminal session has no request timeout.
 */
final class UpgradeCommand extends Command
{
    protected $signature = 'app:upgrade {--force : Skip the confirmation prompt}';

    protected $description = 'Back up the database and apply pending updates.';

    public function handle(UpgradeService $service): int
    {
        $current = $service->getCurrentVersion();
        $incoming = $service->getIncomingVersion();
        $this->line('Installed version: '.($current !== '' ? $current : 'unknown'));
        $this->line('Files on disk:     '.$incoming);

        if (! $service->needsUpgrade()) {
            $this->comment('No newer version on disk; the backup still runs before we finish.');
        }

        if ($this->input->isInteractive() && ! $this->option('force')
            && ! $this->confirm('A backup will be taken and the site will be briefly unavailable. Continue?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $preconditions = $service->checkPreconditions();
        foreach ($preconditions->checks as $check) {
            $this->line(($check->passed ? '  [ok] ' : '  [!!] ').$check->message);
        }
        if (! $preconditions->passed()) {
            $this->error('Preconditions failed; nothing has been changed.');

            return self::FAILURE;
        }

        try {
            $service->upgrade(function (string $label): void {
                $this->line('  '.$label);
            });
        } catch (InstallationException $e) {
            $this->error($e->getMessage());
            $this->line($e->plainMessage);

            return self::FAILURE;
        }

        $this->info('Upgrade complete. The site is running version '.$service->getCurrentVersion().'.');

        return self::SUCCESS;
    }
}
