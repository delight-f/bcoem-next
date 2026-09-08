<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Issue-12 verification (temp): prove no served view silently depended on a
 * deleted Tailwind/daisy class, by probing rendered computed styles and the
 * BS5 interactions that replace the hand-written shim. No vision used.
 */
final class Issue12ShimGoneProbeTest extends DuskTestCase
{
    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_public_home_layout_intact_after_shim_removal(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/');

            // BS5 mobile-nav structure present (replaces the Tailwind peer hack).
            $nav = $browser->script("
                const nav = document.getElementById('site-nav');
                const toggler = nav.querySelector('button[data-bs-toggle=\"collapse\"]');
                const menu = document.getElementById('nav-menu');
                return JSON.stringify({
                  navHasNavbar: nav && nav.classList.contains('navbar'),
                  navHasExpand: nav && nav.classList.contains('navbar-expand-md'),
                  toggler: !!toggler,
                  togglerTarget: toggler ? toggler.getAttribute('data-bs-target') : null,
                  menuCollapse: menu && menu.classList.contains('collapse'),
                  menuCollapseNavbar: menu && menu.classList.contains('navbar-collapse'),
                });
            ")[0];
            $nav = json_decode((string) $nav, true);
            $this->assertTrue($nav['navHasNavbar'], 'public nav lost BS5 .navbar');
            $this->assertTrue($nav['navHasExpand'], 'public nav lost navbar-expand-md');
            $this->assertTrue($nav['toggler'], 'public nav lost its BS5 collapse toggler');
            $this->assertSame('#nav-menu', $nav['togglerTarget'], 'toggler target mismatch');
            $this->assertTrue($nav['menuCollapse'], '#nav-menu not a BS5 collapse');
            $this->assertTrue($nav['menuCollapseNavbar'], '#nav-menu not navbar-collapse');

            // Hero + salutation + fixed footer visual invariants.
            $probe = $browser->script("
                const hero = document.getElementById('hero');
                const sal = document.getElementById('salutation');
                const ft = document.querySelector('footer.site-footer');
                return JSON.stringify({
                  heroDisplay: hero ? getComputedStyle(hero).display : null,
                  heroColor: hero ? getComputedStyle(hero).color : null,
                  salBg: sal ? getComputedStyle(sal).backgroundColor : null,
                  footerPos: ft ? getComputedStyle(ft).position : null,
                });
            ")[0];
            $probe = json_decode((string) $probe, true);
            $this->assertSame('flex', $probe['heroDisplay'], 'hero not a flex row (lost d-flex)');
            $this->assertSame('rgb(255, 255, 255)', $probe['heroColor'], 'hero lost white text');
            $this->assertSame('rgb(0, 0, 0)', $probe['salBg'], 'salutation lost black band');
            $this->assertSame('fixed', $probe['footerPos'], 'public footer not fixed');
        });
    }

    public function test_admin_dashboard_behavior_intact_after_shim_removal(): void
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

            // Fixed topbar + fixed footer + legacy .mt-6 spacing rule intact
            // (the dashboard itself has no .mt-6 element, so verify the rule
            // resolves to 1.5rem on a probe element with the class).
            $probe = $browser->script("
                const tb = document.querySelector('.admin-topbar');
                const ft = document.querySelector('footer.site-footer');
                const probe = document.createElement('div');
                probe.className = 'mt-6';
                document.body.appendChild(probe);
                const mt6 = getComputedStyle(probe).marginTop;
                probe.remove();
                return JSON.stringify({
                  topbarPos: tb ? getComputedStyle(tb).position : null,
                  footerPos: ft ? getComputedStyle(ft).position : null,
                  mt6MarginTop: mt6,
                  hasMain: !!document.getElementById('main-content'),
                });
            ")[0];
            $probe = json_decode((string) $probe, true);
            $this->assertSame('fixed', $probe['topbarPos'], 'admin topbar not fixed');
            $this->assertSame('fixed', $probe['footerPos'], 'admin footer not fixed');
            $this->assertSame('24px', $probe['mt6MarginTop'], 'legacy .mt-6 spacing (1.5rem) lost');
            $this->assertTrue($probe['hasMain'], 'admin main-content wrapper missing');

            // A real BS5 dropdown (admin user menu) opens via BS5 JS.
            $browser->click('.admin-topbar .dropdown-toggle');
            $browser->pause(350);
            $open = $browser->script("return document.querySelector('.admin-topbar .dropdown-menu')?.classList.contains('show');")[0];
            $this->assertTrue($open, 'BS5 admin dropdown did not open');
            $browser->click('.admin-topbar .dropdown-toggle');
            $browser->pause(350);
        });
    }
}
