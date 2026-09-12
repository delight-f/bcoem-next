<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Wraps InstallationService::install() for the web wizard. No install logic
 * lives here — it forwards the service's progress hook into the marker and
 * turns a typed failure into a plain + technical pair.
 *
 * Forced onto the `sync` connection: a freshly uploaded install cannot be
 * assumed to have a queue worker running. The browser keeps the indicator
 * moving by polling the marker while this runs inline in the request.
 */
final class RunInstallationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly InstallInput $input,
        public readonly string $token,
    ) {
        // Assigned, not redeclared: the Queueable trait already declares this
        // property, and a typed redeclaration is a fatal trait conflict.
        $this->connection = 'sync';
    }

    public function handle(): void
    {
        $tracker = new ProgressTracker;

        try {
            app(InstallationService::class)->install(
                $this->input,
                fn (string $label) => $tracker->step($this->token, $label),
            );
            $tracker->complete($this->token, 'Your site is ready.');
        } catch (InstallationException $e) {
            $tracker->fail($this->token, $e->plainMessage, $e->getMessage());
        } catch (\Throwable $e) {
            $tracker->fail($this->token, 'Something went wrong while installing your site.', $e->getMessage());
        } finally {
            $tracker->release('install');
        }
    }
}
