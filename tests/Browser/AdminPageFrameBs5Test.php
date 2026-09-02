<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-7 gate: the admin page frame is Bootstrap 5 themed.
 *
 * Asserts the rendered admin surface applies the brux palette through the
 * BS5 theme attribute (data-bs-theme="bcoem-brux"), has no bare daisy
 * data-theme attribute, uses the BS5 container wrapper, and keeps its
 * fixed dark footer and brux topbar.
 */
final class AdminPageFrameBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_admin_page_frame_is_bootstrap5_themed(): void
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

            // No bare daisy data-theme anywhere; BS5 theme attr present.
            $html = $browser->driver->getPageSource();
            $this->assertStringNotContainsString('data-theme=', $html, 'bare daisy data-theme on the admin page');
            $this->assertStringContainsString(
                'data-bs-theme="bcoem-brux"',
                $html,
                'admin page lacks the BS5 brux theme attribute'
            );

            // Frame marker gate: the chrome shell (topbar, offcanvas, footer,
            // main wrapper element itself) carries no BS3/daisy markers.
            // Dashboard BODY content (panel modals etc.) is later-batch scope.
            $outer = $browser->script("
                const shell = [
                    document.querySelector('.admin-topbar').outerHTML,
                    document.getElementById('admin-offcanvas').outerHTML,
                    document.querySelector('footer.site-footer').outerHTML,
                    document.getElementById('main-content').outerHTML.match(/<div[^>]*id=\"main-content\"[^>]*>/)[0],
                ].join('|');
                return shell;
            ")[0];
            foreach (['panel', 'navbar-inverse', 'btn-default', 'modal-box', 'data-theme='] as $legacy) {
                $this->assertStringNotContainsString($legacy, (string) $outer, "legacy marker '{$legacy}' in the admin frame");
            }

            // BS5 container wrapper + fixed footer + brux topbar gradient.
            $state = $browser->script("
                const mc = document.getElementById('main-content');
                const ft = document.querySelector('footer.site-footer');
                const tb = document.querySelector('.admin-topbar');
                return JSON.stringify({
                  mainClass: mc ? mc.className : null,
                  footerPos: ft ? getComputedStyle(ft).position : null,
                  topbarBg: tb ? getComputedStyle(tb).backgroundImage.slice(0, 55) : null,
                });
            ")[0];
            $data = json_decode((string) $state, true);
            $this->assertStringContainsString('container-fluid', $data['mainClass'] ?? '', 'admin main wrapper not container-fluid');
            $this->assertSame('fixed', $data['footerPos'], 'admin footer not fixed');
            $this->assertStringContainsString('rgb(48, 62, 75)', $data['topbarBg'] ?? '', 'brux topbar gradient lost');
        });
    }
}
