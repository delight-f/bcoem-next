<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-5 gate: the admin top navbar is a Bootstrap 5 dark navbar.
 *
 * Asserts the rendered top-bar region of an admin page carries no BS3
 * markers (navbar-inverse, caret, BS3 data-toggle), carries BS5 markers
 * (data-bs-toggle, navbar-dark), and that the user dropdown opens via
 * Bootstrap 5 JS with the account/logout rows present.
 */
final class AdminTopbarBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_admin_topbar_is_bootstrap5_dark_navbar(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/')
                ->click('[data-bs-toggle="modal"][data-bs-target="#login-modal"]')
                ->waitFor('#login-modal.show', 5)
                ->whenAvailable('#login-modal', function (Browser $modal): void {
                    $modal->type('loginUsername', self::ADMIN_EMAIL)
                        ->type('loginPassword', self::ADMIN_PASS)
                        ->press('#login-button');
                })
                ->assertPathIs('/admin');

            // Isolate the top-bar HTML so page-body BS3 markers don't leak in.
            $topbar = $browser->script(
                "return document.querySelector('.admin-topbar')?.outerHTML ?? '';"
            )[0];

            $this->assertStringContainsString('navbar-dark', $topbar, 'topbar is not a BS5 dark navbar');
            $this->assertStringNotContainsString('navbar-inverse', $topbar, 'navbar-inverse still on the topbar');
            $this->assertStringNotContainsString('caret', $topbar, 'BS3 caret still on the topbar');
            $this->assertStringNotContainsString('data-toggle=', $topbar, 'BS3 data-toggle on the topbar');
            $this->assertStringContainsString(
                'data-bs-toggle="dropdown"',
                $topbar,
                'user dropdown lacks the BS5 data-bs-toggle trigger'
            );

            // The user dropdown opens under Bootstrap 5 JS (.show on the menu).
            $browser->click('.admin-topbar [data-bs-toggle="dropdown"]')
                ->waitFor('.admin-topbar .dropdown-menu.show', 5);

            $items = $browser->script(
                "return document.querySelector('.admin-topbar .dropdown-menu').textContent;"
            )[0];
            $this->assertStringContainsString('Log Out', $items, 'logout row missing from the user dropdown');
            $this->assertStringContainsString('My Account', $items, 'account row missing from the user dropdown');

            // The (still-BS3 until issue 6) Admin Essentials offcanvas still opens.
            $browser->click('#admin-offcanvas-open');
            $state = $browser->script(
                "return JSON.stringify({in: document.getElementById('admin-offcanvas').classList.contains('in')});"
            )[0];
            $this->assertStringContainsString('"in":true', $state, 'offcanvas navmenu did not open from its trigger');
        });
    }
}
