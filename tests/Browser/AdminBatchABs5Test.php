<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-9 gate: core admin blades (batch A) are Bootstrap 5 clean.
 *
 * Representative pages from the batch — dashboard, site-preferences,
 * competition-info, dates, contacts, entries, participants — render with no
 * BS3-only / daisy-only markers and carry BS5 markers. A subset also
 * exercises BS5 interaction (dashboard accordion, a daisy modal now opening
 * as a BS5 modal).
 */
final class AdminBatchABs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    /** Batch-A pages: path => expected content substring. */
    private const PAGES = [
        '/admin' => 'Administration Dashboard',
        '/admin/site-preferences' => ': Preferences',
        '/admin/competition-info' => 'Competition',
        '/admin/dates' => 'Dates',
        '/admin/contacts' => 'Contacts',
        '/backoffice/entries' => 'Entries',
        '/backoffice/participants' => 'Participants',
    ];

    public function test_batch_a_pages_carry_no_legacy_markers(): void
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
                $browser->pause(600);

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

    public function test_dashboard_accordion_and_modal_use_bs5_js(): void
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

            // Dashboard accordion: force a collapse closed, click its header,
            // and confirm BS5 JS opens it (.show after the transition).
            $targetId = $browser->script("
                const t = document.querySelector('[data-bs-toggle=collapse]');
                if (!t) return null;
                const target = document.querySelector(t.getAttribute('data-bs-target'));
                target.classList.remove('show');
                t.click();
                return target.id;
            ")[0];
            $this->assertNotNull($targetId, 'no BS5 collapse toggle on the dashboard');
            $browser->pause(1200);
            $nowShown = $browser->script(
                "return document.getElementById('".$targetId."').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $nowShown, 'dashboard collapse did not open under BS5 JS');

            // A dashboard help dialog now opens as a real BS5 modal.
            $helpTarget = $browser->script("
                const h = document.querySelector('a[data-bs-target^=\"#dashboard-help-modal-\"]');
                return h ? h.getAttribute('data-bs-target') : null;
            ")[0];
            $this->assertNotNull($helpTarget, 'dashboard help modal trigger missing');
            $browser->click('a[data-bs-target="'.$helpTarget.'"]');
            $browser->pause(1000);
            $shown = $browser->script(
                "return document.querySelector('".$helpTarget."').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $shown, 'dashboard help modal did not open as a BS5 modal');
        });
    }
}
