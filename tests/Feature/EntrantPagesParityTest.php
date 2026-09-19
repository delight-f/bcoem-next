<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Entrant-side parity batch (matrix rows: /brew gate, /list/edit-account
 * ownership, /list buttons + entries info cards, /pay account surface,
 * change-password page + section=user redirects).
 *
 * Pinned legacy behaviors:
 *   - brew.sec.php:112 — closed entry window suppresses the add-entry form
 *     for entrants (userLevel > 1); admins keep it.
 *   - brewer.sec.php:86/:370 — profile form renders only for the row owner;
 *     everyone else gets "You can only edit your own profile."
 *   - list.pub.php button stack carries Change Password; brewer_entries.pub.php
 *     info cards render once the entry window has opened (bottles required,
 *     edit deadline, confirmed/unpaid counts, fees to pay).
 *   - process_users.inc.php go=password — wrong old password bounces with
 *     ?msg=3; success re-hashes, stamps userCreated to now, lands on /list.
 */
final class EntrantPagesParityTest extends PublicSurfaceTestCase
{
    private const LOGIN = 'user.baseline@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => self::LOGIN,
                'password' => self::HASH,
                'userLevel' => '2',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Entrant',
                'brewerEmail' => self::LOGIN,
            ]);
        } else {
            DB::table('users')->where('id', 1)->update([
                'password' => self::HASH,
                'userLevel' => '2',
            ]);
        }

        $row = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $row === null ? [] : (array) $row;

        // Open entry window by default; individual tests close it.
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
            'contestDropoffOpen' => Date::now()->subDays(1)->getTimestamp(),
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);

        $this->loginWithEmail(self::LOGIN);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewBrewerID', 1)->delete();

        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }

        parent::tearDown();
    }

    public function test_brew_form_is_gated_when_entry_window_closed(): void
    {
        $this->closeEntryWindow();

        // Entrant: lead only, no form, no submit button.
        $this->get('/brew')
            ->assertOk()
            ->assertSee('Adding and editing of entries is not available.', false)
            ->assertDontSee('name="brewName"');
    }

    public function test_admin_keeps_brew_form_after_window_closes(): void
    {
        $this->closeEntryWindow();

        // Admins bypass the legacy gate (userLevel > 1 condition).
        DB::table('users')->where('id', 1)->update(['userLevel' => '0']);
        $this->loginWithEmail(self::LOGIN);

        $this->get('/brew')
            ->assertOk()
            ->assertSee('name="brewName"', false);
    }

    public function test_edit_account_hides_form_for_non_owner(): void
    {
        // Legacy brewer.sec.php:86 — form requires login email == row email.
        DB::table('brewer')->where('uid', 1)->update([
            'brewerEmail' => 'someone.else@brewingcompetitions.com',
        ]);

        $this->get('/list/edit-account')
            ->assertOk()
            ->assertSee('You can only edit your own profile.', false)
            ->assertDontSee('name="brewerFirstName"');

        // Restore ownership → form renders.
        DB::table('brewer')->where('uid', 1)->update(['brewerEmail' => self::LOGIN]);
        $this->get('/list/edit-account')
            ->assertOk()
            ->assertSee('name="brewerFirstName"', false);
    }

    public function test_list_renders_change_password_and_entries_info_cards(): void
    {
        DB::table('brewing')->insert([
            'brewName' => 'Parity Porter',
            'brewCategorySort' => '1',
            'brewCategory' => '1',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '1',
            'brewConfirmed' => '1',
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewJudgingNumber' => '900001',
        ]);

        $this->get('/list')
            ->assertOk()
            // Button stack (pub/list.pub.php $user_edit_links).
            ->assertSee('Change Password')
            ->assertSee(url('/user/password'))
            ->assertSee('Add Entry')
            // Entries info cards (brewer_entries.pub.php $page_info1).
            ->assertSee('Confirmed Entries:')
            ->assertSee('Unpaid Confirmed Entries:')
            ->assertSee('Entry Fees to Pay:')
            ->assertSee('Entry Edit Deadline:');
    }

    /**
     * The pay button must read as a button only when it IS one: greyed
     * (btn-secondary) and disabled with an explanatory tooltip when nothing is
     * collectable, blue (btn-primary) otherwise. A disabled btn-primary only
     * drops to 65% opacity, so it still looked clickable.
     */
    public function test_pay_button_is_greyed_out_and_explained_when_nothing_is_payable(): void
    {
        // A real per-entry fee exists; this entrant simply owes nothing.
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '10.00']);
        DB::table('brewing')->where('brewBrewerID', 1)->delete();

        $this->get('/list')
            ->assertOk()
            ->assertSee('btn-secondary hide-loader disabled', false)
            ->assertSee('No fees are payable.', false)
            ->assertDontSee('btn-primary hide-loader', false);
    }

    public function test_pay_button_is_blue_when_fees_are_payable(): void
    {
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '10.00']);
        DB::table('brewing')->where('brewBrewerID', 1)->delete();
        DB::table('brewing')->insert([
            'brewName' => 'Parity Unpaid Entry',
            'brewCategorySort' => '1',
            'brewCategory' => '1',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '1',
            'brewConfirmed' => '1',
            'brewPaid' => '0',
            'brewReceived' => '1',
            'brewJudgingNumber' => '900002',
        ]);

        $this->get('/list')
            ->assertOk()
            ->assertSee('btn-primary hide-loader', false)
            ->assertDontSee('btn-secondary hide-loader', false)
            ->assertDontSee('No fees are payable.', false);
    }

    /**
     * The sticky mobile Add Entry button always renders, so a faded
     * btn-primary still promised an action it could not perform once the entry
     * window closed. It is greyed (btn-secondary) and disabled with a reason
     * whenever the entry window is not open, and blue only when it is.
     */
    public function test_add_entry_button_is_greyed_out_and_explained_when_entry_closed(): void
    {
        $this->closeEntryWindow();

        $this->get('/list')
            ->assertOk()
            ->assertSee('btn-secondary disabled', false)
            ->assertSee('Entry registration has closed.', false)
            ->assertDontSee('btn-primary" href="'.url('/brew?filter=1').'"', false);
    }

    public function test_add_entry_button_is_blue_when_entry_window_open(): void
    {
        $this->get('/list')
            ->assertOk()
            ->assertSee('btn-primary" href="'.url('/brew?filter=1').'"', false)
            ->assertDontSee('btn-secondary disabled', false)
            ->assertDontSee('Entry registration has closed.', false);
    }

    public function test_add_entry_button_explains_entry_registration_not_yet_open(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->addDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(9)->getTimestamp(),
        ]);

        $this->get('/list')
            ->assertOk()
            ->assertSee('btn-secondary disabled', false)
            ->assertSee('Entry registration has not opened yet.', false);
    }

    public function test_pay_renders_account_surface(): void
    {
        // The legacy PayPal $pay_modal ("Return to Merchant" / confirm-submit)
        // was retired with PayPal itself in favour of Stripe Checkout
        // (docs/plans/payments-stripe-2026.md W3 — a hosted redirect needs no
        // leave-site coaching), so /pay carries the shared account block only.
        $this->get('/pay')
            ->assertOk()
            // Same account block as /list (index.pub.php shares list.pub.php).
            ->assertSee('My Account')
            ->assertSee('id="entries"', false);
    }

    #[Group('slow')]
    public function test_change_password_flow(): void
    {
        // Wrong old password → back with ?msg=3.
        $this->from('/user/password')->post('/user/password', [
            'passwordOld' => 'wrong-old',
            'password' => 'brand-new-pw',
        ])->assertRedirect('/user/password?msg=3');

        // Success: re-hash + userCreated stamp + land on /list edited-ok.
        $before = date('Y-m-d H:i:s');
        $this->post('/user/password', [
            'passwordOld' => 'bcoem',
            'password' => 'brand-new-pw',
        ])->assertRedirect('/list?msg=2');
        $after = date('Y-m-d H:i:s');

        $row = (array) DB::table('users')->find(1);
        self::assertNotEmpty($row);
        $this->assertTrue(password_verify('brand-new-pw', (string) $row['password']));
        $this->assertTrue(
            (string) $row['userCreated'] >= $before && (string) $row['userCreated'] <= $after,
            'legacy stamps userCreated with the change time',
        );

        // Old password no longer authenticates; restore for tearDown safety.
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => self::LOGIN,
            'loginPassword' => 'brand-new-pw',
        ])->assertRedirect('/list');
        DB::table('users')->where('id', 1)->update(['password' => self::HASH]);
    }

    public function test_section_user_redirects_to_port_surfaces(): void
    {
        $this->get('/index.php?section=user&go=account&action=password&id=1')
            ->assertRedirect('/user/password');
        $this->get('/index.php?section=user&go=account&action=username&id=1')
            ->assertRedirect('/user/username?id=1');
    }

    private function closeEntryWindow(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => 946684800,     // 2000-01-01
            'contestEntryDeadline' => 978307200, // 2001-01-01
        ]);
    }
}
