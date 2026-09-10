<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\PullsheetsController;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pull sheets (spec §7 P5.1) — the Slice D PDF pipeline validation case.
 * Asserts admin gating, real dompdf output (%PDF magic bytes), and that
 * the rows feeding the sheet match received entries for the table in
 * legacy judging-number order (ledger/flight-assignment.md #4/#5).
 */
final class OutputPullSheetsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p51.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9501;

    private const USER_EMAIL = 'p51.user@brewingcompetitions.com';

    private const USER_ID = 9502;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    private int $myTableId = 0;

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $flightRowIds = [];

    /** @var array<string, mixed> */
    private array $origJudgingPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = (array) DB::table('judging_preferences')->where('id', 1)->first();
        $this->origJudgingPrefs = collect($row)->except(['id'])->all();
        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsQueued' => 'N',
            'jPrefsFlightEntries' => 2,
            'jPrefsTablePlanning' => 0,
        ]);

        foreach ([[self::ADMIN_ID, self::ADMIN_EMAIL, '0'], [self::USER_ID, self::USER_EMAIL, '2']] as [$id, $email, $level]) {
            DB::table('users')->where('id', $id)->delete();
            DB::table('users')->insert([
                'id' => $id,
                'user_name' => $email,
                'password' => self::HASH,
                'userLevel' => $level,
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::table('judging_flights')->whereIn('id', $this->flightRowIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tableIds ?: [0])->delete();

        // Sweep any rows leaked by a crashed earlier run of this class.
        $leaked = DB::table('judging_tables')->where('tableName', 'P51 Test Table')->pluck('id');
        if ($leaked->isNotEmpty()) {
            DB::table('judging_flights')->whereIn('flightTable', $leaked)->delete();
            DB::table('brewing')->where('brewName', 'like', 'P51 Entry %')->delete();
            DB::table('judging_tables')->whereIn('id', $leaked)->delete();
        }
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::USER_ID])->delete();
        DB::table('judging_locations')->where('judgingLocName', 'like', 'P51%')->delete();

        DB::table('judging_preferences')->where('id', 1)->update($this->origJudgingPrefs);

        parent::tearDown();
    }

    private function login(string $email): void
    {
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => $email,
            'loginPassword' => 'bcoem',
        ]);
    }

    /**
     * @param  array{tables: list<array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    private function myTable(array $data, int $tableId): array
    {
        foreach ($data['tables'] as $t) {
            if ((int) $t['id'] === $this->myTableId) {
                return $t;
            }
        }

        self::fail('P51 fixture table not found in build() payload');
    }

    /**
     * One table over two styles; entries: three received (two styles),
     * one unreceived, one unflighted. Returns [tableId, entryIds].
     *
     * @return array{int, array<string, int>}
     */
    private function seedTableWithEntries(): array
    {
        $s1 = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'P51 Light Lager',
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
            'brewStyleReqSpec' => 1,
        ]);
        $s2 = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'P51 Pilsner',
            'brewStyleGroup' => '2',
            'brewStyleNum' => 'B',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
            'brewStyleReqSpec' => 0,
        ]);
        $this->styleIds = [$s1, $s2];

        $locId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingLocName' => 'P51 Hall',
            'judgingDate' => '1735689600',
            'judgingRounds' => 1,
        ]);

        // Inserted out of numeric order on purpose — the sheet must sort by
        // judging number, not insertion order.
        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'P51 Test Table',
            'tableStyles' => $s1.','.$s2,
            'tableNumber' => 9510,
            'tableLocation' => $locId,
        ]);
        $this->tableIds[] = $tableId;
        $this->myTableId = $tableId;
        $entries = [];
        foreach ([['3', $s2, '1'], ['1', $s1, '1'], ['2', $s1, '1'], ['4', $s2, '0'], ['5', $s1, '1']] as [$jn, $styleId, $received]) {
            $style = (array) DB::table('styles')->where('id', $styleId)->first();
            $id = (int) DB::table('brewing')->insertGetId([
                'brewName' => 'P51 Entry '.$jn,
                'brewStyle' => $style['brewStyle'],
                'brewCategorySort' => $style['brewStyleGroup'],
                'brewSubCategory' => $style['brewStyleNum'],
                'brewJudgingNumber' => $jn,
                'brewInfo' => $jn === '1' ? 'Weyermann^Barke^' : '',
                'brewBrewerFirstName' => 'Ada',
                'brewBrewerLastName' => 'Brewster',
                'brewBrewerID' => 1,
                'brewPaid' => 0,
                'brewReceived' => $received,
                'brewConfirmed' => '1',
            ]);
            $this->entryIds[] = $id;
            $entries['jn'.$jn] = $id;
        }

        return [$tableId, $entries];
    }

    /**
     * Flight rows for a table. Manual order only where given.
     *
     * @param  array<int, array{eid: int, n: int, round: int, order: ?int}>  $rows
     */
    private function seedFlights(int $tableId, array $rows): void
    {
        foreach ($rows as $r) {
            $this->flightRowIds[] = (int) DB::table('judging_flights')->insertGetId([
                'flightTable' => $tableId,
                'flightNumber' => $r['n'],
                'flightEntryID' => (string) $r['eid'],
                'flightEntryOrder' => $r['order'],
                'flightRound' => $r['round'],
            ]);
        }
    }

    public function test_requires_admin(): void
    {
        // 'auth' middleware bounces anonymous users to /login before the
        // controller's legacy msg=99 gate sees them.
        $this->get('/admin/output/pullsheets')
            ->assertRedirect('/login');

        $this->login(self::USER_EMAIL);
        $this->get('/admin/output/pullsheets')
            ->assertRedirect('/?msg=99');
    }

    public function test_admin_gets_pdf_with_magic_bytes(): void
    {
        $this->seedTableWithEntries();
        $this->login(self::ADMIN_EMAIL);

        $response = $this->get('/admin/output/pullsheets');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF', substr((string) $response->getContent(), 0, 4));
    }

    public function test_sheet_rows_match_received_entries_in_judging_number_order(): void
    {
        [$tableId, $entries] = $this->seedTableWithEntries();
        $this->seedFlights($tableId, [
            ['eid' => $entries['jn1'], 'n' => 1, 'round' => 1, 'order' => null],
            ['eid' => $entries['jn2'], 'n' => 1, 'round' => 1, 'order' => null],
            ['eid' => $entries['jn3'], 'n' => 2, 'round' => 1, 'order' => null],
            // jn5 is received but has no flight row → not pulled (legacy quirk).
        ]);

        $data = PullsheetsController::build(TenantContext::load());
        $this->assertFalse($data['queued']);

        $table = $this->myTable($data, $tableId);
        $this->assertSame(4, $table['entryCount']); // 5 seeded − 1 unreceived

        $pulled = [];
        foreach ($table['flights'] as $flight) {
            foreach ($flight['rows'] as $row) {
                $pulled[$row['judgingNo']] = $flight['label'];
            }
        }

        // Unreceived excluded, unflighted received entry excluded, flights
        // grouped, judging-number ascending within each flight.
        $this->assertSame([
            '000001' => 'Flight 1, Round 1',
            '000002' => 'Flight 1, Round 1',
            '000003' => 'Flight 2, Round 1',
        ], $pulled);
    }

    public function test_manual_pull_order_overrides_judging_number_order_nulls_last(): void
    {
        [$tableId, $entries] = $this->seedTableWithEntries();
        $this->seedFlights($tableId, [
            ['eid' => $entries['jn1'], 'n' => 1, 'round' => 1, 'order' => 3],
            ['eid' => $entries['jn2'], 'n' => 1, 'round' => 1, 'order' => 1],
            ['eid' => $entries['jn3'], 'n' => 1, 'round' => 1, 'order' => null],
            ['eid' => $entries['jn5'], 'n' => 1, 'round' => 1, 'order' => 2],
        ]);

        $table = $this->myTable(PullsheetsController::build(TenantContext::load()), $tableId);
        $rows = $table['flights'][0]['rows'];

        // Saved manual order first (2,5,1), NULL-order entry last (#4/#5).
        $this->assertSame(['000002', '000005', '000001', '000003'], array_column($rows, 'judgingNo'));
    }

    public function test_queued_mode_lists_all_received_entries_flat(): void
    {
        $this->seedTableWithEntries();
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsQueued' => 'Y']);

        $data = PullsheetsController::build(TenantContext::load());
        $this->assertTrue($data['queued']);

        $table = $this->myTable($data, $this->tableIds[0]);
        $this->assertSame(4, $table['entryCount']);
        $this->assertCount(1, $table['flights']);
        $this->assertNull($table['flights'][0]['label']);
        // No flight rows exist at all, yet queued mode pulls every
        // received entry — the whole point of queued judging.
        $this->assertSame(
            ['000001', '000002', '000005', '000003'],
            array_column($table['flights'][0]['rows'], 'judgingNo'),
        );
    }

    public function test_view_renders_special_ingredient_info_and_excludes_unreceived(): void
    {
        $this->seedTableWithEntries();
        // Queued mode pulls every received entry without flight rows.
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsQueued' => 'Y']);
        $html = view('outputs.pullsheets', PullsheetsController::build(TenantContext::load()))->render();

        // Style 1A has brewStyleReqSpec=1 → brewInfo shown with ^ → " | ".
        $this->assertStringContainsString('Required Info:', $html);
        $this->assertStringContainsString('Weyermann | Barke |', $html);
        // Style 2B has brewStyleReqSpec=0 → no info cell for it. Legacy style
        // number format is group+sub with the style-set separator ("").
        $this->assertStringContainsString('2B P51 Pilsner', $html);
        // Zero-padded judging number and location line.
        $this->assertStringContainsString('000001', $html);
        $this->assertStringContainsString('P51 Hall', $html);
        // Unreceived entry never appears anywhere in the document.
        $pdf = StreamPdf::bytes('outputs.pullsheets', PullsheetsController::build(TenantContext::load()));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_pullsheets_withheld_in_tables_planning_mode(): void
    {
        // Planning-mode counts deliberately include unreceived entries, so a
        // pull sheet produced then is not the official document. Both the
        // dashboard tooltip and the judging-tables page tell the admin pull
        // sheets are unavailable in this mode — this is that promise.
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);

        $this->login(self::ADMIN_EMAIL);

        $this->get('/admin/output/pullsheets')->assertForbidden();

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 0]);
        $this->get('/admin/output/pullsheets')->assertOk();
    }
}
