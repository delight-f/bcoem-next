<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-11 gate: dual-chrome + print/account surfaces (batch C) are BS5 clean.
 *
 * The batch-C blades render in both the admin shell and the public shell.
 * Asserts the reachable account/edit/register surfaces carry no BS3-only or
 * daisy-only markers and keep BS5 markers, in the admin-logged-in path, and
 * that the public register path (issue-4 territory) stays clean.
 */
final class AdminBatchCBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_dual_chrome_batch_c_pages_are_marker_free(): void
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
            $browser->pause(900);

            // Admin-shell renders of the dual-chrome blades.
            foreach (['/list/edit-account', '/list/edit-clubs', '/register'] as $path) {
                $browser->visit($path);
                $browser->pause(900);

                $html = $browser->driver->getPageSource();
                $legacy = array_merge(
                    self::markersInHtml($html, $this->bs3OnlyClasses),
                    self::markersInHtml($html, $this->daisyOnlyMarkers)
                );
                $this->assertSame([], $legacy, "legacy markers on {$path}: ".implode(', ', $legacy));
                $this->assertNotEmpty(
                    self::markersInHtml($html, $this->bs5Markers),
                    "No BS5 markers on {$path}"
                );
            }
        });
    }

    public function test_public_shell_register_stays_issue4_clean(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();

            $browser->visit('/register');
            $browser->pause(900);
            $html = $browser->driver->getPageSource();

            $legacy = array_merge(
                self::markersInHtml($html, $this->bs3OnlyClasses),
                self::markersInHtml($html, $this->daisyOnlyMarkers)
            );
            $this->assertSame([], $legacy, 'public /register regressed legacy markers: '.implode(', ', $legacy));
            $this->assertNotEmpty(
                self::markersInHtml($html, $this->bs5Markers),
                'public /register lost its BS5 markers'
            );
        });
    }
}
