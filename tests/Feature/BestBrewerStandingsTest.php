<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Results\BestBrewerStandings;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pins BestBrewerStandings (port of scores_bestbrewer.db.php + awards.php
 * accumulation) against the legacy point semantics:
 * - classic: place-point prefs × place counts + tie-breaker fractions.
 * - CoA: ((pool - place)/pool)^3 summed over per-pool best places.
 * - prefsBestUseBOS merges judging_scores_bos rows.
 * - top-N capping.
 */
final class BestBrewerStandingsTest extends AdminScreensTestCase
{
    /** @var array<string, mixed> */
    private array $prefBackup = [];

    /** @var list<array{eid:int,uid:int,table:int}> */
    private array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefBackup = (array) DB::table('preferences')->where('id', 1)->first();
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $s) {
            DB::table('judging_scores')->where('eid', $s['eid'])->delete();
            DB::table('judging_scores_bos')->where('eid', $s['eid'])->delete();
            DB::table('brewing')->where('id', $s['eid'])->delete();
        }
        if ($this->seeded !== []) {
            DB::table('brewer')->whereIn('uid', [999300, 999301, 999302, 999303])->delete();
            DB::table('users')->whereIn('id', [999300, 999301, 999302, 999303])->delete();
        }
        DB::table('preferences')->where('id', 1)->update($this->prefBackup);
        parent::tearDown();
    }

    public function test_classic_points_matches_place_prefs(): void
    {
        $this->seedEntry(999300, '1', 40);
        $this->seedEntry(999300, '1', 41);
        $this->seedEntry(999300, '2', 42);

        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => -1,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 0,
            'prefsBestUseBOS' => 0,
            'prefsWinnerMethod' => 0,
            'prefsFirstPlacePts' => 4,
            'prefsSecondPlacePts' => 3,
            'prefsThirdPlacePts' => 2,
            'prefsFourthPlacePts' => 0,
            'prefsHMPts' => 0,
            'prefsTieBreakRule1' => '',
            'prefsTieBreakRule2' => '',
            'prefsTieBreakRule3' => '',
            'prefsTieBreakRule4' => '',
            'prefsTieBreakRule5' => '',
            'prefsTieBreakRule6' => '',
        ]);

        $standings = BestBrewerStandings::forAwards(TenantContext::load());

        self::assertCount(1, $standings->brewerRows);
        self::assertSame('Awrdbrewer Testerson', $standings->brewerRows[0]->name);
        self::assertSame(11.0, $standings->brewerRows[0]->points);
        self::assertSame([2, 1, 0, 0, 0], $standings->brewerRows[0]->places);
    }

    public function test_coa_points_use_pool_size(): void
    {
        // Table pools: brewer A a 1st in a table carrying 4 scored rows →
        // ((4-1)/4)^3 = 0.421875.
        $table = $this->seedEntry(999300, '1', 40);
        $this->seedEntryAt(999301, '2', $table, 38);
        $this->seedEntryAt(999302, '3', $table, 35);
        $this->seedEntryAt(999303, '4', $table, 33);

        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => -1,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 1,
            'prefsBestUseBOS' => 0,
            'prefsWinnerMethod' => 0,
        ]);

        $standings = BestBrewerStandings::forAwards(TenantContext::load());
        $row = collect($standings->brewerRows)->firstWhere('name', 'Awrdbrewer Testerson');
        self::assertNotNull($row);
        self::assertEqualsWithDelta(0.421875, $row->points, 1e-6);
    }

    public function test_bos_inclusion_adds_places(): void
    {
        $table = $this->seedEntry(999300, '1', 40);

        DB::table('judging_scores_bos')->insert([
            'eid' => $this->seeded[0]['eid'],
            'bid' => 999300,
            'scorePlace' => '1',
            'scoreEntry' => 45,
            'scoreType' => 1,
        ]);

        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => -1,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 0,
            'prefsBestUseBOS' => 1,
            'prefsWinnerMethod' => 0,
            'prefsFirstPlacePts' => 4,
            'prefsSecondPlacePts' => 0,
            'prefsThirdPlacePts' => 0,
            'prefsFourthPlacePts' => 0,
            'prefsHMPts' => 0,
            'prefsTieBreakRule1' => '',
            'prefsTieBreakRule2' => '',
            'prefsTieBreakRule3' => '',
            'prefsTieBreakRule4' => '',
            'prefsTieBreakRule5' => '',
            'prefsTieBreakRule6' => '',
        ]);

        $standings = BestBrewerStandings::forAwards(TenantContext::load());
        self::assertSame([2, 0, 0, 0, 0], $standings->brewerRows[0]->places);
        self::assertSame(8.0, $standings->brewerRows[0]->points);
    }

    public function test_top_n_caps_rows(): void
    {
        $table = $this->seedEntry(999300, '1', 40);
        // Two firsts for A (wins tie), one first for B → A > B.
        $this->seedEntryAt(999300, '1', $table, 41);
        $this->seedEntryAt(999301, '1', $table, 39);

        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => 1,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 1,
            'prefsBestUseBOS' => 0,
            'prefsWinnerMethod' => 0,
        ]);

        $standings = BestBrewerStandings::forAwards(TenantContext::load());
        self::assertCount(1, $standings->brewerRows);
    }

    /** @return int table id */
    private function seedEntry(int $uid, string $place, ?float $score): int
    {
        return $this->seedEntryAt($uid, $place, 0, $score);
    }

    private function seedEntryAt(int $uid, string $place, int $table, ?float $score): int
    {
        static $sharedTable = null;
        if ($table === 0) {
            $sharedTable ??= (int) DB::table('judging_tables')->insertGetId([
                'tableNumber' => 200,
                'tableName' => 'AWRD BB Table',
            ]);
            $table = (int) $sharedTable;
        }

        if (! DB::table('users')->where('id', $uid)->exists()) {
            DB::table('users')->insert([
                'id' => $uid,
                'user_name' => 'bb'.(string) $uid.'@x.com',
                'password' => password_hash('x', PASSWORD_BCRYPT),
                'userLevel' => 2,
            ]);
            DB::table('brewer')->insert([
                'uid' => $uid,
                'brewerFirstName' => 'Awrdbrewer',
                'brewerLastName' => 'Testerson',
                'brewerClubs' => 'Test Club',
            ]);
        }

        $eid = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'AWRD BB Brew '.($score ?? $place),
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewBrewerID' => $uid,
        ]);
        DB::table('judging_scores')->insert([
            'eid' => $eid,
            'scorePlace' => $place,
            'scoreTable' => $table,
            'scoreEntry' => $score ?? 40,
        ]);
        $this->seeded[] = ['eid' => $eid, 'uid' => $uid, 'table' => $table];

        return $table;
    }
}
