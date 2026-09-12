<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brewer\ClubsSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `php artisan clubs:sync` (issue #22, Task B.4): a legible one-line summary
 * on success, a non-zero exit on failure so a scheduled run is visibly red.
 */
final class SyncClubsCommandTest extends PublicSurfaceTestCase
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

    public function test_it_reports_added_counts(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Alpha Club', 'Beta Club']])]);

        $exit = Artisan::call('clubs:sync');

        self::assertSame(0, $exit);
        self::assertStringContainsString('2 added', Artisan::output());
    }

    public function test_it_reports_when_already_up_to_date(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Alpha Club']])]);
        app(ClubsSyncService::class)->sync();

        Artisan::call('clubs:sync');

        self::assertStringContainsString('already up to date', Artisan::output());
    }

    public function test_a_failure_exits_non_zero_with_the_reason(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $exit = Artisan::call('clubs:sync');

        self::assertSame(1, $exit);
        self::assertStringContainsString('Clubs list sync failed', Artisan::output());
    }
}
