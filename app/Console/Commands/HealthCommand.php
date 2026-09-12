<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Installation\InstallationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan app:health` — DB connectivity, storage writability and required
 * extensions. The precondition checks are InstallationService's, not a second
 * copy of them.
 */
final class HealthCommand extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Check database, storage and extension health.';

    public function handle(InstallationService $service): int
    {
        $ok = true;

        try {
            DB::connection()->getPdo()->query('SELECT 1');
            $this->line('  [ok] Database connection is working.');
        } catch (\Throwable $e) {
            $ok = false;
            $this->line('  [!!] Database connection failed: '.$e->getMessage());
        }

        foreach ($service->checkPreconditions()->checks as $check) {
            $this->line(($check->passed ? '  [ok] ' : '  [!!] ').$check->message);
            $ok = $ok && $check->passed;
        }

        $this->line('  [ok] Queue connection: '.config('queue.default'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
