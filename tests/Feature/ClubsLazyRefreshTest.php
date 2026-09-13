<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brewer\Clubs;
use App\Support\Brewer\ClubsSyncService;
use App\Support\Tenant\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Issue #22: `clubs:sync` is scheduled daily, but a shared/FTP host often has
 * no cron. The picker's lazy refresh must populate the mirror on first use,
 * stay cheap once fresh, throttle an unreachable source, and never break a page.
 *
 * The suite disables lazy refresh (phpunit.xml); these tests opt back in.
 */
final class ClubsLazyRefreshTest extends PublicSurfaceTestCase
{
    private const URL = 'https://clubs.test/clubs.json';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.clubs_list.source_url', self::URL);
        config()->set('services.clubs_list.timeout_seconds', 5);
        config()->set('services.clubs_list.lazy_refresh', true);

        Cache::flush();
        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();
        Cache::flush();

        parent::tearDown();
    }

    private function service(): ClubsSyncService
    {
        return app(ClubsSyncService::class);
    }

    private function rememberSync(string $version, DateTimeInterface $at): void
    {
        DB::table('clubs_sync_state')->insert([
            'id' => 1,
            'version' => $version,
            'synced_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_an_unsynced_site_is_populated_on_first_use(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Lazy Club']])]);

        $result = $this->service()->refreshIfStale();

        self::assertNotNull($result);
        self::assertTrue($result->ok);
        self::assertSame(1, DB::table('clubs')->where('name', 'Lazy Club')->count());
    }

    public function test_a_fresh_sync_is_not_fetched_again(): void
    {
        $this->rememberSync('v1', now()->subHour());
        Http::fake();

        $result = $this->service()->refreshIfStale();

        self::assertNull($result);
        Http::assertNothingSent();
    }

    public function test_a_stale_sync_is_refreshed(): void
    {
        $this->rememberSync('v1', now()->subDays(2));
        Http::fake([self::URL => Http::response(['version' => 'v2', 'clubs' => ['Refreshed Club']])]);

        $result = $this->service()->refreshIfStale();

        self::assertNotNull($result);
        self::assertSame('v2', DB::table('clubs_sync_state')->where('id', 1)->value('version'));
        self::assertSame(1, DB::table('clubs')->where('name', 'Refreshed Club')->count());
    }

    public function test_an_unreachable_source_is_not_retried_within_the_throttle(): void
    {
        Http::fake([self::URL => Http::response('nope', 503)]);

        $this->service()->refreshIfStale();
        $this->service()->refreshIfStale();

        Http::assertSentCount(1);
    }

    public function test_the_club_picker_triggers_the_refresh(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Picker Club']])]);

        $names = Clubs::all(TenantContext::load());

        self::assertContains('Picker Club', $names);
    }
}
