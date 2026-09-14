<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\AssignmentsController;
use Illuminate\Support\Facades\DB;

/**
 * Judge/steward sign-in sheets (legacy assignments.output.php view=sign-in).
 * Issue #38: the generated sheets omitted the assigned people entirely — no
 * name, BJCP ID or waiver — because assignments whose `assignLocation` did
 * not resolve to a `judging_locations` row were silently dropped. These pin
 * the prefill payload plus the per-role column contract.
 */
final class OutputSignInSheetsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'signin.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9401;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const JUDGE_ID = 9402;

    private const STEWARD_ID = 9403;

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $brewerIds = [];

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
    }

    protected function tearDown(): void
    {
        DB::table('judging_assignments')->whereIn('bid', $this->brewerIds ?: [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tableIds ?: [0])->delete();
        DB::table('judging_locations')->whereIn('id', $this->locationIds ?: [0])->delete();
        DB::table('brewer')->whereIn('uid', $this->brewerIds ?: [0])->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    public function test_judges_sign_in_sheet_prefills_name_bjcp_id_and_waiver(): void
    {
        $locationId = $this->location('Session A');
        $tableId = $this->table($locationId);
        $this->person(self::JUDGE_ID, 'Smith', 'John', 'abc123', 'Y');
        $this->assign(self::JUDGE_ID, $tableId, 'J', $locationId);

        $data = AssignmentsController::signInSheetsData('J');

        $sheet = $this->sheet($data['sheets'], 'Session A');
        self::assertNotNull($sheet);
        self::assertSame([
            ['name' => 'Smith, John', 'bjcpId' => 'ABC123', 'waiver' => 'Yes'],
        ], $sheet['members']);
    }

    public function test_assignments_without_a_matching_session_are_not_dropped(): void
    {
        // An assignment with no usable location (assignLocation NULL, no
        // table) must still reach the sheet instead of vanishing.
        $this->person(self::JUDGE_ID, 'Lone', 'Judge', 'zz9', 'N');
        $this->assign(self::JUDGE_ID, null, 'J', null);

        $data = AssignmentsController::signInSheetsData('J');

        self::assertNotSame([], $data['sheets'], 'unmatched assignment was dropped');
        $members = [];
        foreach ($data['sheets'] as $sheet) {
            $members = [...$members, ...$sheet['members']];
        }
        self::assertContains(
            ['name' => 'Lone, Judge', 'bjcpId' => 'ZZ9', 'waiver' => 'No'],
            $members,
        );
    }

    public function test_assignment_location_falls_back_to_the_tables_session(): void
    {
        $locationId = $this->location('Session B');
        $tableId = $this->table($locationId);
        $this->person(self::JUDGE_ID, 'Fallback', 'Fay', 'ff1', 'Y');
        // assignLocation unset, but the table names the session.
        $this->assign(self::JUDGE_ID, $tableId, 'J', null);

        $data = AssignmentsController::signInSheetsData('J');

        $sheet = $this->sheet($data['sheets'], 'Session B');
        self::assertNotNull($sheet);
        self::assertSame('Fallback, Fay', $sheet['members'][0]['name']);
    }

    public function test_steward_sheet_prefills_name_and_waiver_and_only_judges_show_bjcp_column(): void
    {
        $locationId = $this->location('Session C');
        $tableId = $this->table($locationId);
        $this->person(self::STEWARD_ID, 'Doe', 'Jane', 'sd7', 'Y');
        $this->assign(self::STEWARD_ID, $tableId, 'S', $locationId);

        $data = AssignmentsController::signInSheetsData('S');
        $sheet = $this->sheet($data['sheets'], 'Session C');
        self::assertNotNull($sheet);
        self::assertSame('Doe, Jane', $sheet['members'][0]['name']);
        self::assertSame('Yes', $sheet['members'][0]['waiver']);

        $stewardHtml = view('outputs.assignments-signin', [
            'roleLabel' => 'Steward',
            'contestName' => 'Test Competition',
            'sheets' => $data['sheets'],
            'blankRows' => $data['blankRows'],
        ])->render();
        self::assertStringContainsString('Doe, Jane', $stewardHtml);
        self::assertStringNotContainsString('BJCP ID', $stewardHtml);

        // Judges keep the BJCP ID column.
        $judgeHtml = view('outputs.assignments-signin', [
            'roleLabel' => 'Judge',
            'contestName' => 'Test Competition',
            'sheets' => $data['sheets'],
            'blankRows' => $data['blankRows'],
        ])->render();
        self::assertStringContainsString('BJCP ID', $judgeHtml);
    }

    public function test_sign_in_route_streams_pdf_with_the_expected_filename_contract(): void
    {
        $locationId = $this->location('Session PDF');
        $tableId = $this->table($locationId);
        $this->person(self::JUDGE_ID, 'Pdf', 'Pat', 'pp1', 'Y');
        $this->assign(self::JUDGE_ID, $tableId, 'J', $locationId);

        $response = $this->get('/admin/output/assignments?filter=judges&view=sign-in');
        $response->assertOk();
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('assignments-sign-in.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    private function location(string $name): int
    {
        $id = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => strtotime('2030-06-15 14:00 UTC'),
            'judgingLocName' => $name,
            'judgingLocation' => 'Hall',
            'judgingRounds' => 1,
        ]);
        $this->locationIds[] = $id;

        return $id;
    }

    private function table(int $locationId): int
    {
        $id = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Table '.$locationId,
            'tableNumber' => ((int) DB::table('judging_tables')->max('tableNumber')) + 1,
            'tableLocation' => $locationId,
        ]);
        $this->tableIds[] = $id;

        return $id;
    }

    private function person(int $uid, string $last, string $first, string $judgeId, string $waiver): void
    {
        DB::table('brewer')->insert([
            'uid' => $uid,
            'brewerFirstName' => $first,
            'brewerLastName' => $last,
            'brewerJudgeID' => $judgeId,
            'brewerJudgeWaiver' => $waiver,
        ]);
        $this->brewerIds[] = $uid;
    }

    private function assign(int $uid, ?int $tableId, string $role, ?int $location): void
    {
        DB::table('judging_assignments')->insert([
            'bid' => $uid,
            'assignment' => $role,
            'assignTable' => $tableId,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => $location,
        ]);
    }

    /**
     * @param  list<array{session: string, members: list<array{name: string, bjcpId: string, waiver: string}>}>  $sheets
     * @return array{session: string, members: list<array{name: string, bjcpId: string, waiver: string}>}|null
     */
    private function sheet(array $sheets, string $session): ?array
    {
        foreach ($sheets as $sheet) {
            if ($sheet['session'] === $session) {
                return $sheet;
            }
        }

        return null;
    }
}
