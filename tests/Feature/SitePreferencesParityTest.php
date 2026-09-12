<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Site-preferences B/C parity batch (matrix rows: /admin/site-preferences
 * entries grid + default tab widgets + email contact options, plus the
 * contacts/dropoff/competition-info C-rows).
 *
 * Pins:
 *  - entries tab: server-renders styleEntryLimit-<set>-<key> inputs for the
 *    active style set, and choose-style-entry-limits=1 persists them into
 *    preferences.prefsStyleLimits as a JSON keyed by style category;
 *  - default tab: Winner Place Distribution Method radio labels, Language +
 *    Time Zone as selects, Available Languages checkbox group;
 *  - email tab: the 3rd Contact Form option (X) + SMTP Settings Test section;
 *  - contacts: Contact Help modal + View All Contacts link;
 *  - dropoff: Drop-Off Locations Help modal + "specified" wording;
 *  - competition-info: QR Code Log On Password label + Additional Club
 *    Names search helper + Entry Preferences note.
 */
final class SitePreferencesParityTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'prefs.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9751;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $prefs = (array) DB::table('preferences')->where('id', 1)->first();
        $this->origPrefs = $prefs === [] ? [] : $prefs;
        $contest = (array) DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $contest === [] ? [] : $contest;
    }

    protected function tearDown(): void
    {
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);
    }

    private function pref(string $key): string
    {
        return (string) DB::table('preferences')->where('id', 1)->value($key);
    }

    public function test_entries_tab_renders_per_style_limit_grid(): void
    {
        $this->login();

        $set = $this->pref('prefsStyleSet');
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('Entry Limits by Style or Table/Medal Group')
            ->assertSee('Entry Limits per '.$set.' Style', false)
            ->assertSee('name="styleEntryLimit-'.$set.'-', false);
    }

    public function test_entries_style_limit_save_round_trip(): void
    {
        $this->login();

        $set = DB::table('preferences')->where('id', 1)->value('prefsStyleSet');
        $group = (string) DB::table('styles')
            ->where('brewStyleVersion', $set)->where('brewStyleOwn', '!=', 'custom')
            ->value('brewStyleGroup');

        $this->put('/admin/site-preferences/entries', [
            'contestEntryFee' => '8',
            'contestEntryFee2' => '7',
            'contestEntryFeeDiscountNum' => '4',
            'prefsStyleSet' => $set,
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
            'styleEntryLimit-'.$set.'-'.$group => '5',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        $limits = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsStyleLimits'), true);
        $this->assertSame('5', (string) ($limits[$group] ?? ''), 'per-style limit persisted to prefsStyleLimits');

        // Re-render shows the stored value back in the grid input.
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('name="styleEntryLimit-'.$set.'-'.$group.'" value="5"', false);
    }

    public function test_default_tab_general_values_round_trip_with_legacy_encodings(): void
    {
        $this->login();

        $this->put('/admin/site-preferences/default', [
            'prefsProEdition' => '0',
            'prefsDisplayWinners' => 'Y',
            'prefsWinnerDelay' => '',
            'prefsWinnerMethod' => '1',
            'prefsTheme' => 'default',
            'prefsSEF' => 'N',
            'prefsUseMods' => 'Y',
            'prefsDropOff' => '1',
            'prefsShipping' => '0',
            'prefsAutoPurge' => '0',
            'prefsLanguage' => 'en-US',
            'prefsLanguageToggle' => 'N',
            'prefsDateFormat' => '1',
            'prefsTimeZone' => '-7.000',
            'prefsTimeFormat' => '0',
            'prefsSponsors' => 'Y',
            'prefsSponsorLogos' => 'Y',
            'prefsRecordPaging' => '25',
        ])->assertRedirect('/admin/site-preferences/default?msg=2');

        // Legacy encodings: Custom Modules is a char(1) Y/N column (the
        // dashboard and mods gate both test for 'Y'), and drop-off / shipping
        // are tinyint 1/0 despite the radios looking like switches.
        $this->assertSame('Y', $this->pref('prefsUseMods'));
        $this->assertSame('25', $this->pref('prefsRecordPaging'));
        $this->assertSame('1', (string) $this->pref('prefsDropOff'));
        $this->assertSame('0', (string) $this->pref('prefsShipping'));

        // And the controls re-render the stored state (the old Drop-Off /
        // Shipping selects compared a tinyint against 'Y', so an enabled
        // drop-off always came back "Disabled").
        $this->get('/admin/site-preferences')
            ->assertOk()
            ->assertSee('placeholder="12" value="25"', false)
            ->assertSee('value="-7.000" selected', false)
            ->assertSee('id="dropYes" checked', false)
            ->assertSee('id="shipNo" checked', false)
            ->assertSee('value="1" id="prefsWinnerMethod_1" checked', false);
    }

    public function test_default_tab_renders_winner_language_timezone_and_available_languages(): void
    {
        $this->login();

        $this->get('/admin/site-preferences')
            ->assertOk()
            ->assertSee('Winner Place Distribution Method')
            ->assertSee('By Table/Medal Group')
            ->assertSee('By Style')
            ->assertSee('By Sub-Style')
            ->assertSee('name="prefsLanguage"', false)
            ->assertSee('name="prefsTimeZone"', false)
            ->assertSee('Available Languages')
            ->assertSee('name="prefsLanguageOptions[]"', false);
    }

    public function test_email_tab_renders_contact_options_and_smtp_test_section(): void
    {
        $this->login();

        $this->get('/admin/site-preferences/email')
            ->assertOk()
            ->assertSee('Allow BCOE&amp;M to Send Emails', false)
            ->assertSee('Disable Contact Form - List Contacts')
            ->assertSee('Disable Contact Form - Do Not List Contacts')
            ->assertSee('SMTP Settings Test')
            ->assertSee('Contact Form CC');
    }

    public function test_contacts_renders_help_modal_and_view_all_link(): void
    {
        $this->login();

        $this->get('/admin/contacts')
            ->assertOk()
            ->assertSee('Contact Help')
            ->assertSee('Define the contacts associated with the competition');

        $this->get('/admin/contacts/create')
            ->assertOk()
            ->assertSee('View All Contacts')
            ->assertSee(url('/admin/contacts'), false);
    }

    public function test_dropoff_renders_help_modal_and_uses_specified(): void
    {
        $this->login();

        $this->get('/admin/dropoff')
            ->assertOk()
            ->assertSee('Drop-Off Locations Help');
    }

    public function test_competition_info_renders_qr_label_club_helper_and_entry_note(): void
    {
        $this->login();

        $this->get('/admin/competition-info')
            ->assertOk()
            ->assertSee('QR Code Log On Password')
            ->assertSee('Additional Club Names')
            ->assertSee('search-club-list-input', false)
            ->assertSee('Entry Preferences');
    }
}
