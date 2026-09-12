<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Jobs\RunInstallationJob;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;

/**
 * Drives the sync-queue job that wraps InstallationService::install().
 */
final class InstallWizardJobTest extends InstallationTestCase
{
    private function token(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(6));
    }

    private function input(DbCredentials $credentials): InstallInput
    {
        return new InstallInput($credentials, 'http://example.test', 'Jane Admin', 'jane@example.test', 's3cret-pass');
    }

    /**
     * The required cache-store reproduction: install() rewrites .env including
     * CACHE_STORE while the job owns the marker, and the marker still finishes
     * `complete` because the store was pinned before the run.
     */
    public function test_install_job_finishes_complete_after_the_cache_store_is_rewritten(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = $this->token('job');
        $tracker = new ProgressTracker;
        $tracker->pending($token);

        RunInstallationJob::dispatch($this->input($this->credentials()), $token);

        $marker = $tracker->get($token) ?? [];
        $this->assertSame('complete', $marker['status'] ?? null);

        $env = (string) file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('CACHE_STORE=file', $env, 'the install must rewrite CACHE_STORE');
        $this->assertStringContainsString('SESSION_DRIVER=file', $env, 'the install must rewrite SESSION_DRIVER');

        // Re-read through a fresh tracker: the marker is in the pinned store,
        // not somewhere the .env rewrite moved it to.
        $this->assertNotNull((new ProgressTracker)->get($token));
    }

    public function test_install_job_failure_marks_the_marker_with_both_messages(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $good = $this->credentials();
        $bad = new DbCredentials($good->host, $good->port, $good->database, $good->username, 'definitely-not-the-password');

        $token = $this->token('fail');
        $tracker = new ProgressTracker;
        $tracker->pending($token);

        RunInstallationJob::dispatch($this->input($bad), $token);

        $marker = $tracker->get($token) ?? [];
        $error = $marker['error'] ?? [];

        $this->assertSame('failed', $marker['status'] ?? null);
        $this->assertStringContainsString('password', strtolower((string) ($error['plain'] ?? '')));
        $this->assertNotSame('', (string) ($error['technical'] ?? ''));
        $this->assertNotSame($error['plain'] ?? '', $error['technical'] ?? '');
    }
}
