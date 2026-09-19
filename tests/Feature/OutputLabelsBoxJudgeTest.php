<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\LabelsController;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Box labels, virtual judge labels, staff nametags and all-judge
 * scoresheet labels (output/labels.output.php go=judging_tables and
 * go=participants judging_nametags/judging_labels).
 *
 * Seeds a staff judge (staff_judge=1, brewerJudge=Y), a virtual judge at a
 * judgingLocType=1 location, and a judging table referencing a baseline
 * style so each branch is exercised against the real schema.
 */
final class OutputLabelsBoxJudgeTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p52j.admin@brewingcompetitions.com';

    private const ADMIN_ID = 52511;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const NON_ADMIN_ID = 52512;

    private const PREFIX = 'P52j';

    private const STAFF_UID = 90003;      // brewer.uid / staff.uid for the staff judge

    private const VIRTUAL_UID = 90004;    // brewer.uid for the virtual judge

    private const LOCATION_ID = 90005;    // judging_locations.id (virtual)

    private const TABLE_ID = 90006;       // judging_tables.id

    /** baseline_styles.id for the box-label style list (21B BJCP2021). */
    private const STYLE_ID = 517;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        DB::table('judging_assignments')->where('assignTable', self::TABLE_ID)->delete();
        DB::table('judging_tables')->where('id', self::TABLE_ID)->delete();
        DB::table('judging_locations')->where('id', self::LOCATION_ID)->delete();
        DB::table('staff')->whereIn('uid', [self::STAFF_UID, self::VIRTUAL_UID])->delete();
        DB::table('brewer')->whereIn('uid', [self::STAFF_UID, self::VIRTUAL_UID])->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::NON_ADMIN_ID])->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    private function ctx(): TenantContext
    {
        return TenantContext::load();
    }

    private function seedFixtures(): void
    {
        // Staff judge (staff_judge=1, brewerJudge=Y) -> nametag + judging label.
        DB::table('brewer')->where('uid', self::STAFF_UID)->delete();
        DB::table('brewer')->insert([
            'id' => self::STAFF_UID,
            'uid' => self::STAFF_UID,
            'brewerFirstName' => self::PREFIX.'First',
            'brewerLastName' => self::PREFIX.'Last',
            'brewerCity' => 'Austin',
            'brewerState' => 'TX',
            'brewerEmail' => self::PREFIX.'.judge@brewingcompetitions.com',
            'brewerJudge' => 'Y',
            'brewerJudgeRank' => 'Certified',
            'brewerJudgeID' => '',
            'brewerJudgeMead' => 'N',
            'brewerJudgeCider' => 'N',
        ]);
        DB::table('staff')->where('uid', self::STAFF_UID)->delete();
        DB::table('staff')->insert([
            'uid' => self::STAFF_UID,
            'staff_judge' => 1,
            'staff_steward' => 0,
            'staff_staff' => 0,
            'staff_organizer' => 0,
        ]);

        // Virtual judge (brewerJudge=Y, brewerJudgeLocation='Y-<virt-id>').
        DB::table('brewer')->where('uid', self::VIRTUAL_UID)->delete();
        DB::table('brewer')->insert([
            'id' => self::VIRTUAL_UID,
            'uid' => self::VIRTUAL_UID,
            'brewerFirstName' => self::PREFIX.'Virtual',
            'brewerLastName' => 'Judge',
            'brewerCity' => 'Dallas',
            'brewerState' => 'TX',
            'brewerJudge' => 'Y',
            'brewerJudgeLocation' => 'Y-'.self::LOCATION_ID,
        ]);

        // Virtual location (judgingLocType=1).
        DB::table('judging_locations')->where('id', self::LOCATION_ID)->delete();
        DB::table('judging_locations')->insert([
            'id' => self::LOCATION_ID,
            'judgingLocType' => 1,
            'judgingDate' => '',
            'judgingDateEnd' => '',
            'judgingLocName' => 'Virtual Hall',
            'judgingLocation' => 'Online',
            'judgingRounds' => 1,
        ]);

        // Judging table referencing the baseline 21B style, at the virtual loc.
        DB::table('judging_tables')->where('id', self::TABLE_ID)->delete();
        DB::table('judging_tables')->insert([
            'id' => self::TABLE_ID,
            'tableName' => self::PREFIX.' Table',
            'tableStyles' => (string) self::STYLE_ID,
            'tableNumber' => '99',
            'tableLocation' => self::LOCATION_ID,
        ]);

        // Virtual judge assigned to the table at the virtual location.
        DB::table('judging_assignments')->where('assignTable', self::TABLE_ID)->delete();
        DB::table('judging_assignments')->insert([
            'bid' => self::VIRTUAL_UID,
            'assignment' => 'J',
            'assignTable' => self::TABLE_ID,
            'assignLocation' => self::LOCATION_ID,
            'assignFlight' => 1,
            'assignRound' => 1,
        ]);
    }

    public function test_guest_and_non_admin_are_rejected(): void
    {
        DB::table('users')->insert([
            'id' => self::NON_ADMIN_ID,
            'user_name' => self::PREFIX.'.member@brewingcompetitions.com',
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->loginWithEmail(self::PREFIX.'.member@brewingcompetitions.com');

        foreach ([
            'go=judging_tables',
            'go=judging_tables&filter=judges',
            'go=participants&action=judging_nametags',
            'go=participants&action=judging_labels',
        ] as $q) {
            $this->get('/admin/output/labels?'.$q)->assertRedirect('/?msg=99');
        }
    }

    public function test_box_labels_stream_pdf_with_table_and_styles(): void
    {
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_tables&psort=5160&sort=1');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $data = LabelsController::boxLabels($this->ctx(), '5160', 1);
        $this->assertStringContainsString('_Box_Labels_Avery5160.pdf', $data['filename']);
        /** @var list<list<string>> $labels */
        $labels = $data['view']['labels'];
        $flat = collect($labels)->map(fn ($l) => implode('|', $l))->implode("\n");
        $this->assertStringContainsString(self::PREFIX.' Table', $flat);
        $this->assertStringContainsString('99', $flat);
        $this->assertStringContainsString('21B', $flat);
        $this->assertStringContainsString('Virtual Hall', $flat);
    }

    public function test_virtual_judge_labels_stream_pdf_with_table_assignment(): void
    {
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_tables&filter=judges&psort=5160&sort=1');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $data = LabelsController::virtualJudgeLabels($this->ctx(), '5160', 1);
        $this->assertStringContainsString('_Virtual_Judge_Labels_Avery5160.pdf', $data['filename']);
        /** @var list<list<string>> $labels */
        $labels = $data['view']['labels'];
        $flat = collect($labels)->map(fn ($l) => implode('|', $l))->implode("\n");
        $this->assertStringContainsString(self::PREFIX.'Virtual Judge', $flat);
        $this->assertStringContainsString('Table 99', $flat);
    }

    public function test_nametags_stream_pdf_with_role_assignment(): void
    {
        $this->login();

        $response = $this->get('/admin/output/labels?go=participants&action=judging_nametags&psort=5395');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $data = LabelsController::nametags($this->ctx());
        $this->assertStringContainsString('_Nametags_Avery5395.pdf', $data['filename']);
        /** @var list<list<string>> $labels */
        $labels = $data['view']['labels'];
        $flat = collect($labels)->map(fn ($l) => implode('|', $l))->implode("\n");
        $this->assertStringContainsString(self::PREFIX.'First '.self::PREFIX.'Last', $flat);
        $this->assertStringContainsString('Judge', $flat);
        $this->assertStringContainsString('Austin, TX', $flat);
    }

    public function test_judging_labels_stream_pdf_with_rank_and_email(): void
    {
        $this->login();

        $response = $this->get('/admin/output/labels?go=participants&action=judging_labels&psort=5160');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $data = LabelsController::judgingLabels($this->ctx(), '5160');
        $this->assertStringContainsString('_All_Judge_Scoresheet_Labels_Avery5160.pdf', $data['filename']);
        // The baseline DB has staff judges whose names sort before P52jLast,
        // so locate the seeded judge by name rather than index [0].
        $labels = array_map(
            static fn (array $l): string => implode('|', $l),
            $data['view']['labels'],
        );
        $seeded = collect($labels)->first(
            static fn (string $l): bool => str_contains($l, self::PREFIX.'First '.self::PREFIX.'Last'),
        );
        $this->assertNotNull($seeded, 'seeded judge label missing from '.count($labels).' labels');
        $this->assertStringContainsString('BJCP Certified Judge', $seeded);
        $this->assertStringContainsString(strtolower(self::PREFIX).'.judge@brewingcompetitions.com', $seeded);
    }
}
