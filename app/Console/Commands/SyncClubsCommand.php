<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Brewer\ClubsSyncService;
use Illuminate\Console\Command;

/**
 * `php artisan clubs:sync` (issue #22, Task B.4) — a thin wrapper over
 * ClubsSyncService so an operator on SSH can run the sync by hand and read
 * a plain summary. A failed sync exits non-zero so a scheduled run surfaces
 * as a failed task rather than a silent no-op.
 */
final class SyncClubsCommand extends Command
{
    protected $signature = 'clubs:sync';

    protected $description = 'Mirror the published central clubs list into the local clubs table.';

    public function handle(ClubsSyncService $service): int
    {
        $result = $service->sync();

        if (! $result->ok) {
            $this->error($result->summary());

            return self::FAILURE;
        }

        $this->info($result->summary());

        return self::SUCCESS;
    }
}
