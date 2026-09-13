<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Installation\Fixups\SyncCentralClubsList;
use App\Services\Installation\UpgradeFixups;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Issue #22: the first central-clubs sync runs as part of the upgrade wizard,
 * so a shared/FTP operator gets a populated picker without a shell. The fixup
 * must be best-effort — a bad day upstream can never fail an upgrade.
 */
final class ClubsUpgradeFixupTest extends PublicSurfaceTestCase
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

    public function test_it_primes_the_clubs_table_during_an_upgrade(): void
    {
        Http::fake([self::URL => Http::response(['version' => 'v1', 'clubs' => ['Fixup Club']])]);

        (new SyncCentralClubsList)->run();

        self::assertSame(1, DB::table('clubs')->where('name', 'Fixup Club')->count());
        self::assertSame('v1', DB::table('clubs_sync_state')->where('id', 1)->value('version'));
    }

    public function test_it_never_fails_an_upgrade_when_the_source_is_unreachable(): void
    {
        Http::fake(static function (): void {
            throw new ConnectionException('Connection refused');
        });

        (new SyncCentralClubsList)->run();

        self::assertSame(0, DB::table('clubs')->count());
        self::assertNull(DB::table('clubs_sync_state')->where('id', 1)->value('version'));
    }

    public function test_it_is_registered_for_each_release_that_ships_the_clubs_tables(): void
    {
        // The registry is process-global static state, so several test boots can
        // leave duplicates: assert membership, never an exact count.
        foreach (['4.1.0-alpha.1', '4.1.0'] as $incoming) {
            $registered = false;
            foreach ((new UpgradeFixups)->for('4.0.0', $incoming) as $fixup) {
                if ($fixup instanceof SyncCentralClubsList) {
                    $registered = true;
                }
            }
            self::assertTrue($registered, 'the clubs fixup must run on the 4.0.0 -> '.$incoming.' upgrade');
        }

        foreach ((new UpgradeFixups)->for('4.1.0', '4.2.0') as $fixup) {
            self::assertNotInstanceOf(SyncCentralClubsList::class, $fixup, 'it must not run on a later jump');
        }
    }
}
