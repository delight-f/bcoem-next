<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Concerns\AssertsBs5Markers;
use Tests\DuskTestCase;

/**
 * Issue-8 gate: the admin session-expiry modals are Bootstrap 5 modals.
 *
 * Asserts both session modals carry BS5 markup (btn-close, data-bs-dismiss,
 * modal-dialog/content) and no BS3 markers (close, data-dismiss, btn-default,
 * role=dialog), and that showing one via the BS5 Modal API reveals it and the
 * Stay Here action dismisses it.
 */
final class AdminSessionModalsBs5Test extends DuskTestCase
{
    use AssertsBs5Markers;

    private const ADMIN_EMAIL = 'smoke.p57@brewingcompetitions.com';

    private const ADMIN_PASS = 'bcoem';

    public function test_session_modals_are_bootstrap5(): void
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

            // Both modals present with BS5 markup, no BS3 markers.
            $state = $browser->script("
                const m120 = document.getElementById('session-expire-warning');
                const m30 = document.getElementById('session-expire-warning-30');
                if (!m120 || !m30) return JSON.stringify({missing: true});
                const html = m120.outerHTML + m30.outerHTML;
                return JSON.stringify({
                  missing: false,
                  noClose: !html.includes('class=\"close\"'),
                  noDataDismiss: !/data-dismiss=/.test(html),
                  hasBtnClose: (html.match(/btn-close/g) || []).length,
                  hasDataBsDismiss: (html.match(/data-bs-dismiss=/g) || []).length,
                  hasBtnSecondary: html.includes('btn-secondary'),
                  noBtnDefault: !html.includes('btn-default'),
                  dialogCentered: (html.match(/modal-dialog-centered/g) || []).length,
                });
            ")[0];
            $data = json_decode((string) $state, true);
            $this->assertFalse($data['missing'] ?? true, 'one session modal is missing');
            $this->assertTrue($data['noClose'], 'BS3 .close button still on a session modal');
            $this->assertTrue($data['noDataDismiss'], 'BS3 data-dismiss still on a session modal');
            $this->assertGreaterThanOrEqual(2, (int) $data['hasBtnClose'], 'btn-close missing');
            $this->assertGreaterThanOrEqual(6, (int) $data['hasDataBsDismiss'], 'data-bs-dismiss missing');
            $this->assertTrue($data['hasBtnSecondary'], 'btn-secondary (Stay Here) missing');
            $this->assertTrue($data['noBtnDefault'], 'btn-default still present');
            $this->assertGreaterThanOrEqual(2, (int) $data['dialogCentered'], 'modal-dialog-centered missing');

            // Show the 2-minute modal via the BS5 Modal API; it reveals with a
            // backdrop, and Stay Here (data-bs-dismiss) closes it.
            $browser->script("
                bootstrap.Modal.getOrCreateInstance('#session-expire-warning').show();
            ");
            $browser->pause(900);
            $shown = $browser->script("
                const m120 = document.getElementById('session-expire-warning');
                return m120.classList.contains('show') && getComputedStyle(m120).visibility === 'visible';
            ")[0];
            $this->assertTrue((bool) $shown, 'session modal did not reveal via BS5 Modal API');

            $browser->script("
                const btn = [...document.querySelectorAll('#session-expire-warning button')]
                    .find(b => b.textContent.trim() === 'Stay Here');
                btn.click();
            ");
            $browser->pause(900);
            $closed = $browser->script(
                "return !document.getElementById('session-expire-warning').classList.contains('show');"
            )[0];
            $this->assertTrue((bool) $closed, 'Stay Here did not dismiss the session modal');
        });
    }
}
