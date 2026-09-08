<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PARITY-027 — public mods render the mods/<mod_filename> FILE, never
 * mod_description, exactly like legacy mods_top/bottom.inc.php +
 * mod_display() (includes/db/mods.db.php).
 * Legacy contract (index.pub.php:357+618): prefsUseMods=Y → include-render
 * informational mods (mod_type=0, mod_enable=1) at top (display_rank=1) /
 * bottom (display_rank=2) of core content, gated by mod_permission >=
 * userLevel (0 uber / 1 admin / 2 all; anon counts as 2 —
 * mods_top.inc.php:5) and mod_extend_function section match (0 or the
 * section). A missing file renders NOTHING on public pages; a broken
 * (throwing) file is skipped so one bad module cannot take the page down.
 */
final class ModsPublicRenderTest extends PublicSurfaceTestCase
{
    private const MOD_DATA = [
        'mod_name' => 'P527 Test Mod',
        'mod_filename' => 'test_mod.php',
        'mod_description' => 'Admin-only note: this text must NEVER appear on public pages.',
        'mod_type' => '0',
        'mod_permission' => '2',
        'mod_extend_function' => '0',
        'mod_extend_function_admin' => null,
        'mod_rank' => '1',
        'mod_display_rank' => '1',
        'mod_enable' => '1',
    ];

    private const MOD_FILE_HTML = '<div class="test-mod-content"><h2>Custom announcement for the contest.</h2><p>Rendered from the module file.</p></div>';

    private string $modFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modFilePath = base_path('mods/'.self::MOD_DATA['mod_filename']);
        DB::table('users')->where('user_name', 'assign.admin@brewingcompetitions.com')->delete();
        DB::table('users')->insert([
            'user_name' => 'assign.admin@brewingcompetitions.com',
            'password' => Hash::make('bcoem'),
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('mods')->where('mod_name', 'P527 Test Mod')->delete();
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'N']);
        @unlink($this->modFilePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->modFilePath);
        DB::table('mods')->where('mod_name', 'P527 Test Mod')->delete();
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'N']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function seedMod(array $overrides = []): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsUseMods' => 'Y']);
        DB::table('mods')->insert(array_merge(self::MOD_DATA, $overrides));
    }

    private function seedFile(string $html = self::MOD_FILE_HTML): void
    {
        file_put_contents($this->modFilePath, $html);
    }

    public function test_disabled_globally_renders_no_mods(): void
    {
        // prefsUseMods stays 'N' (setUp); the row + file alone must not render.
        DB::table('mods')->insert(self::MOD_DATA);
        $this->seedFile();

        $this->get('/')->assertOk()->assertDontSee('Rendered from the module file.', false);
    }

    public function test_missing_file_renders_nothing_even_with_description(): void
    {
        // Enabled mod, prefsUseMods=Y, but no mods/test_mod.php file —
        // legacy mod_display() file_exists() gate: public pages show
        // nothing, and mod_description is never a fallback.
        $this->seedMod();

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringNotContainsString('id="mods-top"', $html);
        self::assertStringNotContainsString('Admin-only note', $html);
    }

    public function test_enabled_top_renders_file_contents_before_core(): void
    {
        $this->seedMod();
        $this->seedFile();

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringContainsString('id="mods-top"', $html);
        self::assertStringContainsString('Rendered from the module file.', $html);
        // mod_description must not leak onto public pages.
        self::assertStringNotContainsString('Admin-only note', $html);
        // mods-top appears before main-content
        self::assertStringContainsString('mods-top', explode('main-content', $html)[0] ?? '');
    }

    public function test_enabled_bottom_renders_after_core(): void
    {
        $this->seedMod(['mod_display_rank' => '2']);
        $this->seedFile();

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringContainsString('id="mods-bottom"', $html);
        self::assertStringContainsString('Rendered from the module file.', $html);
        $after = explode('main-content', $html);
        self::assertStringContainsString('mods-bottom', end($after));
    }

    public function test_broken_file_is_skipped_not_fatal(): void
    {
        $this->seedMod();
        $this->seedFile('<?php throw new RuntimeException("boom");');

        $html = (string) $this->get('/')->assertOk()->getContent();
        self::assertStringNotContainsString('id="mods-top"', $html);
        self::assertStringNotContainsString('boom', $html);
    }

    public function test_disabled_mod_not_rendered(): void
    {
        $this->seedMod(['mod_enable' => '0']);
        $this->seedFile();

        $this->get('/')->assertOk()->assertDontSee('Rendered from the module file.', false);
    }

    public function test_non_informational_type_not_rendered(): void
    {
        // mod_type=3 (PHP Code) never renders through mods_top/bottom.
        $this->seedMod(['mod_type' => '3']);
        $this->seedFile('<p>Should not appear</p>');

        $this->get('/')->assertOk()->assertDontSee('Should not appear', false);
    }

    public function test_permission_uber_only_hidden_from_admin(): void
    {
        $this->seedMod(['mod_permission' => '0']);
        $this->seedFile();

        $admin = User::query()->where('user_name', 'assign.admin@brewingcompetitions.com')->first();
        self::assertNotNull($admin);
        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Rendered from the module file.', false);
    }

    public function test_permission_all_users_shows_to_guest(): void
    {
        // mods_top.inc.php:5 gives guests user_level_mods = "2", so an
        // "All Users" (permission 2) mod renders for anonymous visitors.
        $this->seedMod(['mod_permission' => '2']);
        $this->seedFile();

        $this->get('/')->assertOk()->assertSee('Rendered from the module file.', false);
    }

    public function test_extend_function_section_filter(): void
    {
        $this->seedMod(['mod_extend_function' => '6']);
        $this->seedFile();

        // Landing (section=1) should not render an extend=6 (register) mod.
        $this->get('/')->assertOk()->assertDontSee('Rendered from the module file.', false);
    }
}
