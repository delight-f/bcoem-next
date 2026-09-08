<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-10 gate: judging/eval/backoffice blades (batch B) are BS5 clean.
 *
 * Representative pages from the batch — judging preferences, tables,
 * locations, check-in, scores, special-best, eval dashboard + scoresheet —
 * render with no BS3-only / daisy-only markers and carry BS5 markers.
 * A subset exercises BS5 interaction (config modal, dropdown).
 */
final class AdminBatchBBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    /** Batch-B pages: path => expected content substring. */
    private const PAGES = [
        '/admin/judging/preferences' => 'Judging',
        '/admin/judging/tables' => 'Table',
        '/admin/judging/locations' => 'Judging Sessions',
        '/admin/dropoff' => 'Drop-Off',
        '/admin/judging/checkin' => 'Check',
        '/admin/judging/scores' => 'Scores',
        '/admin/judging/special-best' => 'Custom Categories',
        '/eval' => 'Evaluation',
    ];

    public function test_batch_b_pages_carry_no_legacy_markers(): void
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

            foreach (self::PAGES as $path => $needle) {
                $browser->visit($path);
                $browser->pause(700);

                $browser->assertSee($needle);
                $html = $browser->driver->getPageSource();

                $bs3 = self::markersInHtml($html, $this->bs3OnlyClasses);
                $this->assertSame([], $bs3, "BS3 markers on {$path}: ".implode(', ', $bs3));

                $daisy = self::markersInHtml($html, $this->daisyOnlyMarkers);
                $this->assertSame([], $daisy, "daisy markers on {$path}: ".implode(', ', $daisy));

                $bs5 = self::markersInHtml($html, $this->bs5Markers);
                $this->assertNotEmpty($bs5, "No BS5 markers on {$path}");
            }
        });
    }

    public function test_judging_config_modal_and_dropdown_use_bs5_js(): void
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

            // Judging preferences: open the queued-judging info modal via BS5.
            $browser->visit('/admin/judging/preferences');
            $browser->pause(700);
            $target = $browser->script("
                const t = document.querySelector('a[data-bs-toggle=modal], button[data-bs-toggle=modal]');
                if (!t) return null;
                t.click();
                return t.getAttribute('data-bs-target');
            ")[0];
            $this->assertNotNull($target, 'no BS5 modal trigger on judging preferences');
            $browser->pause(1000);
            $shown = $browser->script(
                "return document.querySelector('".$target."').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $shown, 'judging prefs modal did not open under BS5 JS');
            $browser->script("
                const c = document.querySelector('".$target." .btn-close');
                if (c) c.click();
            ");
            $browser->pause(800);
            $closed = $browser->script(
                "return !document.querySelector('".$target."').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $closed, 'judging prefs modal did not close via btn-close');

            // Tables: a View... dropdown opens under BS5 data-api.
            $browser->visit('/admin/judging/tables');
            $browser->pause(700);
            $browser->script("
                const d = document.querySelector('[data-bs-toggle=dropdown]');
                if (d) d.click();
            ");
            $browser->pause(800);
            $ddShown = $browser->script("
                return !!document.querySelector('.dropdown-menu.show');
            ")[0];
            $this->assertTrue((bool) $ddShown, 'tables dropdown did not open under BS5 data-api');
        });
    }
}
