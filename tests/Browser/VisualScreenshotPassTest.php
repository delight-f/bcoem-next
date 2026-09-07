<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Issue-13 visual sign-off screenshot pass (PARITY-010 method). Captures the
 * key surfaces at desktop and tablet width for the human visual gate:
 * admin dashboard, a representative admin form (desktop + tablet, proving the
 * BS5 grid tier-shift), a judging/scoresheet page, the public home, and an
 * auth page. Frames land in tools/parity/screenshots/ for the user's review.
 */
final class VisualScreenshotPassTest extends DuskTestCase
{
    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';
    private const ADMIN_PASS = 'bcoem';
    private const SHOT_PREFIX = 'issue13-';

    public function test_issue13_visual_screenshot_pass(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();

            // --- Public home (guest) ---
            $browser->visit('/');
            $browser->pause(900);
            $browser->resize(1600, 1000);
            $browser->driver->executeScript('window.scrollTo(0, 0);');
            $browser->pause(400);
            $browser->screenshot(self::SHOT_DIR.'/public-home-desktop');
            fwrite(STDERR, "\nSHOT: public-home-desktop\n");

            // --- Auth page (login shell shows the login modal on home; the
            //     register page is the auth surface) ---
            $browser->visit('/register');
            $browser->pause(800);
            $browser->screenshot(self::SHOT_DIR.'/auth-register-desktop');
            fwrite(STDERR, "\nSHOT: auth-register-desktop\n");

            // --- Admin login (dashboard) ---
            $browser->visit('/');
            $browser->click('[data-bs-toggle="modal"][data-bs-target="#login-modal"]');
            $browser->waitFor('#login-modal.show', 5);
            $browser->whenAvailable('#login-modal', function (Browser $modal): void {
                $modal->type('loginUsername', self::ADMIN_EMAIL)
                    ->type('loginPassword', self::ADMIN_PASS)
                    ->press('#login-button');
            });
            $browser->assertPathIs('/admin');
            $browser->pause(1000);
            $browser->resize(1600, 1000);
            $browser->driver->executeScript('window.scrollTo(0, 0);');
            $browser->pause(500);
            $browser->screenshot(self::SHOT_DIR.'/admin-dashboard-desktop');
            fwrite(STDERR, "\nSHOT: admin-dashboard-desktop\n");

            // --- Admin form: competition-info at desktop ---
            $browser->visit('/admin/competition-info');
            $browser->pause(900);
            $browser->resize(1600, 1000);
            $browser->driver->executeScript('window.scrollTo(0, 0);');
            $browser->pause(400);
            $browser->screenshot(self::SHOT_DIR.'/admin-form-desktop');
            fwrite(STDERR, "\nSHOT: admin-form-desktop\n");

            // --- Same admin form at tablet width (proves grid tier-shift) ---
            $browser->resize(768, 1024);
            $browser->pause(700);
            $browser->driver->executeScript('window.scrollTo(0, 0);');
            $browser->pause(400);
            $browser->screenshot(self::SHOT_DIR.'/admin-form-tablet');
            fwrite(STDERR, "\nSHOT: admin-form-tablet\n");
            $browser->resize(1600, 1000);
            $browser->pause(300);

            // --- Judging/scoresheet page ---
            // A judging config page is admin-reachable and form-dense.
            $browser->visit('/admin/judging/tables');
            $browser->pause(900);
            $browser->screenshot(self::SHOT_DIR.'/judging-tables-desktop');
            fwrite(STDERR, "\nSHOT: judging-tables-desktop\n");

            $browser->driver->manage()->deleteAllCookies();
            $this->addToAssertionCount(1);
        });
    }
}
