<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\PullsheetsController;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pull-sheet variant matrix (spec §7 P5.1): the legacy ?go=... dispatch and
 * per-family copy. The default all-tables sheet is covered by
 * OutputPullSheetsTest; here we cover the other go families (mini_bos,
 * judging_scores_bos, all_entry_info, judging_locations) and the
 * view/filter selection of the judging_tables family against the same
 * anon-base-shaped fixture.
 */
final class OutputPullSheetsVariantTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'pv.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9601;

    private const USER_EMAIL = 'pv.user@brewingcompetitions.com';

    private const USER_ID = 9602;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $flightRowIds = [];

    private int $tableId = 0;

    private int $locationId = 0;

    /** @var array<string, mixed> */
    private array $origJudgingPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = (array) DB::table('judging_preferences')->where('id', 1)->first();
        $this->origJudgingPrefs = collect($row)->except(['id'])->all();
        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsQueued' => 'N',
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
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
        DB::table('judging_locations')->where('id', $this->locationId)->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::USER_ID])->delete();
        DB::table('judging_assignments')->where('assignTable', $this->tableId)->delete();
        DB::table('judging_flights')->where('flightTable', $this->tableId)->delete();

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
     * One table, one location, two styles, two received entries (one per
     * style), both in flight 1 with no manual order. Returns nothing.
     *
     * @return array{entry1: int, entry2: int}
     */
    private function seedStyle(): array
    {
        $s1 = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'PV Light Lager',
            'brewStyleGroup' => '01',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
            'brewStyleReqSpec' => 1,
        ]);
        $s2 = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'PV Pilsner',
            'brewStyleGroup' => '02',
            'brewStyleNum' => 'B',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
            'brewStyleReqSpec' => 0,
        ]);
        $this->styleIds = [$s1, $s2];

        $this->locationId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingLocName' => 'PV Hall',
            'judgingDate' => '1735689600',
            'judgingRounds' => 1,
        ]);

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'PV Test Table',
            'tableStyles' => $s1.','.$s2,
            'tableNumber' => 9610,
            'tableLocation' => $this->locationId,
        ]);
        $this->tableIds[] = $this->tableId;

        $eids = [];
        foreach ([['1', $s1, '1', 'Alpha^Beta^'], ['2', $s2, '1', '']] as [$jn, $styleId, $received, $info]) {
            $style = (array) DB::table('styles')->where('id', $styleId)->first();
            $id = (int) DB::table('brewing')->insertGetId([
                'brewName' => 'PV Entry '.$jn,
                'brewStyle' => $style['brewStyle'],
                'brewCategorySort' => $style['brewStyleGroup'],
                'brewSubCategory' => $style['brewStyleNum'],
                'brewJudgingNumber' => $jn,
                'brewInfo' => $info,
                'brewBrewerFirstName' => 'Ada',
                'brewBrewerLastName' => 'Lovelace',
                'brewBrewerID' => 1,
                'brewPaid' => 0,
                'brewReceived' => $received,
                'brewConfirmed' => '1',
            ]);
            $this->entryIds[] = $id;
            $eids[$jn] = $id;
        }

        // Both entries in flight 1, round 1, no manual order.
        foreach ($eids as $id) {
            $this->flightRowIds[] = (int) DB::table('judging_flights')->insertGetId([
                'flightTable' => $this->tableId,
                'flightNumber' => 1,
                'flightEntryID' => (string) $id,
                'flightEntryOrder' => null,
                'flightRound' => 1,
            ]);
        }

        return $eids;
    }

    public function test_variant_urls_return_pdf_with_filename(): void
    {
        $this->seedStyle();
        $this->login(self::ADMIN_EMAIL);

        $urls = [
            '/admin/output/pullsheets',
            '/admin/output/pullsheets?view=entry',
            '/admin/output/pullsheets?go=judging_tables&filter=mini_bos&id=default',
            '/admin/output/pullsheets?go=judging_locations&location='.$this->locationId.'&round=1',
            '/admin/output/pullsheets?go=mini_bos',
            '/admin/output/pullsheets?go=mini_bos&view=entry',
            '/admin/output/pullsheets?go=judging_scores_bos',
            '/admin/output/pullsheets?go=judging_scores_bos&id=1&view=entry',
            '/admin/output/pullsheets?go=judging_scores_bos&action=pro-am&filter=1&id=1',
            '/admin/output/pullsheets?go=all_entry_info',
            '/admin/output/pullsheets?go=all_entry_info&view=entry',
            '/admin/output/pullsheets?go=all_entry_info&view=judge_inventory&filter=J',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $response->assertHeader('Content-Disposition', 'inline; filename="pullsheets.pdf"');
            $this->assertSame('%PDF', substr((string) $response->getContent(), 0, 4), $url);
        }
    }

    public function test_requires_admin_for_variants(): void
    {
        $this->seedStyle();
        // 'auth' middleware bounces anonymous to /login.
        $this->get('/admin/output/pullsheets?go=mini_bos')->assertRedirect('/login');
        $this->login(self::USER_EMAIL);
        $this->get('/admin/output/pullsheets?go=mini_bos')->assertRedirect('/?msg=99');
    }

    public function test_view_entry_shows_entry_numbers_in_number_column(): void
    {
        $eids = $this->seedStyle();
        $data = PullsheetsController::tableReport(TenantContext::load(), $this->params(['view' => 'entry']));
        $html = view('outputs.pullsheets', $data)->render();

        $this->assertStringContainsString(sprintf('%06d', $eids['1']), $html);
        $this->assertStringContainsString(sprintf('%06d', $eids['2']), $html);
        // Style-grouped order: style 01A (group "01") before style 02B.
        $e1 = sprintf('%06d', $eids['1']);
        $e2 = sprintf('%06d', $eids['2']);
        $this->assertLessThan(strpos($html, $e2), strpos($html, $e1));
    }

    public function test_queued_mode_keeps_style_grouped_order(): void
    {
        $this->seedStyle();
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsQueued' => 'Y']);
        $data = PullsheetsController::build(TenantContext::load());
        $this->assertTrue($data['queued']);
        $this->assertCount(1, $data['tables'][0]['flights']);

        $flat = $data['tables'][0]['flights'][0]['rows'];
        // Style 01A entries first, then 02B (judging number within style).
        $this->assertSame(['000001', '000002'], array_column($flat, 'judgingNo'));
    }

    public function test_mini_bos_empty_state_copy(): void
    {
        $this->seedStyle();
        $data = PullsheetsController::miniBosReport(TenantContext::load(), $this->params());
        $html = view('outputs.pullsheets_mini', $data)->render();

        $this->assertStringContainsString('Mini-BOS', $html);
        $this->assertStringContainsString('No Mini-BOS entries were found.', $html);
    }

    public function test_bos_empty_state_copy(): void
    {
        $this->seedStyle();
        $data = PullsheetsController::bosReport(TenantContext::load(), $this->params());
        $html = view('outputs.pullsheets_bos', $data)->render();

        $this->assertStringContainsString('Best of Show: Beer', $html);
        $this->assertStringContainsString('No BOS entries were found for Beer.', $html);
    }

    public function test_all_entry_info_shows_only_entries_with_additional_info(): void
    {
        $this->seedStyle();
        $data = PullsheetsController::allInfoReport(TenantContext::load(), $this->params());
        $html = view('outputs.pullsheets_all_info', $data)->render();

        $this->assertStringContainsString('Entries with Additional Info', $html);
        // Entry 1 (style 01A, reqSpec 1) has brewInfo → shown.
        $this->assertStringContainsString('Alpha | Beta |', $html);
        // Entry 2 (style 02B, reqSpec 0, no info) has no additional info → absent.
        $this->assertStringNotContainsString('PV Pilsner', $html);
    }

    public function test_judge_inventory_headers(): void
    {
        $this->seedStyle();
        // Assign a judge (brewer uid 1 exists in baseline) to the table/flight.
        $judgeId = DB::table('brewer')->where('uid', 1)->value('id');
        if ($judgeId) {
            DB::table('judging_assignments')->where('assignTable', $this->tableId)->delete();
            DB::table('judging_assignments')->insert([
                'bid' => $judgeId,
                'assignment' => 'J',
                'assignTable' => $this->tableId,
                'assignFlight' => 1,
                'assignRound' => 1,
                'assignLocation' => $this->locationId,
            ]);
        }

        $data = PullsheetsController::allInfoReport(TenantContext::load(), $this->params(['view' => 'judge_inventory', 'filter' => 'J']));
        $html = view('outputs.pullsheets_all_info', $data)->render();

        $this->assertStringContainsString('Judging Inventory for', $html);
        $this->assertStringContainsString('Entries to Judge', $html);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function params(array $overrides = []): array
    {
        return array_merge([
            'go' => 'judging_tables', 'view' => 'default', 'filter' => 'default',
            'action' => 'default', 'id' => 'default', 'location' => 'default',
            'round' => 'default', 'sort' => 'default',
        ], $overrides);
    }
}
