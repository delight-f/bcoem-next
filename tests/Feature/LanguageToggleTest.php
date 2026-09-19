<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PARITY-026 — Language toggle + locale resolution layer.
 * Legacy contract (language.lang.php:42-78 + pub/nav.pub.php:130-152):
 * prefsLanguage default en-US; per-session userLanguage cookie overrides
 * when prefsLanguageToggle=Y AND the code is in prefsLanguageOptions.
 * The navbar shows a globe dropdown gated on toggle=Y + count>1.
 * Admin/evaluation/setup/update always forces en-US (language.lang.php:95-110).
 */
final class LanguageToggleTest extends PublicSurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->where('user_name', 'assign.admin@brewingcompetitions.com')->delete();
        DB::table('users')->insert([
            'user_name' => 'assign.admin@brewingcompetitions.com',
            'password' => Hash::make('bcoem'),
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'N',
            'prefsLanguageOptions' => null,
            'prefsLanguage' => 'en-US',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'N',
            'prefsLanguageOptions' => null,
            'prefsLanguage' => 'en-US',
        ]);
        parent::tearDown();
    }

    public function test_toggle_hidden_when_disabled(): void
    {
        $html = (string) $this->get('/')->getContent();
        self::assertStringNotContainsString('fa-globe', $html);
    }

    public function test_toggle_shown_when_enabled_with_multiple_options(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => json_encode(['en-US', 'cs-CZ', 'fr-FR']),
        ]);

        $html = (string) $this->get('/')->getContent();
        self::assertStringContainsString('fa-globe', $html);
        self::assertStringContainsString('Čeština', $html);
        self::assertStringContainsString('Français', $html);
    }

    public function test_toggle_shown_with_null_options_uses_canonical_fallback_codes(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => null,
        ]);

        $html = (string) $this->get('/')->getContent();
        self::assertStringContainsString('fa-globe', $html);
        self::assertStringContainsString('Čeština', $html);
    }

    public function test_lang_param_sets_cookie_and_redirects(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => json_encode(['en-US', 'cs-CZ']),
        ]);

        $response = $this->get('/?lang=cs-CZ');
        $response->assertRedirect('/');
        $response->assertCookie('userLanguage', 'cs-CZ');
    }

    public function test_lang_param_rejected_when_not_in_options(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => json_encode(['en-US']),
        ]);

        $response = $this->get('/?lang=cs-CZ');
        $response->assertOk(); // no redirect, stays on page
    }

    public function test_cookie_sets_locale(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => json_encode(['en-US', 'cs-CZ']),
        ]);

        $response = $this->withCookie('userLanguage', 'cs-CZ')->get('/');
        $response->assertOk();
        self::assertSame('cs', app()->getLocale());
    }

    public function test_admin_forces_en_locale(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsLanguageToggle' => 'Y',
            'prefsLanguageOptions' => json_encode(['en-US', 'cs-CZ']),
        ]);

        $admin = User::query()->where('user_name', 'assign.admin@brewingcompetitions.com')->firstOrFail();
        $this->actingAs($admin)
            ->withCookie('userLanguage', 'cs-CZ')
            ->get('/admin')
            ->assertOk();
        self::assertSame('en', app()->getLocale());
    }
}
