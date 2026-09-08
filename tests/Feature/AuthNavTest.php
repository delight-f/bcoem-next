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
