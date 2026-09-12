<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Installation\Exceptions\UpgradeException;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Wraps UpgradeService::upgrade() for the web wizard. Same shape as
 * RunInstallationJob; the only difference is the backup path that travels on
 * an UpgradeException and is kept beside the messages so the failure screen
 * can name the file to relay to support.
 */
final class RunUpgradeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token)
    {
        // Assigned, not redeclared: the Queueable trait already declares this
        // property, and a typed redeclaration is a fatal trait conflict.
        $this->connection = 'sync';
    }

    public function handle(): void
    {
        $tracker = new ProgressTracker;

        try {
            app(UpgradeService::class)->upgrade(
                fn (string $label) => $tracker->step($this->token, $label),
            );
            $tracker->complete($this->token, 'Your site is back online.');
        } catch (UpgradeException $e) {
            $tracker->fail($this->token, $e->plainMessage, $e->getMessage(), $e->backupPath);
        } catch (\Throwable $e) {
            $tracker->fail($this->token, 'Something went wrong while applying the update.', $e->getMessage());
        } finally {
            $tracker->release('upgrade');
        }
    }
}
