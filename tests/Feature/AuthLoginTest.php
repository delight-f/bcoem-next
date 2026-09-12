<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;

/**
 * Auth bootstrap (ticket 01): login/logout against the legacy `users`
 * table (D5 starter kit adapted, no schema changes).
 *
 * Fixture: baseline_users id=1, user.baseline@brewingcompetitions.com,
 * userLevel '0' (admin). The stored hash is the legacy phpass-bcrypt
 * scheme: password_hash(md5($plaintext)) with cost 8 ($2a$ prefix).
 * Known plaintext for this fixture is "bcoem" (baseline header comment);
 * security-question answer is "pabst".
 */
final class AuthLoginTest extends PublicSurfaceTestCase
{
    /** Legacy $2a$ hash of md5('bcoem') — the baseline fixture's stored hash. */
    private const LEGACY_HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    protected function setUp(): void
    {
        parent::setUp();

        // The suite shares a persistent bcoem_test DB with the
        // Characterization suite, which truncates users/brewer/brewing
        // wholesale (ContestInfoTest aggregate test). A successful login
        // also rehashes the row. Seed the admin fixture idempotently so
        // this class never depends on prior suite state.
        $exists = DB::table('users')->where('id', 1)->exists();
        if (! $exists) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => 'user.baseline@brewingcompetitions.com',
                'password' => self::LEGACY_HASH,
                'userLevel' => '0',
                'userQuestion' => 'What is your favorite all-time beer to drink?',
                'userQuestionAnswer' => '$2a$08$gImDLllgw/nned4kVWDAD.394FXpXeoEip85oqEQ.fIy8s4U3lwx.',
                'userCreated' => '2024-01-01 00:00:01',
                'userFailedLogins' => 0,
                'userAdminObfuscate' => 0,
            ]);
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Admin',
                'brewerEmail' => 'user.baseline@brewingcompetitions.com',
            ]);
        } else {
            DB::table('users')->where('id', 1)->update([
                'password' => self::LEGACY_HASH,
                'userLevel' => '0',
                'user_name' => 'user.baseline@brewingcompetitions.com',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function adminUser(): array
    {
        return (array) DB::table('users')->where('id', 1)->first();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log In');
    }

    public function test_legacy_login_query_shape_renders(): void
    {
        // Legacy query shape redirects to the clean login URL.
        $this->get('/?section=login')->assertRedirect('/login');
    }

    public function test_phpass_hash_login_succeeds_and_rehashes_to_bcrypt(): void
    {
        // Legacy hash present in fixture.
        $this->assertStringStartsWith('$2a$', $this->adminUser()['password']);

        $response = $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $response->assertRedirect('/?section=admin'); // userLevel 0 <= 1 → admin
        $this->assertAuthenticated();

        // Rehash-on-login: the row is now bcrypt ($2y$), never $2a$.
        $after = $this->adminUser();
        $this->assertStringStartsWith('$2y$', $after['password']);
    }

    public function test_wrong_password_redirects_with_msg11_and_no_session(): void
    {
        $response = $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'nope-nope-nope',
        ]);

        $response->assertRedirect('/?msg=11');
        $this->assertGuest();
    }

    public function test_unknown_user_redirects_with_msg11(): void
    {
        $response = $this->post('/login', [
            'loginUsername' => 'nobody@example.com',
            'loginPassword' => 'bcoem',
        ]);

        $response->assertRedirect('/?msg=11');
        $this->assertGuest();
    }

    public function test_password_longer_than_72_bytes_is_rejected(): void
    {
        $response = $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => str_repeat('a', 73),
        ]);

        $response->assertRedirect('/?msg=11');
        $this->assertGuest();
    }

    public function test_entrant_user_redirects_to_list(): void
    {
        // Scope the demotion to the fixture account. This was previously an
        // unscoped DB::table('users')->update(['userLevel' => '2']), which
        // demoted EVERY user — and because the suite shares a database with the
        // local install, it took the developer's own admin account with it.
        // Restore afterwards so the change cannot leak into sibling suites.
        $original = DB::table('users')->where('id', 1)->value('userLevel');

        try {
            DB::table('users')->where('id', 1)->update(['userLevel' => '2']);

            $this->post('/login', [
                'loginUsername' => 'user.baseline@brewingcompetitions.com',
                'loginPassword' => 'bcoem',
            ])->assertRedirect('/list');
        } finally {
            DB::table('users')->where('id', 1)->update(['userLevel' => $original ?? '2']);
        }
    }

    public function test_logout_clears_session_and_redirects_home(): void
    {
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_route_rejects_get_method(): void
    {
        // Issue 14: the auto-logout countdown used to navigate via GET, which
        // the CSRF-protected POST-only route rejects with 405.
        $this->get('/logout')->assertStatus(405);
    }

    public function test_admin_session_modal_logs_out_via_post_not_get(): void
    {
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ])->assertRedirect('/?section=admin');

        $html = (string) $this->get('/admin')->assertOk()->getContent();

        // Session-expiry modals submit a CSRF POST through the shared helper…
        $this->assertStringContainsString('window.bcoemLogout(', $html);
        // …and no logout action issues a bare GET.
        $this->assertStringNotContainsString("location.replace('/logout')", $html);
    }

    public function test_username_normalized_lowercase_and_trimmed(): void
    {
        $this->post('/login', [
            'loginUsername' => '  USER.BASELINE@BREWINGCOMPETITIONS.COM  ',
            'loginPassword' => 'bcoem',
        ])->assertRedirect('/?section=admin');

        $this->assertAuthenticated();
    }

    public function test_brewer_email_mirrored_on_login(): void
    {
        // Precondition: brewer email differs from the normalized login email.
        DB::table('brewer')->where('uid', 1)->update(['brewerEmail' => 'old@example.com']);

        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ])->assertRedirect('/?section=admin');

        $this->assertSame(
            'user.baseline@brewingcompetitions.com',
            DB::table('brewer')->where('uid', 1)->value('brewerEmail'),
        );
    }

    public function test_hasher_flags_legacy_hash_for_rehash(): void
    {
        $hasher = app(Hasher::class);

        $this->assertTrue($hasher->needsRehash(self::LEGACY_HASH));
        $this->assertFalse($hasher->needsRehash('$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWX'));
        $this->assertTrue($hasher->check('bcoem', self::LEGACY_HASH));
    }
}
