<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Probe the reported regressions: topbar layout, date picker, label text,
 * and label output links.
 */
final class RegressionProbeTest extends DuskTestCase
{
    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';
    private const ADMIN_PASS = 'bcoem';

    public function test_admin_all_dates_date_picker_and_topbar(): void
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
            $browser->visit('/admin/dates');
            $browser->pause(1500);

            $state = $browser->script("
                const dtEl = document.querySelector('.date-time-picker-system');
                const form = dtEl ? dtEl.closest('form') : null;
                const tbNavs = [...document.querySelectorAll('.admin-topbar .navbar-nav')];
                return JSON.stringify({
                  hasPickerInput: !!dtEl,
                  pickerFormDataTime: form ? form.getAttribute('data-time-24hr') : null,
                  flatpickrLoaded: typeof window.flatpickr !== 'undefined',
                  flatpickrInstances: window.flatpickr ? Object.keys(window.flatpickr).length : 0,
                  inputClass: dtEl ? dtEl.className : null,
                  topbarNavCount: tbNavs.length,
                  topbarFlexDir: tbNavs.map(n => getComputedStyle(n).flexDirection),
                  topbarHasExpand: !!document.querySelector('.admin-topbar.navbar-expand'),
                  topbarLinks: [...document.querySelectorAll('.admin-topbar .nav-link')].map(a => a.textContent.trim()).slice(0, 6),
                });
            ")[0];
            fwrite(STDERR, "\nDATE-PICKER+TOPBAR: ".$state."\n");
            $this->addToAssertionCount(1);
        });
    }

    public function test_admin_site_preferences_label_text(): void
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
            $browser->visit('/admin/site-preferences');
            $browser->pause(1500);

            $state = $browser->script("
                const labels = [...document.querySelectorAll('.col-form-label')];
                const entryLimit = labels.find(l => l.textContent.includes('Entry Limit'));
                return JSON.stringify({
                  labelCount: labels.length,
                  hasWarped: labels.some(l => l.textContent.includes('&ndash') || l.textContent.includes('&amp;')),
                  entryLimitText: entryLimit ? entryLimit.textContent : null,
                });
            ")[0];
            fwrite(STDERR, "\nSITE-PREFS: ".$state."\n");
            $this->addToAssertionCount(1);
        });
    }

    public function test_admin_entries_label_links(): void
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
            $browser->visit('/admin/entries');
            $browser->pause(1500);

            $state = $browser->script("
                const links = [...document.querySelectorAll('a')].map(a => ({href: a.getAttribute('href') || '', text: a.textContent.trim()})).filter(l => (l.text.toLowerCase().includes('print') || l.href.includes('/admin/output/')));
                return JSON.stringify({ outputLinks: links.length, details: links.slice(0, 15) });
            ")[0];
            fwrite(STDERR, "\nENTRIES-OUTPUT-LINKS: ".$state."\n");
            $this->addToAssertionCount(1);
        });
    }
}
