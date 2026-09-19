<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * BJCP XML OrgReport — the "BJCP XML org report implement" issue (#25). The
 * port carried no XML surface; the report is legacy output/export.output.php's
 * export-staff/`view=xml` branch, reached from the dashboard's BJCP Points
 * row (Print / PDF / XML).
 *
 * The report aggregates whole tables (staff, brewer, judging_locations), so
 * every test owns those tables: snapshot, clear, seed, restore.
 */
final class BjcpXmlReportTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p25.admin@brewingcompetitions.com';

    private const ADMIN_ID = 95401;

    private const ORGANIZER_ID = 95406;

    private const JUDGE_ID = 95402;

    private const TEMP_JUDGE_ID = 95403;

    private const BOS_ONLY_ID = 95404;

    private const STEWARD_ID = 95405;

    private const NON_BJCP_JUDGE_ID = 95407;

    private const BOTH_ROLES_ID = 95408;

    /** @var list<int> five panel judges with distinct last names (A..E). */
    private const PANEL_IDS = [95411, 95412, 95413, 95414, 95415];

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const TABLES = [
        'staff', 'brewer', 'brewing', 'judging_scores', 'judging_scores_bos',
        'judging_locations', 'judging_assignments', 'bos_panel_judges',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $snapshot = [];

    private string $originalContestId = '';

    private int $locationId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            $this->snapshot[$table] = array_values(DB::table($table)->get()->map(static fn ($row): array => (array) $row)->all());
            DB::table($table)->delete();
        }

        $this->originalContestId = (string) (DB::table('contest_info')->where('id', 1)->value('contestID') ?? '');
        DB::table('contest_info')->where('id', 1)->update(['contestID' => 'P25COMP']);

        // Idempotent: a previous interrupted run can leave the row behind.
        DB::table('users')->where('id', self::ADMIN_ID)->orWhere('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('contest_info')->where('id', 1)->update(['contestID' => $this->originalContestId]);

        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
            foreach (array_chunk($this->snapshot[$table] ?? [], 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->snapshot = [];

        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/output/staff_points?view=xml')->assertRedirect('/login');
    }

    public function test_non_admin_is_rejected(): void
    {
        DB::table('users')->where('id', self::ADMIN_ID)->update(['userLevel' => '2']);
        $this->login();

        $this->get('/admin/output/staff_points?view=xml')->assertRedirect('/?msg=99');
    }

    /**
     * The document itself: OrgReport skeleton, the judged entry count feeding
     * <CompEntries>, the BJCPpoints/NonBJCP split, TEMP#### acceptance, the
     * BOS-only judge's flat 1.0, and a valid steward id.
     */
    public function test_admin_gets_the_org_report_with_the_four_reporting_adjustments(): void
    {
        $this->seedSeason();
        $this->login();

        $response = $this->get('/admin/output/staff_points?view=xml');

        $response->assertOk();
        $this->assertSame('application/force-download', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;filename="', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.xml"', (string) $response->headers->get('Content-Disposition'));

        $xml = (string) $response->getContent();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $xml);
        $this->assertStringContainsString('<OrgReport>', $xml);
        $this->assertStringContainsString('</OrgReport>', $xml);
        $this->assertStringContainsString('<CompID>P25COMP</CompID>', $xml);
        $this->assertStringContainsString('<CompEntries>30</CompEntries>', $xml);
        $this->assertStringContainsString('<CompDays>1</CompDays>', $xml);
        $this->assertStringContainsString('<CompSessions>1</CompSessions>', $xml);
        $this->assertStringContainsString('<BJCPpoints>', $xml);
        $this->assertStringContainsString('<NonBJCP>', $xml);
        $this->assertStringContainsString('Generated by BCOEM version', $xml);

        // Judge with a standard id.
        $this->assertStringContainsString('<JudgeID>D1234</JudgeID>', $xml);
        $this->assertStringContainsString('<JudgeRole>Judge</JudgeRole>', $xml);

        // Fix 3 — TEMP#### ids are accepted and land in the BJCP block.
        $this->assertStringContainsString('<JudgeID>TEMP1234</JudgeID>', $xml);

        // Fix 4 — a BOS-only judge gets credit for their session.
        $this->assertStringContainsString('<JudgeRole>BOS Judge</JudgeRole>', $xml);
        $this->assertStringContainsString('<JudgePts>1.0</JudgePts>', $xml);

        // A valid steward id is reported (legacy shipped an empty one).
        $this->assertStringContainsString('<JudgeID>S9012</JudgeID>', $xml);
        $this->assertStringContainsString('<JudgeRole>Steward</JudgeRole>', $xml);

        // A malformed id belongs to the NonBJCP block, with an empty id.
        $this->assertStringContainsString('<JudgeID></JudgeID>', $xml);
    }

    /**
     * Fix 2 — medal winners alone must not be reported as Best of Show
     * entries. <BOSData> appears only once a BOS panel has actually scored
     * (a judging_scores_bos row exists).
     */
    public function test_bos_data_requires_a_bos_panel(): void
    {
        $this->seedSeason();
        $this->login();

        $withoutPanel = (string) $this->get('/admin/output/staff_points?view=xml')->getContent();
        $this->assertStringNotContainsString('<BOSData>', $withoutPanel, 'scored medal winners are not a BOS panel');

        DB::table('judging_scores_bos')->insert(['eid' => 1, 'scorePlace' => '1']);
        $withPanel = (string) $this->get('/admin/output/staff_points?view=xml')->getContent();

        $this->assertStringContainsString('<BOSData>', $withPanel);
        $this->assertStringContainsString('<BOSBeer>1</BOSBeer>', $withPanel);
    }

    /**
     * A report the BJCP portal would reject is refused in plain text, with the
     * same reasons legacy lists.
     */
    public function test_report_refuses_without_a_competition_id_or_organizer(): void
    {
        $this->seedSeason();
        $this->login();

        DB::table('contest_info')->where('id', 1)->update(['contestID' => '']);
        $missingId = (string) $this->get('/admin/output/staff_points?view=xml')->getContent();
        $this->assertStringContainsString('The report cannot be generated', $missingId);
        $this->assertStringContainsString('BJCP Competition ID is missing', $missingId);
        $this->assertStringNotContainsString('<OrgReport>', $missingId);

        DB::table('contest_info')->where('id', 1)->update(['contestID' => 'P25COMP']);
        DB::table('staff')->where('uid', self::ORGANIZER_ID)->delete();
        $missingOrganizer = (string) $this->get('/admin/output/staff_points?view=xml')->getContent();
        $this->assertStringContainsString('No organizer has been designated', $missingOrganizer);
    }

    /**
     * Official rules, fix 1 — wine style types are excluded from the reported
     * entry count. Three scored wine entries sit outside a 30-entry beer
     * competition without moving <CompEntries>.
     */
    public function test_wine_entries_are_excluded_from_the_entry_count(): void
    {
        $this->seedSeason();
        $this->login();

        foreach ([101, 102, 103] as $eid) {
            DB::table('brewing')->insert([
                'id' => $eid,
                'brewName' => 'P25 Wine Entry',
                'brewStyleType' => 5, // style_types id 5 = Wine
                'brewPaid' => 1,
                'brewReceived' => 1,
            ]);
            DB::table('judging_scores')->insert(['eid' => $eid, 'scoreType' => 5, 'scorePlace' => '1']);
        }

        $xml = $this->xml();

        $this->assertStringContainsString('<CompEntries>30</CompEntries>', $xml, 'wine entries must not count');
        $this->assertStringNotContainsString('<CompEntries>33</CompEntries>', $xml);
    }

    /**
     * Official rules, fix 2 — a participant flagged as both judge and steward
     * earns judge points only.
     */
    public function test_judge_role_wins_over_steward_role(): void
    {
        $this->seedSeason();
        DB::table('brewer')->insert(['uid' => self::BOTH_ROLES_ID, 'brewerFirstName' => 'Jill', 'brewerLastName' => 'Both', 'brewerJudgeID' => 'B1111']);
        DB::table('staff')->insert([
            'uid' => self::BOTH_ROLES_ID, 'staff_judge' => 1, 'staff_judge_bos' => 0,
            'staff_steward' => 1, 'staff_organizer' => 0, 'staff_staff' => 0,
        ]);
        DB::table('judging_assignments')->insert([
            ['bid' => self::BOTH_ROLES_ID, 'assignment' => 'J', 'assignLocation' => $this->locationId, 'assignRound' => 1],
            ['bid' => self::BOTH_ROLES_ID, 'assignment' => 'S', 'assignLocation' => $this->locationId, 'assignRound' => 1],
        ]);
        $this->login();

        $xml = $this->xml();
        $block = $this->blockFor($xml, 'Jill Both');

        $this->assertSame(1, substr_count($xml, '<JudgeName>Jill Both</JudgeName>'), 'one entry per person');
        $this->assertStringContainsString('<JudgeRole>Judge</JudgeRole>', $block);
        $this->assertStringNotContainsString('Steward', $block);
    }

    /**
     * Official rules, fix 3 — "No single person can receive more total points
     * than the Organizer." At 50 entries the Organizer maximum is 2.5, the
     * staff pool is 2.0 (one staffer) and the judge minimum is 1.0, so the
     * discretionary staff award is trimmed to 1.5.
     */
    public function test_combined_total_is_capped_at_the_organizer_maximum(): void
    {
        $this->seedCapSeason();
        $this->login();

        $block = $this->blockFor($this->xml(), 'Stan Staff');

        $this->assertStringContainsString('<JudgeRole>Staff + Judge</JudgeRole>', $block);
        $this->assertStringContainsString('<JudgePts>1.0</JudgePts>', $block);
        $this->assertStringContainsString('<NonJudgePts>1.5</NonJudgePts>', $block);
    }

    /**
     * Official rules, fix 4 — captured panel membership drives the BOS bonus
     * and the official per-panel cap: a 10-entry beer panel admits 3 judges,
     * so 2 of the 5 assigned judges lose the 0.5 bonus.
     */
    public function test_captured_panel_membership_applies_the_per_panel_cap(): void
    {
        $this->seedPanelSeason(
            panelEntries: 10,
            assigned: self::PANEL_IDS,
        );
        $this->login();

        $xml = $this->xml();

        $this->assertSame(3, substr_count($xml, '<JudgeRole>Judge + BOS</JudgeRole>'), 'a 10-entry panel admits three judges');
        $this->assertSame(2, substr_count($xml, '<JudgeRole>Judge</JudgeRole>'));

        // Allocation is by brewerLastName: A, B, C keep the bonus; D, E lose it.
        foreach (['Pat Panel A', 'Pat Panel B', 'Pat Panel C'] as $name) {
            $this->assertStringContainsString('<JudgeRole>Judge + BOS</JudgeRole>', $this->blockFor($xml, $name));
        }
        foreach (['Pat Panel D', 'Pat Panel E'] as $name) {
            $this->assertStringContainsString('<JudgeRole>Judge</JudgeRole>', $this->blockFor($xml, $name));
        }
    }

    /** A 15+ entry panel admits five judges. */
    public function test_large_panel_admits_five_judges(): void
    {
        $this->seedPanelSeason(panelEntries: 15, assigned: self::PANEL_IDS);
        $this->login();

        $xml = $this->xml();

        $this->assertSame(5, substr_count($xml, '<JudgeRole>Judge + BOS</JudgeRole>'));
    }

    /** Without captured panels the legacy global flag still awards the bonus. */
    public function test_global_bos_flag_is_the_fallback_when_no_panel_is_captured(): void
    {
        $this->seedSeason();
        DB::table('staff')->where('uid', self::JUDGE_ID)->update(['staff_judge_bos' => 1]);
        $this->login();

        $this->assertStringContainsString(
            '<JudgeRole>Judge + BOS</JudgeRole>',
            $this->blockFor($this->xml(), 'Judy Judge'),
        );
    }

    /** Panel composition round-trips through the BOS screen endpoint. */
    public function test_bos_panel_judges_can_be_captured(): void
    {
        $this->seedSeason();
        $this->login();

        $this->put('/admin/judging/bos/1/panels', ['judges' => [self::JUDGE_ID, self::TEMP_JUDGE_ID]])
            ->assertRedirect('/admin/judging/bos');
        $this->assertSame(
            [self::JUDGE_ID, self::TEMP_JUDGE_ID],
            DB::table('bos_panel_judges')->where('bosType', 1)->orderBy('uid')->pluck('uid')->all(),
        );

        // Re-saving replaces the set rather than appending.
        $this->put('/admin/judging/bos/1/panels', ['judges' => [self::TEMP_JUDGE_ID]])
            ->assertRedirect('/admin/judging/bos');
        $this->assertSame(
            [self::TEMP_JUDGE_ID],
            DB::table('bos_panel_judges')->where('bosType', 1)->pluck('uid')->all(),
        );
    }

    public function test_non_admin_cannot_capture_panel_judges(): void
    {
        DB::table('users')->where('id', self::ADMIN_ID)->update(['userLevel' => '2']);
        $this->login();

        $this->put('/admin/judging/bos/1/panels', ['judges' => [self::JUDGE_ID]])
            ->assertRedirect('/?msg=99');
        $this->assertSame(0, DB::table('bos_panel_judges')->count());
    }

    private function xml(): string
    {
        return (string) $this->get('/admin/output/staff_points?view=xml')->assertOk()->getContent();
    }

    /** The single <JudgeData> block whose JudgeName is $name. */
    private function blockFor(string $xml, string $name): string
    {
        $needle = '<JudgeName>'.$name.'</JudgeName>';
        $pos = strpos($xml, $needle);
        self::assertNotFalse($pos, $name.' is missing from the report');

        $start = strrpos(substr($xml, 0, (int) $pos), '<JudgeData>');
        self::assertNotFalse($start);
        $end = strpos($xml, '</JudgeData>', (int) $pos);
        self::assertNotFalse($end);

        return substr($xml, (int) $start, (int) $end - (int) $start);
    }

    /**
     * 50 entries, an organizer and one judge/staff hybrid: the staff pool
     * (2.0) plus the judge minimum (1.0) would be 3.0 against a 2.5 Organizer
     * maximum.
     */
    private function seedCapSeason(): void
    {
        DB::table('brewer')->insert([
            ['uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Oscar', 'brewerLastName' => 'Organizer', 'brewerJudgeID' => null],
            ['uid' => self::JUDGE_ID, 'brewerFirstName' => 'Stan', 'brewerLastName' => 'Staff', 'brewerJudgeID' => 'S2222'],
        ]);

        DB::table('staff')->insert([
            ['uid' => self::ORGANIZER_ID, 'staff_judge' => 0, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 1, 'staff_staff' => 0],
            ['uid' => self::JUDGE_ID, 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 1],
        ]);

        DB::table('judging_locations')->insert([
            'judgingLocType' => 0, 'judgingDate' => '1750000000', 'judgingLocName' => 'P25 Cap Hall',
        ]);

        for ($eid = 1; $eid <= 50; $eid++) {
            DB::table('judging_scores')->insert(['eid' => $eid, 'bid' => self::JUDGE_ID, 'scoreType' => 1, 'scorePlace' => '2']);
        }
    }

    /**
     * Five judges assigned to one beer panel with $panelEntries recorded BOS
     * results, plus the 30 judged entries the BOS gate needs.
     *
     * @param  list<int>  $assigned
     */
    private function seedPanelSeason(int $panelEntries, array $assigned): void
    {
        DB::table('brewer')->insert([
            ['uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Oscar', 'brewerLastName' => 'Organizer', 'brewerJudgeID' => null],
            ['uid' => self::PANEL_IDS[0], 'brewerFirstName' => 'Pat', 'brewerLastName' => 'Panel A', 'brewerJudgeID' => 'P0001'],
            ['uid' => self::PANEL_IDS[1], 'brewerFirstName' => 'Pat', 'brewerLastName' => 'Panel B', 'brewerJudgeID' => 'P0002'],
            ['uid' => self::PANEL_IDS[2], 'brewerFirstName' => 'Pat', 'brewerLastName' => 'Panel C', 'brewerJudgeID' => 'P0003'],
            ['uid' => self::PANEL_IDS[3], 'brewerFirstName' => 'Pat', 'brewerLastName' => 'Panel D', 'brewerJudgeID' => 'P0004'],
            ['uid' => self::PANEL_IDS[4], 'brewerFirstName' => 'Pat', 'brewerLastName' => 'Panel E', 'brewerJudgeID' => 'P0005'],
        ]);

        DB::table('staff')->insert([
            ['uid' => self::ORGANIZER_ID, 'staff_judge' => 0, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 1, 'staff_staff' => 0],
            ['uid' => self::PANEL_IDS[0], 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::PANEL_IDS[1], 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::PANEL_IDS[2], 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::PANEL_IDS[3], 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::PANEL_IDS[4], 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
        ]);

        $this->locationId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0, 'judgingDate' => '1750000000', 'judgingLocName' => 'P25 Panel Hall',
        ]);

        foreach ($assigned as $uid) {
            DB::table('judging_assignments')->insert([
                'bid' => $uid, 'assignment' => 'J', 'assignLocation' => $this->locationId, 'assignRound' => 1,
            ]);
            DB::table('bos_panel_judges')->insert(['bosType' => 1, 'uid' => $uid]);
        }

        // 30 judged entries drive the count and the BOS gate.
        for ($eid = 1; $eid <= 30; $eid++) {
            DB::table('judging_scores')->insert(['eid' => $eid, 'bid' => self::PANEL_IDS[0], 'scoreType' => 1, 'scorePlace' => '2']);
        }

        // The panel's recorded size.
        for ($eid = 1; $eid <= $panelEntries; $eid++) {
            DB::table('judging_scores_bos')->insert(['eid' => $eid, 'scoreType' => 1, 'scorePlace' => '1']);
        }
    }

    private function login(): void
    {
        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    /**
     * One session, an organizer, two judges (one standard id, one TEMP####),
     * a BOS-only judge, a steward and a judge with a malformed id, plus 30
     * scored entries — enough for the 30-entry BOS gate.
     */
    private function seedSeason(): void
    {
        DB::table('brewer')->insert([
            ['uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Oscar', 'brewerLastName' => 'Organizer', 'brewerJudgeID' => null],
            ['uid' => self::JUDGE_ID, 'brewerFirstName' => 'Judy', 'brewerLastName' => 'Judge', 'brewerJudgeID' => 'D1234'],
            ['uid' => self::TEMP_JUDGE_ID, 'brewerFirstName' => 'Tim', 'brewerLastName' => 'Temp', 'brewerJudgeID' => 'TEMP1234'],
            ['uid' => self::BOS_ONLY_ID, 'brewerFirstName' => 'Bob', 'brewerLastName' => 'Bos', 'brewerJudgeID' => 'B5678'],
            ['uid' => self::STEWARD_ID, 'brewerFirstName' => 'Sue', 'brewerLastName' => 'Steward', 'brewerJudgeID' => 'S9012'],
            ['uid' => self::NON_BJCP_JUDGE_ID, 'brewerFirstName' => 'Nate', 'brewerLastName' => 'Noid', 'brewerJudgeID' => 'XX'],
        ]);

        // Uniform keys on every row: a multi-row insert takes its column list
        // from the first row and positional values from each, so ragged rows
        // would misalign the role flags.
        DB::table('staff')->insert([
            ['uid' => self::ORGANIZER_ID, 'staff_judge' => 0, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 1, 'staff_staff' => 0],
            ['uid' => self::JUDGE_ID, 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::TEMP_JUDGE_ID, 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::BOS_ONLY_ID, 'staff_judge' => 0, 'staff_judge_bos' => 1, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::STEWARD_ID, 'staff_judge' => 0, 'staff_judge_bos' => 0, 'staff_steward' => 1, 'staff_organizer' => 0, 'staff_staff' => 0],
            ['uid' => self::NON_BJCP_JUDGE_ID, 'staff_judge' => 1, 'staff_judge_bos' => 0, 'staff_steward' => 0, 'staff_organizer' => 0, 'staff_staff' => 0],
        ]);

        $this->locationId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => '1750000000',
            'judgingLocName' => 'P25 Hall',
        ]);

        DB::table('judging_assignments')->insert([
            ['bid' => self::JUDGE_ID, 'assignment' => 'J', 'assignLocation' => $this->locationId, 'assignRound' => 1],
            ['bid' => self::TEMP_JUDGE_ID, 'assignment' => 'J', 'assignLocation' => $this->locationId, 'assignRound' => 1],
            ['bid' => self::STEWARD_ID, 'assignment' => 'S', 'assignLocation' => $this->locationId, 'assignRound' => 1],
        ]);

        // 30 judged entries; one category winner (scorePlace 1) — a medal, not
        // a BOS result.
        for ($eid = 1; $eid <= 30; $eid++) {
            DB::table('judging_scores')->insert([
                'eid' => $eid,
                'bid' => self::JUDGE_ID,
                'scoreType' => 1,
                'scorePlace' => $eid === 1 ? '1' : '2',
            ]);
        }
    }
}
