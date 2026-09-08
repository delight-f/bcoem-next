<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Auth-aware nav + session (ticket 04): logged-in branch renders My Account,
 * Logout, and admin link (userLevel<=1); anonymous shows Log In.
 */
final class AuthNavTest extends PublicSurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $exists = DB::table('users')->where('id', 1)->exists();
        if ($exists) {
            DB::table('users')->where('id', 1)->update([
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '0',
            ]);
        } else {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => 'user.baseline@brewingcompetitions.com',
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '0',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Admin',
                'brewerEmail' => 'user.baseline@brewingcompetitions.com',
            ]);
        }
    }

    public function test_anonymous_sees_log_in_link(): void
    {
        $this->get('/')->assertSee('Log In');
    }

    public function test_logged_in_sees_my_account_and_logout(): void
    {
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/')->assertSee('My Account')->assertSee('Log Out');
    }

    public function test_admin_sees_admin_link(): void
    {
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/')->assertSee('Admin');
    }

    public function test_logged_in_sees_auto_log_out_countdown(): void
    {
        // pub/nav.pub.php:189 — the user dropdown carries an "Auto Log Out
        // in <span id=session-end>" countdown footer (P3 Slice 7, PARITY-020
        // N6).
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/')
            ->assertSee('Auto Log Out in')
            ->assertSee('session-end', false);
    }

    public function test_judging_dashboard_link_gated_on_eval_and_open(): void
    {
        // pub/nav.pub.php:180-183 (PARITY-020 N4): the link renders only
        // when prefsEval==1 AND the user is an assigned judge
        // (staff.staff_judge==1) AND brewerJudge==Y AND judging is open
        // (now > jPrefsJudgingOpen).
        DB::table('preferences')->where('id', 1)->update(['prefsEval' => 1]);
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsJudgingOpen' => time() - 1000]);
        DB::table('staff')->updateOrInsert(
            ['uid' => 1],
            ['uid' => 1, 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0,
                'staff_organizer' => 0, 'staff_staff' => 0],
        );
        DB::table('brewer')->where('uid', 1)->update(['brewerJudge' => 'Y']);

        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/')->assertSee('Judging Dashboard');

        // Closing judging hides the link.
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsJudgingOpen' => time() + 100000]);
        $this->get('/')->assertDontSee('Judging Dashboard');
    }

    public function test_entrant_does_not_see_admin_link(): void
    {
        DB::table('users')->where('id', 1)->update(['userLevel' => '2']);

        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/')->assertSee('My Account');
        // Admin link only visible for userLevel<=1; check nav specifically
        // to avoid false positives from page body text.
        $this->get('/')->assertDontSee('/?section=admin');
    }

    public function test_list_route_redirects_anonymous_with_msg99(): void
    {
        $this->get('/list')->assertRedirect('/?msg=99');
    }

    public function test_list_route_renders_for_authenticated_user(): void
    {
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/list')->assertOk();
    }

    protected function tearDown(): void
    {
        // test_entrant_does_not_see_admin_link mutates userLevel; restore so
        // later test classes see the admin fixture (shared bcoem_test DB).
        DB::table('users')->where('id', 1)->update(['userLevel' => '0']);

        parent::tearDown();
    }
}
