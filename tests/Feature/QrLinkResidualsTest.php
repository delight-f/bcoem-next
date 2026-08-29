<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * qr.php link residuals (PARITY-002 tail): the three admin surfaces that
 * link the QR check-in — competition-info help text, site-preferences
 * entry-tab label help, admin off-canvas nav (barcode-form gate).
 */
final class QrLinkResidualsTest extends AdminScreensTestCase
{
    protected function tearDown(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsEntryForm' => '7']);
        parent::tearDown();
    }

    public function test_competition_info_links_qr_check_in(): void
    {
        $this->get('/admin/competition-info')
            ->assertOk()
            ->assertSee('For use with the', false)
            ->assertSee('href="'.url('/qr').'"', false)
            ->assertSee('QR Code Entry Check-In');
    }

    public function test_site_preferences_entries_tab_links_qr_check_in(): void
    {
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('The QR code options are intended to be used with a mobile device and', false)
            ->assertSee('href="'.url('/qr').'"', false);
    }

    public function test_admin_nav_mobile_devices_row_targets_qr(): void
    {
        // Barcode-enabled form id un-hides the check-in nav rows.
        DB::table('preferences')->where('id', 1)->update(['prefsEntryForm' => '5']);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Entry Check-in Via Mobile Devices')
            ->assertSee('href="'.url('/qr').'"', false);
    }
}
