<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\InstallationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A bare release zip has no database at all. The web middleware group runs
 * before EnsureInstalled, and three of its members read tenant/mail settings
 * unconditionally, so on an unconfigured upload they used to 500 before the
 * wizard could redirect. They must now fall through to their defaults, while
 * still surfacing a real failure on an installed site.
 */
final class BareUploadMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Point the default connection at a port nothing is listening on: every
        // tenant/mail lookup now fails the way it does on a fresh upload.
        Config::set('database.connections.mysql', array_merge(
            (array) config('database.connections.mysql'),
            [
                'host' => '127.0.0.1',
                'port' => '6399',
                'database' => 'bcoem_missing',
                'username' => 'nobody',
                'password' => 'nope',
                'prefix' => '',
            ],
        ));
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
    }

    public function test_installer_surface_renders_without_a_database(): void
    {
        // Welcome screen: reaches the controller through all web middleware.
        $this->get('/install')
            ->assertOk()
            ->assertSee('Get Started');

        // System-check screen: reaches InstallationService::checkPreconditions().
        $this->get('/install/checks')->assertOk();
    }

    public function test_requests_redirect_to_the_wizard_without_a_database(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/login')->assertRedirect('/install');
    }

    public function test_a_real_failure_is_not_masked_on_an_installed_site(): void
    {
        // The same broken connection, but the install marker says "installed":
        // the tenant lookup's failure must propagate, not be swallowed.
        $this->app->instance(InstallationService::class, new class extends InstallationService
        {
            public function isAlreadyInstalled(): bool
            {
                return true;
            }
        });

        $this->withoutExceptionHandling();

        $this->expectException(QueryException::class);

        $this->get('/install');
    }
}
