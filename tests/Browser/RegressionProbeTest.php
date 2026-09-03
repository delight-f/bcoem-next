<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Probe the reported regressions: topbar layout, date picker, label text,
 * label output links, and judging-session datetime picker wiring (D1).
 */
final class RegressionProbeTest extends DuskTestCase
{
    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';
    private const ADMIN_PASS = 'bcoem';

    private function login(Browser $browser): Browser
    {
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/')
            ->click('[data-bs-toggle="modal"][data-bs-target="#login-modal"]')
            ->waitFor('#login-modal.show', 5)
            ->whenAvailable('#login-modal', function (Browser $modal): void {
                $modal->type('loginUsername', self::ADMIN_EMAIL)
                    ->type('loginPassword', self::ADMIN_PASS)
                    ->press('#login-button');
            })
            ->assertPathIs('/admin');
    }

    public function test_admin_all_dates_date_picker_and_topbar(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser);
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
            $this->login($browser);
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

    public function test_public_navbar_link_spacing(): void
    {
        // Public (guest) navbar: .nav-link anchors are direct children of
        // #nav-menu.navbar-collapse (no .navbar-nav wrapper), so they need
        // explicit horizontal padding or the items collide
        // ("RulesVolunteersEntryInfo…"). Assert the row actually carries it.
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/');
            $browser->pause(1200);

            $state = $browser->script("
                const links = [...document.querySelectorAll('#site-nav #nav-menu > .nav-link')];
                return JSON.stringify({
                  linkCount: links.length,
                  text: links.map(a => a.textContent.trim()).join(' | '),
                  padLeft: links.map(a => getComputedStyle(a).paddingLeft),
                  padRight: links.map(a => getComputedStyle(a).paddingRight),
                  gap: links.length > 1 ? links[1].getBoundingClientRect().left - links[0].getBoundingClientRect().right : null,
                  revealCards: document.querySelectorAll('.glance-card-bg.reveal-element').length,
                });
            ")[0];
            fwrite(STDERR, "\nPUBLIC-NAV: ".$state."\n");
            $this->addToAssertionCount(1);
        });
    }

    public function test_admin_entries_label_links(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser);
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

    public function test_admin_judging_location_create_datetime_picker(): void
    {
        // D1: the judging-session add form carries the flatpickr class on
        // its two datetime inputs, a data-time-24hr form hook, and the
        // picker actually opens on click.
        $this->browse(function (Browser $browser): void {
            $this->login($browser);
            $browser->pause(900);
            $browser->visit('/admin/judging/locations/create');
            $browser->pause(1200);

            $state = $browser->script("
                const inputs = [...document.querySelectorAll('input.date-time-picker-system')];
                const form = inputs[0] ? inputs[0].closest('form') : null;
                return JSON.stringify({
                  pickerInputCount: inputs.length,
                  ids: inputs.map(i => i.id),
                  pickerFormDataTime: form ? form.getAttribute('data-time-24hr') : null,
                  flatpickrLoaded: typeof window.flatpickr !== 'undefined',
                  prefill: inputs.map(i => i.value),
                });
            ")[0];
            fwrite(STDERR, "\nJUDGING-LOCATION-PICKER: ".$state."\n");
            $this->addToAssertionCount(1);

            // Click the start-date input and confirm the calendar opens.
            $browser->click('#judgingDate');
            $browser->pause(600);
            $open = $browser->script("return JSON.stringify({ calendarOpen: !!document.querySelector('.flatpickr-calendar.open') });")[0];
            fwrite(STDERR, "\nJUDGING-LOCATION-CALENDAR: ".$open."\n");
            $this->addToAssertionCount(1);
        });
    }
}
