<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

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

        $this->post('/login', [
            'loginUsername' => self::LOGIN,
            'loginPassword' => 'bcoem',
        ]);
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
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => self::LOGIN,
            'loginPassword' => 'bcoem',
        ]);

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

    public function test_pay_renders_account_surface_with_paypal_modal(): void
    {
        $this->get('/pay')
            ->assertOk()
            // Same account block as /list (index.pub.php shares list.pub.php).
            ->assertSee('My Account')
            ->assertSee('id="entries"', false)
            // PayPal confirmation modal ($pay_modal).
            ->assertSee('confirm-submit')
            ->assertSee('Return To Merchant');
    }

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

        $row = DB::table('users')->find(1);
        $this->assertTrue(password_verify('brand-new-pw', (string) $row->password));
        $this->assertTrue(
            (string) $row->userCreated >= $before && (string) $row->userCreated <= $after,
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
            ->assertRedirect('/list/edit-account');
    }

    private function closeEntryWindow(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => 946684800,     // 2000-01-01
            'contestEntryDeadline' => 978307200, // 2001-01-01
        ]);
    }
}
