<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Issue #22 Task B.5 — the admin clubs screen: status indicator, manual sync
 * with a plain-language outcome, and the informational "dropped off the
 * central list" review. Reachable only by admins, like every other
 * routes/admin.php screen.
 */
final class AdminClubsScreenTest extends AdminScreensTestCase
{
    private const URL = 'https://clubs.test/clubs.json';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.clubs_list.source_url', self::URL);

        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();

        parent::tearDown();
    }

    public function test_non_admins_are_turned_away(): void
    {
        $id = 9402;
        DB::table('users')->insert([
            'id' => $id,
            'user_name' => 'p54.member@brewingcompetitions.com',
            'password' => 'x',
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->actingAs(User::findOrFail($id));

        $this->get('/admin/clubs')->assertRedirect('/?msg=99');
        $this->post('/admin/clubs/sync')->assertRedirect('/?msg=99');

        DB::table('users')->where('id', $id)->delete();
    }

    public function test_screen_renders_before_the_first_sync(): void
    {
        $this->get('/admin/clubs')
            ->assertOk()
            ->assertSee('never')
            ->assertSee('Clubs no longer in the central list');
    }

    public function test_admin_sees_status_and_can_sync_now(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Central Club A', 'Central Club B']])]);

        $this->post('/admin/clubs/sync')
            ->assertRedirect('/admin/clubs')
            ->assertSessionHas('status');

        $this->get('/admin/clubs')
            ->assertOk()
            ->assertSee('v1')
            ->assertDontSee('never');
    }

    public function test_sync_failure_shows_a_plain_language_message_not_a_500(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->followingRedirects()
            ->post('/admin/clubs/sync')
            ->assertOk()
            ->assertSee('Clubs list sync failed');
    }

    public function test_review_list_identifies_clubs_that_dropped_off(): void
    {
        $now = now();

        DB::table('clubs')->insert([
            [
                'name' => 'Dropped Club',
                'name_normalized' => 'dropped club',
                'source' => 'upstream',
                'last_seen_at' => $now->copy()->subDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Current Club',
                'name_normalized' => 'current club',
                'source' => 'upstream',
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('clubs_sync_state')->insert([
            'id' => 1,
            'version' => 'v1',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $html = (string) $this->get('/admin/clubs')->assertOk()->getContent();

        self::assertStringContainsString('Dropped Club', $html);
        self::assertStringNotContainsString('Current Club', $html);
    }
}
