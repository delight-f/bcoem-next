<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs exactly one upgrade step for the web wizard. Same shape as
 * RunInstallationJob; the only difference is the backup path that travels on
 * the failure marker so the failure screen can name the file to relay to
 * support.
 */
final class RunUpgradeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly int $cursor,
    ) {
        // Assigned, not redeclared: the Queueable trait already declares this
        // property, and a typed redeclaration is a fatal trait conflict.
        $this->connection = 'sync';
    }

    public function handle(): void
    {
        $tracker = new ProgressTracker;
        $service = app(UpgradeService::class);
        $steps = $service->steps();

        $step = $steps[$this->cursor] ?? null;
        if ($step === null) {
            // Nothing left to run; treat as terminal so the browser stops.
            $tracker->complete($this->token, 'Your site is back online.');
            $tracker->release('upgrade');

            return;
        }

        $tracker->step($this->token, $step['label']);

        $raw = $tracker->raw($this->token) ?? [];
        $state = is_array($raw['state'] ?? null) ? $raw['state'] : [];

        try {
            ($step['run'])($state);
        } catch (\Throwable $e) {
            $failure = $service->describeFailure($state, $e);
            $tracker->fail($this->token, $failure['plain'], $failure['technical'], $failure['backup_path']);
            $tracker->release('upgrade');

            return;
        }

        $next = $this->cursor + 1;
        $tracker->put($this->token, ['cursor' => $next, 'state' => $state]);

        if ($next >= count($steps)) {
            $tracker->complete($this->token, 'Your site is back online.');
            $tracker->release('upgrade');
        }
    }
}
