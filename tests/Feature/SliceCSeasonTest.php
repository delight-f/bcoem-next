<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Slice C scripted season simulation (spec §6 P4.8). Drives the whole
 * judging season leg over HTTP in one flow — config → flight proposal +
 * manual radio assignment (#7) → barcode check-in → score entry → BOS —
 * on a seeded fixture, then asserts the final DB rows the public results
 * surfaces read.
 *
 * This is the DB-state convergence proof for the auth-gated Slice C
 * surfaces (parity/slice-c precedent from Slice B: gated flows are not
 * page-diffed).
 */
final class SliceCSeasonTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'season.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9450;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var array<string, mixed>|null */
    private ?array $origStyleType = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);

        // Remove leftovers from any previously failed run.
        $staleTables = DB::table('judging_tables')->where('tableName', 'Season Table')->pluck('id');
        foreach ($staleTables as $id) {
            DB::table('judging_tables')->where('id', $id)->delete();
        }
        DB::table('styles')->where('brewStyle', 'Season Lager')->delete();
        DB::table('judging_locations')->where('judgingLocName', 'Season Hall A')->delete();
        $staleEntries = DB::table('brewing')->where('brewName', 'like', 'Season Entry %')->pluck('id');
        foreach ($staleEntries as $id) {
            DB::table('judging_flights')->where('flightEntryID', (string) $id)->delete();
            DB::table('judging_scores')->where('eid', $id)->delete();
            DB::table('judging_scores_bos')->where('eid', $id)->delete();
            DB::table('brewing')->where('id', $id)->delete();
        }

        $orig = DB::table('style_types')->where('id', 1)->first();
        $this->origStyleType = $orig === null ? null : (array) $orig;
        DB::table('style_types')->where('id', 1)->update(['styleTypeBOSMethod' => '1']);
    }

    protected function tearDown(): void
    {
        if ($this->origStyleType !== null) {
            DB::table('style_types')->where('id', 1)->update($this->origStyleType);
        }
        foreach ($this->entryIds as $id) {
            DB::table('judging_flights')->where('flightEntryID', (string) $id)->delete();
            DB::table('judging_scores')->where('eid', $id)->delete();
            DB::table('judging_scores_bos')->where('eid', $id)->delete();
            DB::table('brewing')->where('id', $id)->delete();
        }
        foreach ($this->styleIds as $id) {
            DB::table('styles')->where('id', $id)->delete();
        }
        foreach ($this->tableIds as $id) {
            DB::table('judging_tables')->where('id', $id)->delete();
        }
        foreach ($this->locationIds as $id) {
            DB::table('judging_locations')->where('id', $id)->delete();
        }
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    public function test_full_season_leg_config_assign_checkin_score_bos(): void
    {
        // ── Season step 1: organizer configures a judging session + table.
        $this->post('/admin/judging/locations', [
            'judgingLocName' => 'Season Hall A',
            'judgingLocation' => 'Main Hall',
            'judgingDate' => '2026-05-02 09:00',
            'judgingLocType' => '0',
            'judgingRounds' => '1',
        ])->assertRedirect('/admin/judging/locations');
        $locationId = (int) DB::table('judging_locations')->max('id');
        $this->locationIds[] = $locationId;

        $styleId = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'Season Lager',
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
            'brewStyleType' => 1,
        ]);
        $this->styleIds[] = $styleId;

        $this->post('/admin/judging/tables', [
            'tableName' => 'Season Table',
            'tableNumber' => '950',
            'tableLocation' => (string) $locationId,
            'tableEntryLimit' => '',
            'tableStyles' => [$styleId],
        ])->assertRedirect('/admin/judging/tables');
        $tableId = (int) DB::table('judging_tables')->where('tableName', 'Season Table')->value('id');
        $this->tableIds[] = $tableId;

        // ── Step 2: three received entries arrive at the table.
        $e1 = $this->seedEntry('100001', '1');
        $e2 = $this->seedEntry('100002', '1');
        $e3 = $this->seedEntry('100003', '0'); // still at dropoff — checked in later

        // Flight proposal: ceil(2 received / jPrefsFlightEntries=2) = 1 flight.
        $grid = $this->get('/admin/judging/flights/'.$tableId);
        $grid->assertOk();

        // Manual radio assignment writes schema-exact flight rows (#5/#7).
        $this->post('/admin/judging/flights/'.$tableId, [
            'flights' => [$e1 => '1', $e2 => '1'],
        ])->assertRedirect('/admin/judging/flights/'.$tableId);
        $flightRows = DB::table('judging_flights')->where('flightTable', $tableId)->count();
        $this->assertSame(2, $flightRows);

        // ── Step 3: barcode check-in receives the third entry. Paid/
        // confirmed/judging-number are untouched by check-in.
        $this->post('/admin/judging/checkin', ['scan' => '100003'])
            ->assertRedirect('/admin/judging/checkin?ok=100003');
        $this->assertSame('1', (string) DB::table('brewing')->where('id', $e3)->value('brewReceived'));

        // ── Step 4: score entry — winner takes 1st, runner-up HM ('5').
        $this->put('/admin/judging/scores/'.$tableId, [
            'score_id' => ['k1', 'k2'],
            'eidk1' => (string) $e1,
            'bidk1' => '',
            'scoreEntryk1' => '38.5',
            'scorePlacek1' => '1',
            'scoreMiniBOSk1' => '1',
            'scoreTypek1' => '1',
            'eidk2' => (string) $e2,
            'bidk2' => '',
            'scoreEntryk2' => '34',
            'scorePlacek2' => '5',
            'scoreMiniBOSk2' => '',
            'scoreTypek2' => '1',
        ])->assertRedirect('/admin/judging/scores');

        $scores = DB::table('judging_scores')->where('scoreTable', $tableId)->orderBy('eid')->get();
        $this->assertCount(2, $scores);
        $first = $scores[0];
        self::assertNotNull($first);
        $this->assertSame($e1, (int) $first->eid);
        $this->assertSame('38.5', (string) $first->scoreEntry);
        $this->assertSame('1', (string) $first->scorePlace);
        $this->assertSame(1, (int) $first->scoreMiniBOS); // posted checkbox
        $hm = $scores[1];
        self::assertNotNull($hm);
        $this->assertSame('5', (string) $hm->scorePlace); // HM stores '5'
        $this->assertSame(0, (int) $hm->scoreMiniBOS); // empty box ⇒ 0, never NULL

        // ── Step 5: BOS round — method 1 admits 1st place only.
        $this->put('/admin/judging/bos/1', [
            'score_id' => ['w1'],
            'eidw1' => (string) $e1,
            'bidw1' => '',
            'scoreEntryw1' => '38.5',
            'scorePlacew1' => '1',
            'scoreTypew1' => '1',
        ])->assertRedirect('/admin/judging/bos');

        $bos = DB::table('judging_scores_bos')->where('eid', $e1)->first();
        $this->assertNotNull($bos);
        $this->assertSame('1', (string) $bos->scorePlace);
        $this->assertSame(0, (int) DB::table('judging_scores_bos')->where('eid', $e2)->count(),
            'HM row must be ineligible for BOS under method 1');

        // ── Final: the winners filter the public pages use still sees every
        // placed entry (scorePlace IN ('1','2','3','4','5')).
        $placed = DB::table('judging_scores')
            ->whereIn('scorePlace', ['1', '2', '3', '4', '5'])
            ->whereIn('eid', [$e1, $e2])
            ->count();
        $this->assertSame(2, $placed);
    }

    private function seedEntry(string $judgingNumber, string $received): int
    {
        $s = (array) DB::table('styles')->where('id', $this->styleIds[0])->first();

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Season Entry '.$judgingNumber,
            'brewStyle' => $s['brewStyle'],
            'brewCategory' => $s['brewStyleGroup'],
            'brewCategorySort' => $s['brewStyleGroup'],
            'brewSubCategory' => $s['brewStyleNum'],
            'brewJudgingNumber' => $judgingNumber,
            'brewBrewerID' => 1,
            'brewPaid' => 0,
            'brewReceived' => $received,
            'brewConfirmed' => '1',
        ]);
        $this->entryIds[] = $entryId;

        return $entryId;
    }
}
