<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-4 gate: the public-facing surface carries no daisyUI-only markers
 * and renders Bootstrap-5 markers (the migration gate for the daisy purge).
 *
 * Each test asserts the full marker gate over a representative public page:
 * home, contact, the auth shell (login page), register, and the public
 * account surface. A page flips green once its batch removes the residual
 * daisy tokens — that is the machine gate issue 2 defined and issue 4 turns
 * green for the public surface.
 *
 * The login modal (opened from the guest nav) is asserted separately: it is
 * the one interactive BS5 component on the public shell and must open with
 * real Bootstrap 5 modal markup (data-bs-* triggers, .modal-content).
 */
final class PublicBs5GateTest extends DuskTestCase
{
    use AssertsBs5Markers;

    public function test_public_home_is_bootstrap5_clean(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/');

            $html = $browser->driver->getPageSource();
            $found = self::markersInHtml($html, $this->daisyOnlyMarkers);
            $this->assertSame([], $found, 'daisyUI markers on public home: '.implode(', ', $found));
            $this->assertNotEmpty(
                self::markersInHtml($html, $this->bs5Markers),
                'No Bootstrap-5 markers on public home.'
            );
        });
    }

    public function test_contact_page_is_bootstrap5_clean(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/contact');

            $html = $browser->driver->getPageSource();
            $found = self::markersInHtml($html, $this->daisyOnlyMarkers);
            $this->assertSame([], $found, 'daisyUI markers on contact: '.implode(', ', $found));
            $this->assertNotEmpty(
                self::markersInHtml($html, $this->bs5Markers),
                'No Bootstrap-5 markers on contact.'
            );
        });
    }

    public function test_auth_pages_are_bootstrap5_clean(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();

            foreach (['/login', '/register', '/forgot-password'] as $path) {
                $browser->visit($path);

                $html = $browser->driver->getPageSource();
                $found = self::markersInHtml($html, $this->daisyOnlyMarkers);
                $this->assertSame(
                    [],
                    $found,
                    "daisyUI markers on {$path}: ".implode(', ', $found)
                );
                $this->assertNotEmpty(
                    self::markersInHtml($html, $this->bs5Markers),
                    "No Bootstrap-5 markers on {$path}."
                );
            }
        });
    }

    public function test_login_modal_is_bootstrap5_markup(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/');

            // Open the login modal from the guest nav (BS5 data-api trigger).
            $browser->click('[data-bs-toggle="modal"][data-bs-target="#login-modal"]')
                ->waitFor('#login-modal.show', 5);

            $html = $browser->driver->getPageSource();
            $found = self::markersInHtml($html, $this->daisyOnlyMarkers);
            $this->assertSame(
                [],
                $found,
                'daisyUI markers in open login modal page: '.implode(', ', $found)
            );
            // The modal is real BS5 markup: a content shell + close button.
            $this->assertStringContainsString('modal-content', $html, 'modal-content missing');
            $this->assertStringContainsString('btn-close', $html, 'btn-close missing');
            $this->assertStringContainsString('data-bs-dismiss="modal"', $html, 'BS5 dismiss missing');
        });
    }
}
