<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Jobs\RunInstallationJob;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    /**
     * The full wizard HTTP path, safe here because the service is bound to the
     * test's throwaway root: run() must decrypt the session password, install
     * with it, and clear the session on the success path.
     */
    public function test_http_run_installs_with_the_decrypted_password_and_clears_the_session(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = $this->token('http');

        $this->withSession([
            'wizard.install.db' => [
                'host' => $this->server['host'],
                'port' => $this->server['port'],
                'database' => $this->database,
                'username' => $this->server['user'],
                'password' => $this->server['pass'],
            ],
            'wizard.install.site' => [
                'app_url' => 'http://example.test',
                'admin_name' => 'Jane Admin',
                'admin_email' => 'jane@example.test',
                'admin_password' => Crypt::encryptString('s3cret-pass'),
            ],
        ])->postJson('/install/run', ['token' => $token])->assertOk();

        $marker = (new ProgressTracker)->get($token) ?? [];
        $this->assertSame('complete', $marker['status'] ?? null);
        $this->assertNull(session('wizard.install.site'), 'the success path must clear the stored password');

        $hash = (string) DB::table('users')->where('user_name', 'jane@example.test')->value('password');
        $this->assertTrue(Hash::check('s3cret-pass', $hash), 'the install must use the decrypted password');
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
