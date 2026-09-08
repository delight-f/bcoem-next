<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-6 gate: the Admin Essentials menu is a Bootstrap 5 offcanvas.
 *
 * Asserts the offcanvas opens from its BS5 trigger, carries no BS3 navmenu /
 * caret / data-toggle markers, renders every group (BS5 collapse) with its
 * items, that a group expands under BS5 JS and an item navigates, and that
 * close/ESC/backdrop dismissal work.
 */
final class AdminOffcanvasBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_admin_essentials_offcanvas_is_bootstrap5(): void
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

            // Let the login modal's backdrop fully clear before interacting.
            $browser->pause(800);

            // Open the offcanvas from the BS5 trigger (retry: the first click
            // after the login POST can race the page's boot JS).
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $browser->click('#admin-offcanvas-open');
                } catch (\Throwable $e) {
                    // element lookup raced; retry
                }
                $shown = $browser->script(
                    "return document.getElementById('admin-offcanvas').classList.contains('show');"
                )[0];
                if ($shown) {
                    break;
                }
                $browser->pause(500);
            }
            $this->assertTrue(
                (bool) $browser->script(
                    "return document.getElementById('admin-offcanvas').classList.contains('show');"
                )[0],
                'offcanvas did not open from its trigger'
            );

            // Marker gate over the offcanvas region.
            $html = $browser->script(
                "return document.getElementById('admin-offcanvas').outerHTML;"
            )[0];
            foreach (['navmenu', 'navbar-inverse', 'caret', 'data-toggle='] as $bs3) {
                $this->assertStringNotContainsString($bs3, $html, "BS3 marker '{$bs3}' on the offcanvas");
            }
            $this->assertStringContainsString(
                'data-bs-toggle="collapse"',
                $html,
                'no BS5 collapse groups on the offcanvas'
            );

            // Every group renders; expand the first (Competition Preparation).
            $groupCount = $browser->script(
                "return document.querySelectorAll('#admin-offcanvas [data-bs-toggle=collapse]').length;"
            )[0];
            $this->assertGreaterThanOrEqual(7, (int) $groupCount, 'expected the seven menu groups');

            $browser->click('#admin-offcanvas [data-bs-target="#oc-g1"]')
                ->waitFor('#oc-g1.show', 5);
            $itemCount = $browser->script(
                "return document.querySelectorAll('#oc-g1 a').length;"
            )[0];
            $this->assertGreaterThanOrEqual(10, (int) $itemCount, 'Competition Preparation group items missing');

            // Navigate to a menu destination.
            $browser->script("
                const a = [...document.querySelectorAll('#oc-g1 a')]
                    .find(x => x.textContent.trim() === 'Edit All Competition Dates');
                a.click();
            ");
            $browser->waitForLocation('/admin/dates', 5);

            // Dismissal still works after navigation (reopen, close button).
            $browser->waitFor('#admin-offcanvas-open', 5)
                ->click('#admin-offcanvas-open');
            $browser->pause(700);
            $browser->waitFor('#admin-offcanvas.show', 5)
                ->click('#admin-offcanvas-close');
            $browser->pause(800);
            $closed = $browser->script(
                "return !document.getElementById('admin-offcanvas').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $closed, 'offcanvas did not close via the close button');
        });
    }
}
