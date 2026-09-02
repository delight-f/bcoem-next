<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Proof-of-harness for the BS5-migration marker gate (issue 2).
 *
 * This is intentionally migration-state-independent: it does NOT assert a
 * page is BS5-clean (most pages still carry BS3/daisy markers until the
 * migration batches 3-12 land). Instead it proves the things every later
 * migration test will rely on:
 *
 *   1. Dusk boots Firefox via geckodriver against the running app (port 8000).
 *   2. A real admin session can be established through the actual login dialog.
 *   3. The marker detector reads the real rendered DOM.
 *   4. Pre-migration admin/judging pages genuinely carry BS3/daisy markers,
 *      and the detector flags them — the exact state the migration batches
 *      flip to green.
 *
 * The migration batches add their own `assertPageIsBootstrap5()` tests per
 * page once that page is ported; those flip green as the work lands.
 *
 * Detector correctness (BS3/BS5 shared-class false positives, data-theme vs
 * data-bs-theme) is unit-tested in Tests\Unit\Bs5MarkersTest.
 *
 * Tests share one Firefox session within a process, so each method that
 * needs a fresh logged-out home clears cookies first to expose the login
 * trigger.
 */
final class Bs5MarkerHarnessTest extends DuskTestCase
{
    use AssertsBs5Markers;

    /** Smoke admin seeded in the review_scabs dev DB (level 0). */
    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_detector_runs_over_public_home_dom(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            // Home is a public, unauthenticated page (no login needed).
            $browser->visit('/');

            // Plumbing smoke: the public home renders real DOM and the
            // detector runs over it without error.
            $html = $browser->driver->getPageSource();
            $this->assertNotEmpty($html);
            $this->assertIsArray(self::markersInHtml($html, $this->bs3OnlyClasses));
            $this->assertIsArray(self::markersInHtml($html, $this->daisyOnlyMarkers));
        });
    }

    public function test_admin_dashboard_carries_bs3_markers_pre_migration(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsSmokeAdmin($browser);

            // The /admin dashboard is pre-migration: it still carries BS3
            // chrome. The detector must flag at least one BS3 marker in the
            // real rendered DOM — the gate the migration flips to green.
            $found = self::markersInHtml(
                $browser->driver->getPageSource(),
                $this->bs3OnlyClasses
            );
            $this->assertNotEmpty(
                $found,
                'Expected the pre-migration /admin dashboard to carry BS3 markers; none detected.'
            );
        });
    }

    public function test_judging_surface_carries_daisy_markers_pre_migration(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsSmokeAdmin($browser);

            // A representative judging surface (tables/assign page).
            $browser->visit('/admin/judging/tables')
                ->assertSee('Admin');

            $found = self::markersInHtml(
                $browser->driver->getPageSource(),
                $this->daisyOnlyMarkers
            );
            $this->assertNotEmpty(
                $found,
                'Expected the pre-migration judging tables page to carry daisy markers; none detected.'
            );
        });
    }

    /**
     * Log in as the smoke admin through the real login dialog, landing on the
     * admin dashboard.
     */
    private function loginAsSmokeAdmin(Browser $browser): void
    {
        // Tests share one Firefox session within the process; clear cookies so
        // we start logged-out and the login trigger is present.
        $browser->driver->manage()->deleteAllCookies();
        $browser->visit('/')
            ->click('[data-open-modal="login-modal"]')
            ->whenAvailable('#login-modal', function (Browser $modal): void {
                $modal->type('loginUsername', self::ADMIN_EMAIL)
                    ->type('loginPassword', self::ADMIN_PASS)
                    ->press('#login-button');
            })
            // A level-0 admin lands on the admin dashboard.
            ->assertPathIs('/admin');
    }
}
