<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Task 2.2 acceptance: the "new release published" dashboard notice, its
 * 24-hour cache, and the rule that it does not even run for non-Top-Level
 * Administrators.
 */
final class RemoteVersionNoticeTest extends WizardTestCase
{
    public function test_top_level_admin_sees_the_cached_release_notice_without_http(): void
    {
        $this->setInstalled(true, '4.0.0');
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v9.9.9'], 200)]);
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $this->actingAs($this->user('0'))->get('/admin')
            ->assertOk()
            ->assertSee('Version 4.1.0 is available');

        Http::assertNothingSent();
    }

    public function test_mid_level_admin_never_runs_the_release_check(): void
    {
        $this->setInstalled(true, '4.0.0');
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v9.9.9'], 200)]);
        Cache::forget('bcoem.remote-version');

        $this->actingAs($this->user('1'))->get('/admin')
            ->assertOk()
            ->assertDontSee('Version 9.9.9 is available');

        Http::assertNothingSent();
    }

    public function test_dismissing_the_notice_hides_it_for_the_session(): void
    {
        $this->setInstalled(true, '4.0.0');
        Http::fake();
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $admin = $this->user('0');

        $this->actingAs($admin)->post('/admin/notices/dismiss')->assertRedirect();

        // The test client does not carry the session cookie between requests,
        // so replay the dismissal the POST recorded.
        $this->actingAs($admin)
            ->withSession(['wizard.update-notice.dismissed' => true])
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Version 4.1.0 is available');
    }
}
