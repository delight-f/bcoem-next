<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PARITY-028 — DB-stored equivalent of the legacy
 * custom_competition_info.pub.php deploy-time drop-in (index.pub.php:405 +
 * pub/nav.pub.php:109 "Other Info" nav link). The block renders on the
 * landing page's competition-info surface when non-empty and gates the
 * nav item, mirroring legacy's file_exists() gate; absent when empty.
 */
final class CompetitionInfoExtraTest extends PublicSurfaceTestCase
{
    private const EXTRA = '<h2 class="mb-3">Special Rules</h2><p>All entries must be bottled by Friday.</p>';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('contest_info')->where('id', 1)->update(['contestInfoExtra' => null]);
        DB::table('users')->where('user_name', 'config.admin@brewingcompetitions.com')->delete();
        DB::table('users')->insert([
            'user_name' => 'config.admin@brewingcompetitions.com',
            'password' => Hash::make('bcoem'),
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('contest_info')->where('id', 1)->update(['contestInfoExtra' => null]);
        parent::tearDown();
    }

    public function test_absent_block_renders_no_section_and_no_nav_link(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = (string) $response->getContent();
        self::assertStringNotContainsString('id="custom-competition-info"', $html);
        self::assertStringNotContainsString('Other Info', $html);
    }

    public function test_present_block_renders_section_and_nav_link(): void
    {
        DB::table('contest_info')->where('id', 1)->update(['contestInfoExtra' => self::EXTRA]);

        $response = $this->get('/');
        $response->assertOk();

        $html = (string) $response->getContent();
        self::assertStringContainsString('id="custom-competition-info"', $html);
        self::assertStringContainsString('Special Rules', $html);
        self::assertStringContainsString('All entries must be bottled by Friday.', $html);
        self::assertStringContainsString('Other Info', $html);
        self::assertStringContainsString('#custom-competition-info', $html);
    }

    public function test_admin_competition_info_form_persists_block(): void
    {
        $admin = User::query()->where('user_name', 'config.admin@brewingcompetitions.com')->firstOrFail();

        $this->actingAs($admin)
            ->put('/admin/competition-info', [
                'contestName' => (string) DB::table('contest_info')->where('id', 1)->value('contestName'),
                'contestInfoExtra' => self::EXTRA,
            ])
            ->assertRedirect('/admin/competition-info?msg=2');

        self::assertSame(
            self::EXTRA,
            DB::table('contest_info')->where('id', 1)->value('contestInfoExtra'),
        );

        $this->get('/')
            ->assertOk()
            ->assertSee('Special Rules', false);
    }
}
