<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs exactly one install step for the web wizard. No install logic lives
 * here — it forwards the step's label into the marker and turns a throw into
 * the marker's plain + technical pair via the service.
 *
 * Forced onto the `sync` connection: a freshly uploaded install cannot be
 * assumed to have a queue worker running. The browser drives one step per
 * `progress` request while this runs inline in that request.
 */
final class RunInstallationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly InstallInput $input,
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
        $service = app(InstallationService::class);
        $steps = $service->steps($this->input);

        $step = $steps[$this->cursor] ?? null;
        if ($step === null) {
            // Nothing left to run; treat as terminal so the browser stops.
            $tracker->complete($this->token, 'Your site is ready.');
            $tracker->release('install');

            return;
        }

        $tracker->step($this->token, $step['label']);

        $raw = $tracker->raw($this->token) ?? [];
        $state = is_array($raw['state'] ?? null) ? $raw['state'] : [];

        try {
            ($step['run'])($state);
        } catch (\Throwable $e) {
            $failure = $service->describeFailure($e);
            $tracker->fail($this->token, $failure['plain'], $failure['technical'], $failure['backup_path']);
            $tracker->release('install');

            return;
        }

        $next = $this->cursor + 1;
        $tracker->put($this->token, ['cursor' => $next, 'state' => $state]);

        if ($next >= count($steps)) {
            $tracker->complete($this->token, 'Your site is ready.');
            $tracker->release('install');
        }
    }
}
