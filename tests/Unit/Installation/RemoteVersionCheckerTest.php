<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Support\Wizard\RemoteVersionChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RemoteVersionCheckerTest extends TestCase
{
    public function test_latest_version_is_fetched_once_then_served_from_cache(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v4.1.0'], 200)]);

        $checker = new RemoteVersionChecker('bcoem/bcoem-next');

        $this->assertSame('4.1.0', $checker->latestPublishedVersion());
        $this->assertSame('4.1.0', $checker->latestPublishedVersion());
        $this->assertSame('4.1.0', $checker->cachedVersion());

        Http::assertSentCount(1);
    }

    public function test_rate_limit_or_error_response_yields_null_and_is_cached(): void
    {
        Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);

        $checker = new RemoteVersionChecker('bcoem/bcoem-next');

        $this->assertNull($checker->latestPublishedVersion());
        $this->assertNull($checker->latestPublishedVersion());

        Http::assertSentCount(1);
    }

    public function test_network_failure_is_swallowed(): void
    {
        Http::fake(['api.github.com/*' => function (): void {
            throw new \RuntimeException('no outbound network');
        }]);

        $this->assertNull((new RemoteVersionChecker('bcoem/bcoem-next'))->latestPublishedVersion());
    }

    public function test_check_does_not_run_for_non_top_level_admins(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v9.9.9'], 200)]);
        Cache::forget('bcoem.remote-version');

        $checker = new RemoteVersionChecker('bcoem/bcoem-next');

        $this->assertNull($checker->noticeFor(1, '4.0.0'));
        $this->assertNull($checker->noticeFor(2, '4.0.0'));
        $this->assertNull($checker->noticeFor(null, '4.0.0'));

        Http::assertNothingSent();
    }

    public function test_notice_uses_the_cached_value_without_blocking_on_http(): void
    {
        Http::fake();
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $notice = (new RemoteVersionChecker('bcoem/bcoem-next'))->noticeFor(0, '4.0.0');

        $this->assertSame('4.1.0', $notice['version'] ?? null);
        $this->assertSame('https://github.com/bcoem/bcoem-next/releases', $notice['url'] ?? null);
        Http::assertNothingSent();
    }

    public function test_no_notice_when_the_cached_version_is_not_newer(): void
    {
        Http::fake();

        $checker = new RemoteVersionChecker('bcoem/bcoem-next');

        Cache::put('bcoem.remote-version', ['version' => '4.0.0', 'checked_at' => time()], 86400);
        $this->assertNull($checker->noticeFor(0, '4.0.0'));

        Cache::put('bcoem.remote-version', ['version' => '3.0.1.0', 'checked_at' => time()], 86400);
        $this->assertNull($checker->noticeFor(0, '4.0.0'));

        Http::assertNothingSent();
    }
}
