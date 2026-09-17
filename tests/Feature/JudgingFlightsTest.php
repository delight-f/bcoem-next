<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Flight definition grid (spec §6 P4.3, ledger/flight-assignment.md):
 * manual radio assignment per entry (#7) writing schema-exact
 * judging_flights rows via FlightAssignment::flightRow() (#5), the
 * received-only vs table-planning entry scope (#2), and admin gating.
 */
final class JudgingFlightsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'flights.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9410;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var array<string, mixed> */
    private array $origJudgingPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = (array) DB::table('judging_preferences')->where('id', 1)->first();
        $this->origJudgingPrefs = collect($row)->except(['id'])->all();
        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsFlightEntries' => 2,
            'jPrefsTablePlanning' => 0,
        ]);

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
    }

    protected function tearDown(): void
    {
        DB::table('judging_flights')->whereIn('flightTable', $this->tableIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tableIds ?: [0])->delete();
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        DB::table('judging_preferences')->where('id', 1)->update($this->origJudgingPrefs);

        parent::tearDown();
    }

    /** @param array<string, mixed> $styleOverrides */
    private function seedTable(array $styleOverrides = []): int
    {
        $styleId = (int) DB::table('styles')->insertGetId(array_merge([
            'brewStyle' => 'Light Lager Fixture',
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
        ], $styleOverrides));
        $this->styleIds[] = $styleId;

        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Light Lager',
            'tableStyles' => (string) $styleId,
            'tableNumber' => 900 + $styleId % 50,
            'tableLocation' => 1,
        ]);
        $this->tableIds[] = $tableId;

        return $tableId;
    }

    private function seedEntry(int $tableId, string $judgingNumber, string $received = '1'): int
    {
        $style = explode(',', (string) DB::table('judging_tables')->where('id', $tableId)->value('tableStyles'));
        $s = (array) DB::table('styles')->where('id', $style[0])->first();

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Entry '.$judgingNumber,
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

    public function test_radio_assignment_writes_exact_flight_row_shape(): void
    {
        $tableId = $this->seedTable();
        $e1 = $this->seedEntry($tableId, '101');
        $e2 = $this->seedEntry($tableId, '102');
        $e3 = $this->seedEntry($tableId, '103');

        // 3 entries / 2 per flight → 2 proposed flights; radios post 1..2.
        $response = $this->post('/admin/judging/flights/'.$tableId, [
            'flights' => [$e1 => '1', $e2 => '2', $e3 => '2'],
        ]);
        $response->assertRedirect('/admin/judging/flights/'.$tableId);

        foreach ([[$e1, 1], [$e2, 2], [$e3, 2]] as [$entryId, $flightNumber]) {
            $found = DB::table('judging_flights')
                ->where('flightTable', $tableId)
                ->where('flightEntryID', (string) $entryId)
                ->first();
            self::assertNotNull($found);
            $row = (array) $found;

            self::assertSame($tableId, (int) $row['flightTable']);
            self::assertSame($flightNumber, (int) $row['flightNumber']);
            self::assertSame((string) $entryId, $row['flightEntryID']); // mediumtext column
            self::assertSame(1, (int) $row['flightRound']);
            self::assertNull($row['flightEntryOrder']);
        }
    }

    public function test_repost_updates_existing_rows_instead_of_duplicating(): void
    {
        $tableId = $this->seedTable();
        $e1 = $this->seedEntry($tableId, '201');
        $this->seedEntry($tableId, '202');
        $this->seedEntry($tableId, '203'); // 3 entries / 2 per flight → 2 flights

        $this->post('/admin/judging/flights/'.$tableId, ['flights' => [$e1 => '1']]);
        $this->post('/admin/judging/flights/'.$tableId, ['flights' => [$e1 => '2']]);
        self::assertSame(
            1,
            DB::table('judging_flights')->where('flightTable', $tableId)->count(),
        );
        self::assertSame(
            2,
            (int) DB::table('judging_flights')->where('flightEntryID', (string) $e1)->value('flightNumber'),
        );
    }

    public function test_grid_shows_received_entries_and_proposed_count(): void
    {
        $tableId = $this->seedTable();
        $received = $this->seedEntry($tableId, '301', '1');
        $this->seedEntry($tableId, '302', '1');
        $unreceived = $this->seedEntry($tableId, '303', '0');

        $response = $this->get('/admin/judging/flights/'.$tableId);
        $response->assertOk();

        $html = $response->getContent() ?: '';
        self::assertStringContainsString('<td>301</td>', $html);
        self::assertStringContainsString('<td>302</td>', $html);
        // Unreceived entries never reach the grid (ledger #2); assert on
        // the row cell, not the bare number (collides with CDN hashes).
        self::assertStringNotContainsString('<td>303</td>', $html);

        self::assertTrue(DB::table('judging_flights')->where('flightEntryID', (string) $received)->doesntExist());
        self::assertTrue(DB::table('judging_flights')->where('flightEntryID', (string) $unreceived)->doesntExist());
    }

    public function test_planning_mode_includes_unreceived_entries(): void
    {
        $tableId = $this->seedTable();
        $this->seedEntry($tableId, '401', '1');
        $planningEntry = $this->seedEntry($tableId, '402', '0');

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);

        $response = $this->get('/admin/judging/flights/'.$tableId);
        self::assertStringContainsString('402', (string) $response->getContent());

        // Planning rows land in flight 1 with a NULL order (ledger #5).
        $this->post('/admin/judging/flights/'.$tableId, [
            'flights' => [$planningEntry => '1'],
        ])->assertRedirect('/admin/judging/flights/'.$tableId);

        $row = (array) DB::table('judging_flights')
            ->where('flightEntryID', (string) $planningEntry)->first();
        self::assertSame(1, (int) $row['flightNumber']);
        self::assertNull($row['flightEntryOrder']);
    }

    public function test_save_reports_its_outcome_instead_of_redirecting_silently(): void
    {
        $tableId = $this->seedTable();
        $e1 = $this->seedEntry($tableId, '501');
        $this->seedEntry($tableId, '502');
        $this->seedEntry($tableId, '503'); // 3 entries / 2 per flight → 2 flights

        // A real assignment is confirmed on the reloaded grid.
        $this->followingRedirects()
            ->post('/admin/judging/flights/'.$tableId, ['flights' => [$e1 => '2']])
            ->assertOk()
            ->assertSee('Flight assignment saved.');

        // No valid entry posted: the save must say so, not reload silently.
        $this->followingRedirects()
            ->post('/admin/judging/flights/'.$tableId, ['flights' => [999999 => '1']])
            ->assertOk()
            ->assertSee('No flight assignments were saved');
    }

    public function test_requires_admin(): void
    {
        $tableId = $this->seedTable();

        // Authenticated but non-admin (userLevel 2): the controller gate
        // kicks in with the legacy msg=99 redirect.
        $uid = self::ADMIN_ID + 1;
        DB::table('users')->insert([
            'id' => $uid,
            'user_name' => 'flights.entrant@brewingcompetitions.com',
            'userLevel' => '2',
            'password' => self::HASH,
            'userCreated' => '2024-01-01 00:00:01',
        ]);
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => 'flights.entrant@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/admin/judging/flights')->assertRedirect('/?msg=99');
        $this->post('/admin/judging/flights/'.$tableId, ['flights' => [1 => '1']])
            ->assertRedirect('/?msg=99');

        DB::table('users')->where('id', $uid)->delete();
    }
}
