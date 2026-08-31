<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PARITY-027 — safe public mods render without PHP file include.
 * Legacy contract (index.pub.php:357+618, mods_top/bottom.inc.php,
 * mods.db.php:54-108): prefsUseMods=Y → render informational mods
 * (mod_type=0, mod_enable=1) at top (display_rank=1) / bottom
 * (display_rank=2) of core content, gated by mod_permission >=
 * userLevel and mod_extend_function section match. Non-informational
 * types (1/2/3) and disabled mods are excluded.
 */
final class ModsPublicRenderTest extends PublicSurfaceTestCase
{
    private const MOD_DATA = [
        'mod_name' => 'P527 Test Mod',
        'mod_filename' => 'test_mod.php',
        'mod_description' => '<div class="test-mod-content">Custom announcement for the contest.</div>',
        'mod_type' => '0',
        'mod_permission' => '2',
        'mod_extend_function' => '0',
        'mod_extend_function_admin' => null,
        'mod_rank' => '1',
        'mod_display_rank' => '1',
        'mod_enable' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('mods')->where('mod_name', 'P527 Test Mod')->delete();
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'N']);
    }

    protected function tearDown(): void
    {
        DB::table('mods')->where('mod_name', 'P527 Test Mod')->delete();
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'N']);
        parent::tearDown();
    }

    public function test_disabled_globally_renders_no_mods(): void
    {
        DB::table('mods')->insert(self::MOD_DATA);
        $this->get('/')->assertOk()->assertDontSee('Custom announcement for the contest.', false);
    }

    public function test_enabled_top_renders_before_core(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        DB::table('mods')->insert(self::MOD_DATA);

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringContainsString('id="mods-top"', $html);
        self::assertStringContainsString('Custom announcement for the contest.', $html);
        // mods-top appears before main-content
        self::assertStringContainsString('mods-top', explode('main-content', $html)[0] ?? '');
    }

    public function test_enabled_bottom_renders_after_core(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        DB::table('mods')->insert(array_merge(self::MOD_DATA, [
            'mod_display_rank' => '2',
        ]));

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringContainsString('id="mods-bottom"', $html);
        self::assertStringContainsString('Custom announcement for the contest.', $html);
        // mods-bottom appears after main-content
        $after = explode('main-content', $html);
        self::assertStringContainsString('mods-bottom', end($after));
    }

    public function test_disabled_mod_not_rendered(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        DB::table('mods')->insert(array_merge(self::MOD_DATA, ['mod_enable' => '0']));

        $this->get('/')->assertOk()->assertDontSee('Custom announcement for the contest.', false);
    }

    public function test_non_informational_type_not_rendered(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        // mod_type=3 (PHP Code) has no DB content to render
        DB::table('mods')->insert(array_merge(self::MOD_DATA, [
            'mod_type' => '3',
            'mod_description' => '<p>Should not appear</p>',
        ]));

        $this->get('/')->assertOk()->assertDontSee('Should not appear', false);
    }

    public function test_permission_uber_only_hidden_from_admin(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        DB::table('mods')->insert(array_merge(self::MOD_DATA, ['mod_permission' => '0']));

        $admin = User::query()->where('user_name', 'assign.admin@brewingcompetitions.com')->first();
        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Custom announcement for the contest.', false);
    }

    public function test_extend_function_section_filter(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        // extend=6 means register section only
        DB::table('mods')->insert(array_merge(self::MOD_DATA, [
            'mod_extend_function' => '6',
        ]));

        // Landing (section=1) should not render it
        $this->get('/')->assertOk()->assertDontSee('Custom announcement for the contest.', false);
    }
}
