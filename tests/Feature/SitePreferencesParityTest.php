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

    public function test_entries_tab_matches_legacy_structure_and_options(): void
    {
        $this->login();

        $bos = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get();

        $response = $this->get('/admin/site-preferences/entries')->assertOk();

        // Legacy section headings (the port had invented its own).
        $response->assertSee('<h3>Entries</h3>', false)
            ->assertSee('<h4>Fees and Discounts</h4>', false)
            ->assertSee('<h4>Limits</h4>', false);

        // Full bottle/can label list — the port offered only 7 of the 12.
        foreach ([
            'Standard - Larger Printed Number and Style',
            'Standard with Barcode/QR Code',
            'Standard - Larger Printed Number and Style with Barcode/QR Code',
            'Anonymous - Smaller Printed Entry Number',
            'Anonymous - Smaller Printed Entry Number with Barcode/QR Code',
            'Anonymous - Smaller Printed Random Number',
            'Anonymous - Smaller Printed Random Number with Barcode/QR Code',
            'Anonymous - Larger Printed Entry Number',
            'Anonymous - Larger Printed Entry Number with Barcode/QR Code',
            'Anonymous - Larger Printed Random Number',
            'Anonymous - Larger Printed Random Number with Barcode/QR Code',
        ] as $label) {
            $response->assertSee($label, false);
        }
        $response->assertSee('optgroup label="Print Multiple Entries at a Time"', false);

        // Restored control types: the limit method is a radio group again, and
        // the per-participant limits are selects (legacy 1..25 / 1..100).
        $response->assertSee('type="radio" name="choose-style-entry-limits" value="1"', false)
            ->assertSee('name="prefsUserEntryLimit"', false)
            ->assertSee('name="prefsUserSubCatLimit"', false)
            ->assertSee('name="prefsUSCLExLimit"', false)
            ->assertDontSee('name="choose-style-entry-limits" style', false);

        // Restored fields the port never rendered: per-BOS-type limits, the
        // incremental tiers, and the per-sub-style exception checkboxes.
        $response->assertSee('name="style_type_entry_limits"', false)
            ->assertSee('name="prefsUSCLEx[]"', false)
            ->assertSee('name="user-entry-limit-number-1"', false)
            ->assertSee('name="user-entry-limit-expire-days-1"', false)
            ->assertSee('name="user-entry-limit-number-4"', false)
            ->assertSee('id="sub-style-list"', false);

        foreach ($bos as $st) {
            $response->assertSee('name="styleTypeEntryLimit-'.$st->id.'"', false);
        }
    }

    public function test_entries_bulky_lists_collapse_and_exceptions_are_filterable(): void
    {
        $this->login();

        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            // The ~150-row per-style grid starts collapsed behind a toggle.
            ->assertSee('data-bs-target="#style-limits-list"', false)
            ->assertSee('class="collapse" id="style-limits-list"', false)
            // Both toggles are full-size solid buttons, not the old unstyled
            // small ones (btn-default is not a BS5 class, so it had no styling).
            ->assertSee('class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#style-limits-list"', false)
            ->assertSee('class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#sub-style-list"', false)
            ->assertDontSee('btn-sm btn-default', false)
            // The exception picker is filterable/grouped, not a bare wall.
            ->assertSee('id="usclExFilter"', false)
            ->assertSee('id="usclExAll"', false)
            ->assertSee('id="usclExNone"', false)
            ->assertSee('id="usclExCount"', false)
            ->assertSee('class="uscl-ex-group"', false);
    }

    public function test_entries_per_participant_dropdowns_are_capped(): void
    {
        $this->login();

        $html = (string) $this->get('/admin/site-preferences/entries')->assertOk()->getContent();

        // Deliberate divergence from legacy (which reached 25/25/100/25/60):
        // the per-participant dropdowns stop at 10, the incremental days at 30.
        foreach ([
            'prefsUserEntryLimit' => 10,
            'prefsUserSubCatLimit' => 10,
            'prefsUSCLExLimit' => 10,
            'user-entry-limit-number-1' => 10,
            'user-entry-limit-expire-days-1' => 30,
        ] as $name => $max) {
            preg_match('/name="'.preg_quote($name, '/').'".*?<\/select>/s', $html, $m);
            self::assertNotEmpty($m, $name.' select not found');
            self::assertStringContainsString('<option value="'.$max.'"', $m[0], $name.' should offer '.$max);
            self::assertStringNotContainsString('<option value="'.($max + 1).'"', $m[0], $name.' should not exceed '.$max);
        }
    }

    public function test_entries_incremental_tiers_reveal_progressively_without_blue_days(): void
    {
        $this->login();

        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            // Tiers are addressable for the legacy progressive-reveal script.
            ->assertSee('id="user-entry-limit-increment-1"', false)
            ->assertSee('id="user-entry-limit-increment-4"', false)
            ->assertSee('var tiers = [1, 2, 3, 4].map', false)
            // Legacy's blue "Days" emphasis is dropped.
            ->assertDontSee('<span class="text-primary">Days</span>', false)
            ->assertSee('#1 Incremental Entry Limit per Participant Days', false);
    }

    public function test_entries_limit_method_is_a_stacked_option_list(): void
    {
        $this->login();

        // Was three inline radios followed by three stacked help paragraphs —
        // a wall of text. Now each method is its own row: title + one-line
        // description, with the caution as a single compact note.
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('<strong class="d-block">Disable</strong>', false)
            ->assertSee('<strong class="d-block">Enable By Table or Medal Group</strong>', false)
            ->assertSee('<strong class="d-block">Enable By Style</strong>', false)
            ->assertSee('No limit on how many entries a participant may submit.', false)
            ->assertSee('Requires <strong>Tables Planning Mode</strong>', false)
            ->assertSee('Set a numerical limit on overall styles or style groups in the grid below.', false)
            ->assertSee('changing the method deletes any limits set under the previous one', false);
    }

    public function test_preferences_forms_use_the_roomier_form_text_rhythm(): void
    {
        $this->login();

        // The blade scopes a spacing block to .site-preferences; without it
        // Bootstrap's .form-text sits .25rem under its control (and is inline,
        // so consecutive help fragments run together).
        $this->get('/admin/site-preferences')
            ->assertOk()
            ->assertSee('landing-page-section site-preferences', false)
            ->assertSee('.site-preferences .form-text { display: block; margin-top: .5rem; }', false)
            ->assertSee('.site-preferences .form-text .btn { margin-top: .5rem; }', false);

        // Incremental tiers are separated by a rule, not crammed together.
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('class="border-top pt-3">', false);
    }

    public function test_entries_style_type_limits_round_trip(): void
    {
        $this->login();

        $bos = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get();
        $first = $bos->first();
        $original = DB::table('style_types')->pluck('styleTypeEntryLimit', 'id')->all();

        try {
            $this->put('/admin/site-preferences/entries', [
                'prefsStyleSet' => $this->pref('prefsStyleSet'),
                'prefsEntryForm' => '7',
                'prefsSpecific' => '0',
                'prefsSpecialCharLimit' => '150',
                'choose-style-entry-limits' => '1',
                'style_type_entry_limits' => $bos->pluck('id')->implode(','),
                'styleTypeEntryLimit-'.$first->id => '4',
            ])->assertRedirect('/admin/site-preferences/entries?msg=2');

            self::assertSame('4', (string) DB::table('style_types')->where('id', $first->id)->value('styleTypeEntryLimit'));

            $this->get('/admin/site-preferences/entries')
                ->assertOk()
                ->assertSee('name="styleTypeEntryLimit-'.$first->id.'" type="number" min="0" style="width:auto;" value="4"', false);
        } finally {
            foreach ($original as $id => $value) {
                DB::table('style_types')->where('id', $id)->update(['styleTypeEntryLimit' => $value]);
            }
        }
    }

    public function test_entries_incremental_and_exception_limits_round_trip(): void
    {
        $this->login();

        $styleId = (int) DB::table('styles')->where('brewStyleVersion', $this->pref('prefsStyleSet'))->value('id');

        $this->put('/admin/site-preferences/entries', [
            'prefsStyleSet' => $this->pref('prefsStyleSet'),
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
            'prefsUserEntryLimit' => '12',
            'prefsUserSubCatLimit' => '3',
            'prefsUSCLExLimit' => '90',
            'prefsUSCLEx' => [(string) $styleId],
            'user-entry-limit-number-1' => '5',
            'user-entry-limit-expire-days-1' => '10',
            'user-entry-limit-number-2' => '8',
            'user-entry-limit-expire-days-2' => '20',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        self::assertSame('12', $this->pref('prefsUserEntryLimit'));
        self::assertSame('3', $this->pref('prefsUserSubCatLimit'));
        self::assertSame('90', $this->pref('prefsUSCLExLimit'));
        self::assertSame((string) $styleId, $this->pref('prefsUSCLEx'));

        $tiers = json_decode($this->pref('prefsUserEntryLimitDates'), true);
        self::assertSame(['limit-number' => '5', 'limit-days' => '10'], $tiers['1']);
        self::assertSame(['limit-number' => '8', 'limit-days' => '20'], $tiers['2']);

        // The re-render reflects the stored tiers and the checked exception.
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('name="prefsUSCLEx[]" value="'.$styleId.'"', false)
            ->assertSee('id="user-entry-limit-number-1"', false)
            ->assertSee('value="5" selected', false);
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

    /**
     * The payment tab's own dropdown offered currencies that its validation
     * rejected: prefsCurrency was capped at max:5, so czkoruna/phpeso/sfranc/
     * shekel (all in the legacy list; the column is varchar(20)) could never
     * be saved.
     */
    public function test_payment_tab_accepts_every_offered_currency(): void
    {
        $this->login();

        // Scrape the offered option values so the test can't drift from the view.
        $html = (string) $this->get('/admin/site-preferences/payment')->assertOk()->getContent();
        self::assertSame(1, preg_match('/<select[^>]*name="prefsCurrency"[^>]*>(.*?)<\/select>/s', $html, $m));
        preg_match_all('/<option value="([^"]*)"/', $m[1], $opts);
        $currencies = $opts[1];
        self::assertNotEmpty($currencies);

        foreach ($currencies as $curr) {
            $this->put('/admin/site-preferences/payment', [
                'prefsCurrency' => $curr,
                'prefsPayToPrint' => '0',
                'prefsCash' => '1',
                'prefsCheck' => '0',
                'prefsTransFee' => 'N',
            ])->assertSessionHasNoErrors();

            self::assertSame($curr, (string) DB::table('preferences')->where('id', 1)->value('prefsCurrency'), $curr);
        }
    }

    public function test_best_tab_points_cap_unused_label_and_club_gating(): void
    {
        $this->login();

        $response = $this->get('/admin/site-preferences/best')->assertOk();

        // Per-place help text (legacy has it; the port had dropped it).
        $response->assertSee('Enter the number of points awarded for each first place that an entrant receives.', false)
            ->assertSee('Enter the number of points awarded for each Honorable Mention that an entrant receives.', false);

        // Point dropdowns stop at 9 (legacy offered 0-25).
        $html = (string) $response->getContent();
        foreach (['prefsFirstPlacePts', 'prefsSecondPlacePts', 'prefsThirdPlacePts', 'prefsFourthPlacePts', 'prefsHMPts'] as $field) {
            preg_match('/name="'.preg_quote($field, '/').'".*?<\/select>/s', $html, $m);
            self::assertNotEmpty($m, $field.' select not found');
            self::assertStringContainsString('<option value="9"', $m[0], $field.' should offer 9');
            self::assertStringNotContainsString('<option value="10"', $m[0], $field.' should stop at 9');
        }

        // "Unused" — legacy's en-US string carries a stray period.
        $response->assertSee('>Unused</option>', false)
            ->assertDontSee('>Unused.</option>', false);

        // Amateur edition: clubs are shown and the heading says so.
        $response->assertSee('<h3>Best Brewer and/or Club</h3>', false)
            ->assertSee('id="bestClub"', false);

        // Professional edition: legacy hides the club block and drops the
        // "and/or Club" suffix.
        DB::table('preferences')->where('id', 1)->update(['prefsProEdition' => 1]);
        $this->get('/admin/site-preferences/best')
            ->assertOk()
            ->assertSee('<h3>Best Brewer</h3>', false)
            ->assertSee('id="bestClub" style="display:none;"', false);
    }

    public function test_best_tab_is_grouped_into_sections(): void
    {
        $this->login();

        $this->get('/admin/site-preferences/best')
            ->assertOk()
            // Sub-sections rather than one flat run of label/control rows.
            ->assertSee('<h4>Best Brewer</h4>', false)
            ->assertSee('<h4>Best Club</h4>', false)
            ->assertSee('<h4>Scoring Method</h4>', false)
            ->assertSee('<h4>Points per Place</h4>', false)
            ->assertSee('<h4>Tie Break Rules</h4>', false)
            // Circuit of America hides the BOS question too (legacy parity).
            ->assertSee('id="bos-in-calcs"', false)
            ->assertSee("['bos-in-calcs', 'non-COA-scoring']", false)
            // Point controls are a compact grid; the detailed legacy copy is
            // kept as a per-select tooltip instead of a paragraph per row.
            ->assertSee('<label class="form-label" for="prefsFirstPlacePts">First Place</label>', false)
            ->assertSee('id="prefsFirstPlacePts" title="Enter the number of points awarded for each first place that an entrant receives."', false)
            // Tie-break rules sit two-up and explain that order matters.
            ->assertSee('<label class="form-label" for="prefsTieBreakRule6">Tie Break Rule #6</label>', false)
            ->assertSee('rule #1 first, then #2', false);
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
