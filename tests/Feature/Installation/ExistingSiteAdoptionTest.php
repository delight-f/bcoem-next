<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\Data\DatabaseInspection;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\AlreadyInstalledException;
use App\Services\Installation\Exceptions\NotInstalledException;
use App\Services\Installation\InstallationService;
use Illuminate\Support\Facades\DB;

/**
 * The club replaces the old application's files with this release and keeps the
 * database it has been running on. That is an upgrade, not an install — and it
 * has to be recognised before anything is written, because install() imports the
 * baseline schema and createAdmin() deletes every row in `users` and `brewer`.
 *
 * Each test gets its own throwaway database (InstallationTestCase), so a
 * populated one is built here exactly as a legacy site leaves it.
 */
final class ExistingSiteAdoptionTest extends InstallationTestCase
{
    private function installInput(DbCredentials $credentials): InstallInput
    {
        return new InstallInput($credentials, 'http://example.test', 'Jane Admin', 'jane@example.test', 's3cret-pass');
    }

    /** A database as a finished legacy install leaves it: schema, data, setup flag. */
    private function installedDatabase(string $version = '3.1.0.0'): void
    {
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 1, 'version' => $version]);
    }

    public function test_empty_database_is_installable(): void
    {
        $inspection = (new InstallationService($this->root))->inspectDatabase($this->credentials());

        $this->assertSame(DatabaseInspection::STATE_EMPTY, $inspection->state);
        $this->assertTrue($inspection->canInstall());
        $this->assertFalse($inspection->canAdopt());
        $this->assertFalse($inspection->isBlocked());
    }

    public function test_finished_installation_is_adoptable_and_reports_its_version(): void
    {
        $this->installedDatabase('3.1.0.0');

        $inspection = (new InstallationService($this->root))->inspectDatabase($this->credentials());

        $this->assertSame(DatabaseInspection::STATE_INSTALLED, $inspection->state);
        $this->assertSame('3.1.0.0', $inspection->version);
        $this->assertTrue($inspection->canAdopt());
        $this->assertFalse($inspection->canInstall());
        $this->assertStringContainsString('3.1.0.0', $inspection->message, 'the club should be told which site was found');
    }

    public function test_half_finished_installation_is_blocked(): void
    {
        $this->importBaseline();
        DB::table('bcoem_sys')->where('id', 1)->update(['setup' => 0, 'version' => '3.1.0.0']);

        $inspection = (new InstallationService($this->root))->inspectDatabase($this->credentials());

        $this->assertSame(DatabaseInspection::STATE_INCOMPLETE, $inspection->state);
        $this->assertTrue($inspection->isBlocked());
    }

    public function test_database_holding_foreign_tables_is_blocked(): void
    {
        $this->serverPdo($this->database)->exec('CREATE TABLE someone_elses_app (id int primary key)');

        $inspection = (new InstallationService($this->root))->inspectDatabase($this->credentials());

        $this->assertSame(DatabaseInspection::STATE_UNRECOGNISED, $inspection->state);
        $this->assertTrue($inspection->isBlocked());
        $this->assertSame(1, $inspection->tableCount);
    }

    public function test_install_refuses_an_occupied_database_without_touching_its_accounts(): void
    {
        // The data-loss case: with no guard, the wizard walked to the end and
        // createAdmin() deleted every row in `users` before adding its own.
        $this->installedDatabase();
        $before = (int) DB::table('users')->count();
        $this->assertGreaterThan(0, $before, 'the imported site must have accounts to lose');

        try {
            (new InstallationService($this->root))->install($this->installInput($this->credentials()));
            $this->fail('install() should have refused an occupied database');
        } catch (AlreadyInstalledException $e) {
            $this->assertStringContainsString('already', strtolower($e->plainMessage));
        }

        $this->assertSame($before, (int) DB::table('users')->count(), 'accounts must survive a refused install');
        $this->assertSame('3.1.0.0', (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));
    }

    public function test_adopt_records_connection_details_and_leaves_the_data_alone(): void
    {
        $this->installedDatabase();
        $users = (int) DB::table('users')->count();
        $styles = (int) DB::table('styles')->count();

        (new InstallationService($this->root))->adoptExistingInstallation($this->credentials(), 'https://club.example.test');

        $env = (string) file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('DB_DATABASE='.$this->database, $env);
        $this->assertStringContainsString('DB_HOST='.$this->server['host'], $env);
        $this->assertStringContainsString('DB_USERNAME='.$this->server['user'], $env);
        $this->assertStringContainsString('DB_TABLE_PREFIX=', $env);
        $this->assertStringContainsString('APP_URL=https://club.example.test', $env);

        $this->assertSame($users, (int) DB::table('users')->count(), 'adopting must not touch accounts');
        $this->assertSame($styles, (int) DB::table('styles')->count(), 'adopting must not touch content');
        $this->assertSame(
            '3.1.0.0',
            (string) DB::table('bcoem_sys')->where('id', 1)->value('version'),
            'adopting records no version of its own — the upgrade path does that',
        );
    }

    public function test_adopt_refuses_a_database_that_is_not_a_finished_site(): void
    {
        try {
            (new InstallationService($this->root))->adoptExistingInstallation($this->credentials(), 'https://club.example.test');
            $this->fail('adopt should have refused an empty database');
        } catch (NotInstalledException $e) {
            $this->assertStringContainsString('empty', strtolower($e->plainMessage));
        }

        $this->assertFileDoesNotExist($this->root.'/.env', 'a refused adopt must not leave configuration behind');
    }

    public function test_adopt_reports_the_version_it_found(): void
    {
        // The wizard screen that follows an adoption names the version the
        // database is at, so the caller gets it back rather than inspecting twice.
        $this->installedDatabase('2.9.1.0');

        $inspection = (new InstallationService($this->root))
            ->adoptExistingInstallation($this->credentials(), 'https://club.example.test');

        $this->assertSame('2.9.1.0', $inspection->version);
    }

    public function test_the_attached_screen_states_the_update_is_still_to_run(): void
    {
        // Attaching is not upgrading, and a site still on the old database
        // version looks finished — so the one remaining step is spelled out.
        $this->withSession(['wizard.install.attached' => ['database' => '3.1.0.0', 'version' => '4.1.0-alpha.4']])
            ->get('/install/attached')
            ->assertOk()
            ->assertSee('3.1.0.0')
            ->assertSee('4.1.0-alpha.4')
            ->assertSee('Sign in');
    }

    public function test_the_attached_screen_without_session_state_goes_to_the_site(): void
    {
        $this->get('/install/attached')->assertRedirect('/');
    }

    public function test_privilege_warning_flags_a_server_wide_account_but_not_a_scoped_one(): void
    {
        $service = new InstallationService($this->root);

        // The connection the suite runs on can administer the whole server.
        $this->assertNotSame(
            '',
            $service->inspectDatabase($this->credentials())->privilegeWarning,
            'a server-wide account must draw the advisory',
        );

        $scoped = 'scoped_'.bin2hex(random_bytes(3));
        $this->serverPdo()->exec("CREATE USER '{$scoped}'@'%' IDENTIFIED BY 'scoped-pass'");
        $this->serverPdo()->exec("GRANT ALL PRIVILEGES ON `{$this->database}`.* TO '{$scoped}'@'%'");

        try {
            $credentials = new DbCredentials($this->server['host'], $this->server['port'], $this->database, $scoped, 'scoped-pass');

            $this->assertSame(
                '',
                $service->inspectDatabase($credentials)->privilegeWarning,
                'an account limited to one database is exactly what we want',
            );
        } finally {
            $this->serverPdo()->exec("DROP USER '{$scoped}'@'%'");
        }
    }
}
