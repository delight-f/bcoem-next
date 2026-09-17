<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Admin authorization is now a single middleware (EnsureAdmin / EnsureTopAdmin,
 * aliases `admin` / `admin.top`) instead of an inline `isAdmin()` check in every
 * action. This pins the contract those ~143 inline checks used to provide:
 *
 *  - guest              → /login (via `auth`, which runs first);
 *  - authed non-admin   → /?msg=99, or a JSON `{"status":9}` 403 for AJAX callers;
 *  - `admin`            → userLevel 0 and 1;
 *  - `admin.top`        → userLevel 0 only.
 *
 * It also pins the deliberate exclusions: judge signup stays open to entrants.
 */
final class AdminAuthorizationMiddlewareTest extends PublicSurfaceTestCase
{
    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const TOP_EMAIL = 'authz.top@brewingcompetitions.com';

    private const MID_EMAIL = 'authz.mid@brewingcompetitions.com';

    private const ENTRANT_EMAIL = 'authz.entrant@brewingcompetitions.com';

    private const TOP_ID = 98501;

    private const MID_ID = 98502;

    private const ENTRANT_ID = 98503;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TOP_ID, self::MID_ID, self::ENTRANT_ID] as $id) {
            DB::table('users')->where('id', $id)->delete();
        }

        $this->seedUser(self::TOP_ID, self::TOP_EMAIL, '0');
        $this->seedUser(self::MID_ID, self::MID_EMAIL, '1');
        $this->seedUser(self::ENTRANT_ID, self::ENTRANT_EMAIL, '2');
    }

    protected function tearDown(): void
    {
        DB::table('brewer')->whereIn('uid', [self::TOP_ID, self::MID_ID, self::ENTRANT_ID])->delete();
        DB::table('users')->whereIn('id', [self::TOP_ID, self::MID_ID, self::ENTRANT_ID])->delete();

        parent::tearDown();
    }

    private function seedUser(int $id, string $email, string $level): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'user_name' => $email,
            'password' => self::HASH,
            'userLevel' => $level,
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
    }

    private function login(string $email): void
    {
        $this->post('/login', ['loginUsername' => $email, 'loginPassword' => 'bcoem']);
    }

    /**
     * One representative route from each `admin`-gated group, across the module
     * route files (admin, backoffice, judging, judging-scores, archive).
     *
     * @return list<string>
     */
    private function uniformlyGatedRoutes(): array
    {
        return [
            '/admin',
            '/admin/dates',
            '/admin/contacts',
            '/admin/sponsors',
            '/admin/styles',
            '/admin/style-types',
            '/admin/judging/locations',
            '/admin/judging/non-judging',
            '/admin/dropoff',
            '/admin/judging/tables',
            '/admin/judging/special-best',
            '/admin/judging/flights',
            '/admin/judging/bos',
            '/admin/judging/scores',
            '/backoffice/participants',
            '/backoffice/entries',
            '/admin/archive',
            '/admin/purge',
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        foreach ($this->uniformlyGatedRoutes() as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_non_admin_is_bounced_to_the_legacy_notice(): void
    {
        $this->login(self::ENTRANT_EMAIL);

        foreach ($this->uniformlyGatedRoutes() as $uri) {
            $this->get($uri)->assertRedirect('/?msg=99');
        }
    }

    public function test_json_caller_gets_the_status_9_envelope_instead_of_a_redirect(): void
    {
        $this->login(self::ENTRANT_EMAIL);

        $this->getJson('/admin')
            ->assertStatus(403)
            ->assertJson(['status' => 9]);
    }

    public function test_mid_level_admin_reaches_the_uniform_admin_routes(): void
    {
        $this->login(self::MID_EMAIL);

        foreach (['/admin', '/admin/dates', '/admin/contacts', '/admin/judging/locations', '/backoffice/participants'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_top_only_routes_reject_a_mid_level_admin(): void
    {
        $this->login(self::MID_EMAIL);

        $this->get('/admin/users/'.self::TOP_ID.'/level')->assertRedirect('/?msg=99');
        $this->put('/admin/users/'.self::TOP_ID.'/level', ['userLevel' => '2'])->assertRedirect('/?msg=99');
    }

    public function test_top_level_admin_reaches_the_top_only_routes(): void
    {
        $this->login(self::TOP_EMAIL);

        $this->get('/admin/users/'.self::TOP_ID.'/level')->assertOk();
    }

    public function test_judge_signup_stays_open_to_non_admins(): void
    {
        $this->login(self::ENTRANT_EMAIL);

        $response = $this->get('/judge');

        $this->assertNotSame(
            '/?msg=99',
            $response->headers->get('Location'),
            'judge signup must not sit behind the admin gate',
        );
    }
}
