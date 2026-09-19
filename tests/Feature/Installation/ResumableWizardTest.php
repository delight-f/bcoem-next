<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Models\User;
use App\Services\Installation\InstallationService;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;

/**
 * The resumable wizard: run() is bookkeeping only and each progress request
 * executes exactly one step, returning the terminal marker in band when it ran
 * the last one. Uses throwaway MySQL + a temp root via InstallationTestCase.
 */
#[Group('slow')]
final class ResumableWizardTest extends InstallationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The `wizard` store is file-backed and shared across test processes,
        // and an abandoned run holds its lock for 900s, so clear both locks
        // before and after each test to keep this class self-contained.
        $this->releaseLocks();
    }

    protected function tearDown(): void
    {
        $this->releaseLocks();

        parent::tearDown();
    }

    private function releaseLocks(): void
    {
        $tracker = new ProgressTracker;
        $tracker->release('install');
        $tracker->release('upgrade');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function installSession(?string $dbPassword = null): array
    {
        return [
            'wizard.install.db' => [
                'host' => $this->server['host'],
                'port' => $this->server['port'],
                'database' => $this->database,
                'username' => $this->server['user'],
                'password' => $dbPassword ?? $this->server['pass'],
            ],
            'wizard.install.site' => [
                'app_url' => 'http://example.test',
                'admin_name' => 'Jane Admin',
                'admin_email' => 'jane@example.test',
                'admin_password' => Crypt::encryptString('s3cret-pass'),
            ],
        ];
    }

    private function admin(): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'user_name' => 'resume.admin@example.test',
            'password' => Hash::make('secret'),
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(string $url): array
    {
        return (array) $this->getJson($url)->assertOk()->json();
    }

    private function envKey(): string
    {
        $env = (string) file_get_contents($this->root.'/.env');
        preg_match('/^APP_KEY=(.*)$/m', $env, $matches);

        return trim($matches[1] ?? '');
    }

    private function decodedDbPassword(string $payload): string
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $db */
        $db = $data['db'];

        return (string) $db['password'];
    }

    public function test_install_runs_one_step_per_progress_request_and_completes_in_band(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = 'resumeinstall0001';

        $this->withSession($this->installSession())
            ->postJson('/install/run', ['token' => $token])
            ->assertOk();

        // First poll runs exactly one step and is not yet terminal.
        $first = $this->poll('/install/progress?token='.$token);
        $this->assertSame(1, $first['cursor'] ?? null);
        $this->assertNotSame('complete', $first['status'] ?? null);
        $this->assertFalse(
            app(InstallationService::class)->isAlreadyInstalled(),
            'the site is not installed until the last step has run',
        );

        $cursors = [$first['cursor']];
        $terminal = $first;
        for ($i = 0; $i < 11 && ! in_array($terminal['status'] ?? null, ['complete', 'failed'], true); $i++) {
            $terminal = $this->poll('/install/progress?token='.$token);
            $cursors[] = $terminal['cursor'] ?? null;
        }

        $error = is_array($terminal['error'] ?? null) ? $terminal['error'] : [];
        $this->assertSame('complete', $terminal['status'] ?? null, (string) ($error['technical'] ?? ''));

        // One step per request: the cursor advances 1, 2, 3, … with no gaps.
        $this->assertSame(range(1, count($cursors)), $cursors);
        $this->assertGreaterThan(1, count($cursors), 'the install must take more than one request');

        $this->assertTrue(app(InstallationService::class)->isAlreadyInstalled());
        $this->assertSame(1, DB::table('users')->where('user_name', 'jane@example.test')->count());
    }

    /**
     * The APP_KEY rotation bug: if the key rotates before the last step, every
     * later progress request (a fresh process booting from the rotated .env)
     * decrypts the payload with the wrong key and the install hangs.
     */
    public function test_app_key_is_written_only_in_the_final_step_and_payload_survives(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = 'resumekeystep0001';

        $this->withSession($this->installSession())
            ->postJson('/install/run', ['token' => $token])
            ->assertOk();

        $payload = (string) (((new ProgressTracker)->raw($token) ?? [])['payload'] ?? '');
        $this->assertNotSame('', $payload);

        // Five steps now: run the first four (cursors 0..3) and check the key
        // is untouched, and the payload is still readable with the live key.
        $keyBefore = null;
        for ($i = 0; $i < 4; $i++) {
            $marker = $this->poll('/install/progress?token='.$token);
            $this->assertNotSame('complete', $marker['status'] ?? null, 'the install must not finish early');
            $this->assertSame($this->server['pass'], $this->decodedDbPassword($payload));

            $current = $this->envKey();
            $keyBefore ??= $current;
            $this->assertSame($keyBefore, $current, 'APP_KEY must be unchanged until the final step');
        }

        // The final step rotates the key and completes in the same response.
        $terminal = $this->poll('/install/progress?token='.$token);
        $error = is_array($terminal['error'] ?? null) ? $terminal['error'] : [];

        $this->assertSame('complete', $terminal['status'] ?? null, (string) ($error['technical'] ?? ''));
        $this->assertNotSame($keyBefore, $this->envKey(), 'the final step must rotate APP_KEY');
        $this->assertStringStartsWith('base64:', $this->envKey());
        $this->assertSame($this->envKey(), (string) config('app.key'));
    }

    public function test_undecryptable_payload_fails_instead_of_looping(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = 'resumebadpayload01';

        $this->withSession($this->installSession())
            ->postJson('/install/run', ['token' => $token])
            ->assertOk();

        // The payload is present but cannot be decrypted with the live key —
        // exactly what an APP_KEY rotation between steps looks like.
        (new ProgressTracker)->put($token, ['payload' => 'not-a-valid-ciphertext']);

        $marker = $this->poll('/install/progress?token='.$token);
        $error = is_array($marker['error'] ?? null) ? $marker['error'] : [];

        $this->assertSame('failed', $marker['status'] ?? null, 'an undecryptable payload must not loop on running');
        $this->assertNotSame('', (string) ($error['technical'] ?? ''));

        $tracker = new ProgressTracker;
        $this->assertTrue($tracker->acquire('install'), 'the run lock must be released');
        $tracker->release('install');
    }

    public function test_pending_marker_without_payload_keeps_polling(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = 'resumepending001';
        (new ProgressTracker)->pending($token);

        $marker = $this->poll('/install/progress?token='.$token);

        $this->assertSame('pending', $marker['status'] ?? null);
        $this->assertSame(0, $marker['cursor'] ?? null);
    }

    public function test_failed_install_step_releases_the_lock_and_restores_the_ambient_connection(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $token = 'resumefail0000001';

        $this->withSession($this->installSession('definitely-not-the-password'))
            ->postJson('/install/run', ['token' => $token])
            ->assertOk();

        $marker = $this->poll('/install/progress?token='.$token);
        $error = is_array($marker['error'] ?? null) ? $marker['error'] : [];

        $this->assertSame('failed', $marker['status'] ?? null);
        $this->assertStringContainsString('password', strtolower((string) ($error['plain'] ?? '')));

        // A failed step 0 must put the ambient connection back (c17a683), so the
        // rest of this request — and the next attempt — can still talk to the
        // database the caller is actually configured with.
        $this->assertSame($this->database, (string) config('database.connections.mysql.database'));
        $this->assertSame($this->server['pass'], (string) config('database.connections.mysql.password'));

        // The run lock is released on failure so the operator can retry.
        $tracker = new ProgressTracker;
        $this->assertTrue($tracker->acquire('install'));
        $tracker->release('install');

        $this->assertFileDoesNotExist($this->root.'/.env', 'a failed step 0 must not write configuration');
    }

    public function test_upgrade_backup_runs_alone_then_failure_keeps_backup_and_version(): void
    {
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 1, 'version' => '3.0.1.0']);
        file_put_contents($this->root.'/VERSION', '4.0.0');

        $service = new class(new InstallationService($this->root), $this->root) extends UpgradeService
        {
            protected function runMigrations(): void
            {
                throw new \RuntimeException('migration exploded');
            }
        };
        $this->app->instance(UpgradeService::class, $service);

        $admin = $this->admin();
        $token = 'resumeupgrade001';

        $this->actingAs($admin)->postJson('/upgrade/run', ['token' => $token])->assertOk();

        // One step per request: backup, then maintenance, then the throw. The
        // post-maintenance poll must be 200 — that is the exemption this test
        // guards alongside the reachability test below.
        $first = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        $this->assertSame(1, $first['cursor'] ?? null);
        $this->assertSame('running', $first['status'] ?? null);

        $terminal = $first;
        for ($i = 0; $i < 6 && ! in_array($terminal['status'] ?? null, ['complete', 'failed'], true); $i++) {
            $terminal = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        }

        $error = is_array($terminal['error'] ?? null) ? $terminal['error'] : [];

        $this->assertSame('failed', $terminal['status'] ?? null);
        $this->assertNotNull($error['backup_path'] ?? null, 'the failure marker must name the backup');
        $this->assertFileExists((string) $error['backup_path']);

        // Ordering contract: maintenance exited, version marker untouched.
        $this->assertFalse(app(Application::class)->isDownForMaintenance());
        $this->assertSame('3.0.1.0', (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));

        // The upgrade lock is released on failure too.
        $tracker = new ProgressTracker;
        $this->assertTrue($tracker->acquire('upgrade'));
        $tracker->release('upgrade');
    }

    /**
     * The exemption in bootstrap/app.php: the site is down for everyone, but
     * the upgrade control plane must stay reachable so the upgrade that put it
     * down can finish. Without it, the poll after the maintenance step is 503.
     */
    public function test_upgrade_progress_is_reachable_while_the_site_is_in_maintenance(): void
    {
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 1, 'version' => '3.0.1.0']);
        file_put_contents($this->root.'/VERSION', '4.0.0');

        $this->app->instance(UpgradeService::class, new UpgradeService(new InstallationService($this->root), $this->root));

        $admin = $this->admin();
        $token = 'resumemaint001';

        $this->actingAs($admin)->postJson('/upgrade/run', ['token' => $token])->assertOk();

        // Step 0: backup, site still up.
        $backup = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        $this->assertSame(1, $backup['cursor'] ?? null);
        $this->assertFalse(app(Application::class)->isDownForMaintenance());

        // Step 1: maintenance on.
        $maintenance = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        $this->assertSame(2, $maintenance['cursor'] ?? null);
        $this->assertTrue(app(Application::class)->isDownForMaintenance());

        // The public site is down…
        $this->get('/')->assertStatus(503);

        // …but the control plane stays reachable and advances exactly one step.
        $next = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        $this->assertSame(3, $next['cursor'] ?? null);

        // Finish: the last step exits maintenance and completes in band.
        $terminal = $next;
        for ($i = 0; $i < 5 && ! in_array($terminal['status'] ?? null, ['complete', 'failed'], true); $i++) {
            $terminal = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
        }

        $this->assertSame('complete', $terminal['status'] ?? null);
        $this->assertFalse(app(Application::class)->isDownForMaintenance());
    }

    public function test_upgrade_completes_in_band_when_the_last_step_runs(): void
    {
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 1, 'version' => '3.0.1.0']);
        file_put_contents($this->root.'/VERSION', '4.0.0');

        $this->app->instance(UpgradeService::class, new UpgradeService(new InstallationService($this->root), $this->root));

        $admin = $this->admin();
        $token = 'resumeupgradeok01';

        $this->actingAs($admin)->postJson('/upgrade/run', ['token' => $token])->assertOk();

        $terminal = [];
        for ($i = 0; $i < 8; $i++) {
            $terminal = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->assertOk()->json();
            if (in_array($terminal['status'] ?? null, ['complete', 'failed'], true)) {
                break;
            }
        }

        $error = is_array($terminal['error'] ?? null) ? $terminal['error'] : [];
        $this->assertSame('complete', $terminal['status'] ?? null, (string) ($error['technical'] ?? ''));
        $this->assertSame('4.0.0', (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));
        $this->assertFalse(app(Application::class)->isDownForMaintenance());
    }
}
