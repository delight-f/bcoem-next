<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\AlreadyInstalledException;
use App\Services\Installation\Exceptions\DatabaseConnectionException;
use App\Services\Installation\InstallationService;
use App\Services\Installation\UpgradeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
final class InstallationServiceTest extends InstallationTestCase
{
    private function input(DbCredentials $credentials): InstallInput
    {
        return new InstallInput($credentials, 'http://example.test', 'Jane Admin', 'jane@example.test', 's3cret-pass');
    }

    public function test_install_produces_working_install_and_second_call_throws(): void
    {
        $service = new InstallationService($this->root);
        $service->install($this->input($this->credentials()));

        $this->assertTrue($service->isAlreadyInstalled());
        $this->assertSame(
            InstallationService::SHIPPED_VERSION,
            (string) DB::table('bcoem_sys')->where('id', 1)->value('version'),
        );

        $hash = (string) DB::table('users')->where('user_name', 'jane@example.test')->value('password');
        $this->assertTrue(Hash::check('s3cret-pass', $hash));
        $this->assertSame('0', (string) DB::table('users')->where('user_name', 'jane@example.test')->value('userLevel'));
        $this->assertSame(1, DB::table('users')->count(), 'the baseline fixture admin must not survive');
        $this->assertGreaterThan(0, DB::table('styles')->count());
        $this->assertGreaterThan(0, DB::table('preferences')->count());

        $env = (string) file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('CACHE_STORE=file', $env, 'the install must rewrite CACHE_STORE');
        $this->assertStringContainsString('SESSION_DRIVER=file', $env, 'the install must rewrite SESSION_DRIVER');
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $env, 'the install must rewrite QUEUE_CONNECTION');

        try {
            $service->install($this->input($this->credentials()));
            $this->fail('second install() should have thrown');
        } catch (AlreadyInstalledException $e) {
            $this->assertSame(1, DB::table('users')->count(), 'second install() must not touch anything');
        }
    }

    public function test_install_probes_the_named_database_not_the_ambient_connection(): void
    {
        // The ambient default connection is an already-installed database...
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 1, 'version' => '3.0.1.0']);
        $this->assertTrue((new InstallationService($this->root))->isAlreadyInstalled());

        // ...while the input names a different, not-installed database.
        $target = $this->database.'_ambient';
        $this->serverPdo()->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            (new InstallationService($this->root))->install($this->input($this->credentials($target)));

            $installed = $this->serverPdo($target)->query('SELECT setup FROM bcoem_sys WHERE id = 1');
            $this->assertNotFalse($installed);
            $this->assertSame(1, (int) $installed->fetchColumn(), 'the install must land in the database the caller named');

            $ambient = $this->serverPdo($this->database)->query('SELECT version FROM bcoem_sys WHERE id = 1');
            $this->assertNotFalse($ambient);
            $this->assertSame('3.0.1.0', (string) $ambient->fetchColumn(), 'the ambient database must be left exactly as it was');
        } finally {
            $this->serverPdo()->exec('DROP DATABASE IF EXISTS `'.$target.'`');
        }
    }

    public function test_install_with_wrong_password_throws_with_password_specific_plain_message(): void
    {
        $service = new InstallationService($this->root);
        $bad = new DbCredentials($this->server['host'], $this->server['port'], $this->database, $this->server['user'], 'definitely-not-the-password');

        try {
            $service->install($this->input($bad));
            $this->fail('install() should have thrown');
        } catch (DatabaseConnectionException $e) {
            $this->assertStringContainsString('password', strtolower($e->plainMessage));
        }

        $this->assertFileDoesNotExist($this->root.'/.env', 'a failed connection must not leave configuration behind');
        $this->assertFalse($service->isAlreadyInstalled());
    }

    public function test_connection_test_yields_distinct_plain_messages(): void
    {
        $service = new InstallationService($this->root);

        $ok = $service->testDatabaseConnection($this->credentials());
        $this->assertTrue($ok->success);

        $badPassword = $service->testDatabaseConnection(new DbCredentials(
            $this->server['host'], $this->server['port'], $this->database, $this->server['user'], 'nope',
        ));
        $unknownHost = $service->testDatabaseConnection(new DbCredentials(
            'no-such-host.invalid', $this->server['port'], $this->database, $this->server['user'], $this->server['pass'],
        ));
        $unknownDatabase = $service->testDatabaseConnection(new DbCredentials(
            $this->server['host'], $this->server['port'], 'no_such_database_xyz', $this->server['user'], $this->server['pass'],
        ));

        $this->assertFalse($badPassword->success);
        $this->assertFalse($unknownHost->success);
        $this->assertFalse($unknownDatabase->success);

        $this->assertStringContainsString('password', strtolower($badPassword->message));
        $this->assertStringContainsString('reach', strtolower($unknownHost->message));
        $this->assertStringContainsString('exist', strtolower($unknownDatabase->message));

        $this->assertCount(3, array_unique([
            $badPassword->message,
            $unknownHost->message,
            $unknownDatabase->message,
        ]), 'each failure must read differently');
    }

    public function test_install_records_the_releases_own_version_from_the_version_file(): void
    {
        // Two bugs in one assertion. The marker was hard-coded to SHIPPED_VERSION,
        // so a 4.1.0-alpha.3 release marked itself 4.0.0 and then advertised an
        // upgrade to the version it was already running; and the legacy column,
        // varchar(12), could not hold a suffixed version at all — which is what
        // broke a real 3.1.0.0 upgrade on its final step.
        $version = '4.1.0-alpha.3';
        file_put_contents($this->root.'/VERSION', $version."\n");

        (new InstallationService($this->root))->install($this->input($this->credentials()));

        $this->assertSame($version, (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));

        // The symptom the club would see: a freshly installed site offering an
        // upgrade to the release it is already running.
        $upgrade = new UpgradeService(new InstallationService($this->root), $this->root);
        $this->assertFalse(
            $upgrade->needsUpgrade(),
            'a fresh install must not immediately report an available upgrade',
        );
    }

    public function test_install_command_runs_non_interactively(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $exit = Artisan::call('app:install', [
            '--db-host' => $this->server['host'],
            '--db-port' => $this->server['port'],
            '--db-name' => $this->database,
            '--db-username' => $this->server['user'],
            '--db-password' => $this->server['pass'],
            '--app-url' => 'http://example.test',
            '--admin-name' => 'Non Interactive',
            '--admin-email' => 'cmd@example.test',
            '--admin-password' => 'cmd-pass',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue(DB::table('users')->where('user_name', 'cmd@example.test')->exists());
    }

    public function test_install_command_reports_missing_flags_without_prompting(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->root));

        $exit = Artisan::call('app:install', ['--no-interaction' => true]);

        $this->assertSame(1, $exit);
    }
}
