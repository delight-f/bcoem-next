<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brewer\ClubsSyncService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Issue #22 Part B — the Laravel side of the central clubs list sync.
 *
 * The service must be safe on every failure path (no partial writes, version
 * marker untouched), cheap on repeat runs (content-derived version
 * short-circuit), and must never delete a club because it vanished upstream.
 */
final class ClubsSyncServiceTest extends PublicSurfaceTestCase
{
    private const URL = 'https://clubs.test/clubs.json';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.clubs_list.source_url', self::URL);
        config()->set('services.clubs_list.timeout_seconds', 5);

        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('clubs')->delete();
        DB::table('clubs_sync_state')->delete();

        parent::tearDown();
    }

    /** @param list<string> $clubs */
    private function fakePayload(array $clubs, string $version): void
    {
        Http::fake([self::URL => Http::response(['version' => $version, 'clubs' => $clubs])]);
    }

    private function service(): ClubsSyncService
    {
        return app(ClubsSyncService::class);
    }

    public function test_sync_inserts_new_clubs_and_records_the_version(): void
    {
        $this->fakePayload(['Alpha Club', 'Beta Club'], 'v1');

        $result = $this->service()->sync();

        self::assertTrue($result->ok);
        self::assertFalse($result->upToDate);
        self::assertSame(2, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame('v1', $result->version);

        self::assertSame(2, DB::table('clubs')->count());
        self::assertSame('upstream', DB::table('clubs')->where('name', 'Alpha Club')->value('source'));
        self::assertNotNull(DB::table('clubs')->where('name', 'Alpha Club')->value('last_seen_at'));
        self::assertSame('v1', DB::table('clubs_sync_state')->where('id', 1)->value('version'));
    }

    public function test_an_unreachable_source_is_a_no_op_and_logs_a_warning(): void
    {
        Http::fake(static function (): void {
            throw new ConnectionException('Connection refused');
        });
        Log::shouldReceive('warning')->once();

        $result = $this->service()->sync();

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not reach', (string) $result->failure);
        self::assertSame(0, DB::table('clubs')->count());
        self::assertNull(DB::table('clubs_sync_state')->where('id', 1)->value('version'));
    }

    public function test_a_non_success_status_is_a_no_op(): void
    {
        Http::fake([self::URL => Http::response('nope', 503)]);
        Log::shouldReceive('warning')->once();

        $result = $this->service()->sync();

        self::assertFalse($result->ok);
        self::assertStringContainsString('503', (string) $result->failure);
        self::assertSame(0, DB::table('clubs')->count());
    }

    public function test_a_malformed_payload_is_a_no_op(): void
    {
        Http::fake([self::URL => Http::response(['clubs' => 'not-an-array'])]);

        $result = $this->service()->sync();

        self::assertFalse($result->ok);
        self::assertSame(0, DB::table('clubs')->count());
        self::assertNull(DB::table('clubs_sync_state')->where('id', 1)->value('version'));
    }

    public function test_an_unchanged_version_writes_nothing_to_the_clubs_table(): void
    {
        $this->fakePayload(['Alpha Club', 'Beta Club'], 'v1');
        $this->service()->sync();

        $writes = 0;
        DB::listen(static function ($query) use (&$writes): void {
            $sql = (string) $query->sql;

            if (! preg_match('/^\s*(insert|update|delete)/i', $sql)) {
                return;
            }
            if (! str_contains($sql, 'clubs') || str_contains($sql, 'clubs_sync_state')) {
                return;
            }
            $writes++;
        });

        $result = $this->service()->sync();

        self::assertTrue($result->upToDate);
        self::assertSame('v1', $result->version);
        self::assertSame(0, $writes, 'a matching version must not touch the clubs table');
    }

    public function test_local_casing_wins_and_absent_clubs_are_never_deleted(): void
    {
        $past = now()->subDays(30);

        DB::table('clubs')->insert([
            [
                'name' => '50 west',
                'name_normalized' => '50 west',
                'source' => 'upstream',
                'last_seen_at' => $past,
                'created_at' => $past,
                'updated_at' => $past,
            ],
            [
                'name' => 'Old Dropped Club',
                'name_normalized' => 'old dropped club',
                'source' => 'upstream',
                'last_seen_at' => $past,
                'created_at' => $past,
                'updated_at' => $past,
            ],
        ]);

        $this->fakePayload(['50 West', 'New Club'], 'v2');

        $result = $this->service()->sync();

        self::assertSame(1, $result->added, 'New Club is inserted');
        self::assertSame(1, $result->updated, '50 West matches an existing row');

        self::assertSame('50 west', DB::table('clubs')->where('name_normalized', '50 west')->value('name'), 'local casing is kept');
        self::assertTrue(
            (string) DB::table('clubs')->where('name_normalized', '50 west')->value('last_seen_at') > (string) $past,
            'the sighting is refreshed'
        );
        self::assertSame(1, DB::table('clubs')->where('name', 'Old Dropped Club')->count(), 'a club missing upstream is never deleted');
        self::assertSame(1, DB::table('clubs')->where('name', 'New Club')->count());
    }

    public function test_a_failed_run_leaves_the_previous_version_and_rows_in_place(): void
    {
        // A previous successful sync already recorded.
        $past = now()->subDay();
        DB::table('clubs')->insert([
            'name' => 'Alpha Club',
            'name_normalized' => 'alpha club',
            'source' => 'upstream',
            'last_seen_at' => $past,
            'created_at' => $past,
            'updated_at' => $past,
        ]);
        DB::table('clubs_sync_state')->insert([
            'id' => 1,
            'version' => 'v1',
            'synced_at' => $past,
            'created_at' => $past,
            'updated_at' => $past,
        ]);

        Http::fake([self::URL => Http::response('boom', 500)]);

        $result = $this->service()->sync();

        self::assertFalse($result->ok);
        self::assertSame('v1', DB::table('clubs_sync_state')->where('id', 1)->value('version'));
        self::assertSame(1, DB::table('clubs')->count());
        self::assertSame(
            (string) $past,
            (string) DB::table('clubs')->where('name', 'Alpha Club')->value('last_seen_at'),
            'a failed run must not touch existing rows'
        );
    }
}
