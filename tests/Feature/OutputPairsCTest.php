<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\AssignmentsController;
use App\Http\Controllers\Output\StaffPointsController;
use App\Support\Outputs\OutputFormat;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * P5.2 pair C outputs (spec §7 P5.2, ticket 02-remaining-outputs):
 * participant_summary, participant_entries_list, post_judge_inventory,
 * staff_points, styles.
 *
 * Gate + 200 + %PDF on a seeded P52c-prefixed fixture season; helper-level
 * pins for the shared number formatting ported from common.lib.php.
 */
final class OutputPairsCTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p52c.admin@brewingcompetitions.com';

    private const ADMIN_ID = 95301;

    private const JUDGE_EMAIL = 'p52c.judge@brewingcompetitions.com';

    private const JUDGE_ID = 95302;

    private const ORGANIZER_EMAIL = 'p52c.organizer@brewingcompetitions.com';

    private const ORGANIZER_ID = 95303;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<int> */
    private array $styleIds = [];

    private int $tableId = 0;

    private const OUTPUTS = [
        'participant_summary',
        'participant_entries_list',
        'post_judge_inventory',
        'staff_points',
        'styles',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanup();

        foreach ([[self::ADMIN_ID, self::ADMIN_EMAIL, '1'], [self::JUDGE_ID, self::JUDGE_EMAIL, '2'], [self::ORGANIZER_ID, self::ORGANIZER_EMAIL, '2']] as [$id, $email, $level]) {
            DB::table('users')->insert([
                'id' => $id,
                'user_name' => $email,
                'password' => self::HASH,
                'userLevel' => $level,
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
        }

        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    /** Remove every fixture row this class can create — safe against reruns. */
    private function cleanup(): void
    {
        foreach ([self::ADMIN_EMAIL, self::JUDGE_EMAIL, self::ORGANIZER_EMAIL] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        foreach ([self::JUDGE_ID, self::ORGANIZER_ID] as $uid) {
            DB::table('staff')->where('uid', $uid)->delete();
            DB::table('judging_assignments')->where('bid', $uid)->delete();
            DB::table('brewer')->where('uid', $uid)->delete();
        }

        DB::table('judging_scores')->whereIn('eid', $this->entryIds ?: [0])->delete();
        DB::table('judging_scores_bos')->whereIn('eid', $this->entryIds ?: [0])->delete();
        if ($this->tableId !== 0) {
            DB::table('judging_flights')->where('flightTable', $this->tableId)->delete();
            DB::table('judging_tables')->where('id', $this->tableId)->delete();
        }
        DB::table('judging_locations')->whereIn('id', $this->locationIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
    }

    public function test_format_helpers_match_legacy(): void
    {
        // readable_judging_number(): six-digit passes through, five splits
        // 2-3, four splits 1-3, all zero-padded to six characters.
        $this->assertSame('400001', OutputFormat::judgingNumber('400001'));
        $this->assertSame('40-001', OutputFormat::judgingNumber('40001'));
        $this->assertSame('04-001', OutputFormat::judgingNumber('4001'));
        // addOrdinalNumberSuffix(): teens stay th.
        $this->assertSame('1st', OutputFormat::ordinal('1'));
        $this->assertSame('2nd', OutputFormat::ordinal('2'));
        $this->assertSame('11th', OutputFormat::ordinal('11'));
        $this->assertSame('21st', OutputFormat::ordinal(21));
    }

    /**
     * BJCP entry-count precedence — upstream v3.1.0 lib/common.lib.php:2733
     * (get_bjcp_entry_count(), "BJCP Reporting Adjustments", commit
     * ec5360214), consumed by output/staff_points.output.php:86 for both the
     * printed entry figure and the 30-entry BOS gate.
     *
     * The four tiers are crafted to DIFFER (2 judged < 5 received < 7 paid
     * < 9 total), which is what makes the rule observable: 3.0.3 used the
     * judged count only when it EXCEEDED received
     * (`if ($total_entries_scored > $total_entries_received)`), so the
     * pre-fix code answered 5 at the judged tier instead of 2.
     */
    public function test_bjcp_entry_count_follows_judged_received_paid_total_precedence(): void
    {
        // The helper counts whole tables, so own their state and hand it back.
        $brewingRows = DB::table('brewing')->get()->map(fn ($r): array => (array) $r)->all();
        $scoreRows = DB::table('judging_scores')->get()->map(fn ($r): array => (array) $r)->all();

        try {
            DB::table('judging_scores')->delete();
            DB::table('brewing')->delete();

            $brewIds = [];
            // 9 entries: 2 unreceived+unpaid, 2 paid but unreceived,
            // 5 received+paid → received 5, paid 7, total 9.
            foreach ([[0, 0], [0, 0], [1, 0], [1, 0], [1, 1], [1, 1], [1, 1], [1, 1], [1, 1]] as [$paid, $received]) {
                $brewIds[] = (int) DB::table('brewing')->insertGetId([
                    'brewName' => 'P52c BJCP Count Entry',
                    'brewPaid' => $paid,
                    'brewReceived' => $received,
                ]);
            }

            $this->assertSame(5, DB::table('brewing')->where('brewReceived', 1)->count());
            $this->assertSame(7, DB::table('brewing')->where('brewPaid', 1)->count());
            $this->assertSame(9, DB::table('brewing')->count());

            // Tier 1 — two scored entries, so "judged" wins despite received
            // (5), paid (7) and total (9) all being larger.
            DB::table('judging_scores')->insert(['eid' => $brewIds[0], 'scorePlace' => '1']);
            DB::table('judging_scores')->insert(['eid' => $brewIds[1], 'scorePlace' => '2']);
            $this->assertSame(2, StaffPointsController::bjcpEntryCount(), 'judged must beat larger received/paid/total counts');

            // Tier 2 — no judged entries left → received.
            DB::table('judging_scores')->delete();
            $this->assertSame(5, StaffPointsController::bjcpEntryCount(), 'nothing judged → received');

            // Tier 3 — nothing received → paid.
            DB::table('brewing')->whereIn('id', $brewIds)->update(['brewReceived' => 0]);
            $this->assertSame(7, StaffPointsController::bjcpEntryCount(), 'nothing received → paid');

            // Tier 4 — nothing paid either → everything on record.
            DB::table('brewing')->whereIn('id', $brewIds)->update(['brewPaid' => 0]);
            $this->assertSame(9, StaffPointsController::bjcpEntryCount(), 'nothing paid → total');
        } finally {
            DB::table('judging_scores')->delete();
            DB::table('brewing')->delete();
            foreach (array_chunk($brewingRows, 100) as $chunk) {
                DB::table('brewing')->insert($chunk);
            }
            foreach (array_chunk($scoreRows, 100) as $chunk) {
                DB::table('judging_scores')->insert($chunk);
            }
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/logout');

        foreach (self::OUTPUTS as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/login');
        }
    }

    public function test_non_admin_is_rejected(): void
    {
        $this->loginWithEmail(self::JUDGE_EMAIL);

        foreach (self::OUTPUTS as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/?msg=99');
        }
    }

    #[Group('slow')]
    public function test_outputs_render_pdf_for_admin(): void
    {
        $this->seedSeason();

        foreach (self::OUTPUTS as $output) {
            $response = $this->get('/admin/output/'.$output);
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertSame('inline', substr((string) $response->headers->get('Content-Disposition'), 0, 6));
            $this->assertSame('%PDF', substr((string) $response->getContent(), 0, 4));
        }
    }

    /**
     * Issue 5: the dashboard "Staff Availability" row links twice with
     * filter=staff ("By Last Name" / "By Non-Judging Session"). Legacy
     * assignments.output.php branches on filter=staff BEFORE the roster
     * split, so both links must render the staff availability report —
     * brewers flagged brewerStaff=Y who marked `Y-<id>` availability for a
     * judgingLocType=2 session — and never the judge/steward roster.
     */
    public function test_staff_availability_filter_renders_staff_volunteers_not_the_judge_roster(): void
    {
        $judgingId = $this->location('P52c Judging Hall', 0);
        $nonJudgingId = $this->location('P52c Non-Judging Hall', 2);

        DB::table('brewer')->insert([
            'uid' => self::JUDGE_ID, 'brewerFirstName' => 'Judy', 'brewerLastName' => 'Judge', 'brewerEmail' => self::JUDGE_EMAIL,
        ]);
        DB::table('brewer')->insert([
            'uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Oscar', 'brewerLastName' => 'Organizer', 'brewerEmail' => self::ORGANIZER_EMAIL,
            'brewerStaff' => 'Y', 'brewerJudgeLocation' => 'Y-'.$nonJudgingId,
        ]);

        // Roster data: a judge assigned to a judging session. It must not
        // leak into the staff availability report.
        DB::table('judging_assignments')->insert([
            'bid' => self::JUDGE_ID, 'assignment' => 'J', 'assignLocation' => $judgingId, 'assignRound' => 1,
        ]);

        $response = $this->get('/admin/output/assignments?filter=staff&view=name');
        $response->assertOk();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('assignments-staff.pdf', (string) $response->headers->get('Content-Disposition'));

        $rows = AssignmentsController::staffAvailabilityData('name', TenantContext::load());

        self::assertCount(1, $rows, 'only the staff volunteer belongs in the staff availability report');
        self::assertSame('Organizer, Oscar', $rows[0]['name']);
        self::assertSame(self::ORGANIZER_EMAIL, $rows[0]['email']);
        self::assertStringStartsWith('P52c Non-Judging Hall', $rows[0]['session']);
    }

    /**
     * The two staff availability links are the SAME data set in two
     * orderings — view=name sorts person-then-session, the default sorts
     * session-then-person (legacy DataTables aaSorting). The equivalence the
     * user noticed is intended; only the underlying data was wrong.
     */
    public function test_staff_availability_views_are_the_same_data_in_two_orderings(): void
    {
        $alpha = $this->location('P52c Alpha Session', 2);
        $zulu = $this->location('P52c Zulu Session', 2);

        DB::table('brewer')->insert([
            ['uid' => self::JUDGE_ID, 'brewerFirstName' => 'Amy', 'brewerLastName' => 'Abbot', 'brewerEmail' => self::JUDGE_EMAIL,
                'brewerStaff' => 'Y', 'brewerJudgeLocation' => 'Y-'.$zulu],
            ['uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Zed', 'brewerLastName' => 'Zimmer', 'brewerEmail' => self::ORGANIZER_EMAIL,
                'brewerStaff' => 'Y', 'brewerJudgeLocation' => 'Y-'.$alpha],
        ]);

        $ctx = TenantContext::load();
        $byName = AssignmentsController::staffAvailabilityData('name', $ctx);
        $bySession = AssignmentsController::staffAvailabilityData('', $ctx);

        // Both dashboard links render a PDF (same report, different order).
        foreach (['?filter=staff&view=name', '?filter=staff'] as $query) {
            $response = $this->get('/admin/output/assignments'.$query);
            $response->assertOk();
            self::assertStringStartsWith('%PDF', (string) $response->getContent());
        }

        self::assertSame(['Abbot, Amy', 'Zimmer, Zed'], array_column($byName, 'name'));
        self::assertSame(['Zimmer, Zed', 'Abbot, Amy'], array_column($bySession, 'name'));

        $normalize = static fn (array $rows): array => array_map(
            static fn (array $r): array => [$r['name'], $r['email'], $r['session']],
            $rows,
        );
        $a = $normalize($byName);
        $b = $normalize($bySession);
        sort($a);
        sort($b);

        self::assertSame($a, $b, 'both orderings must carry the identical staff/session pairs');
    }

    /** @param 0|1|2 $type */
    private function location(string $name, int $type): int
    {
        $id = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => $type,
            'judgingDate' => '1750000000',
            'judgingLocName' => $name,
        ]);
        $this->locationIds[] = $id;

        return $id;
    }

    /**
     * Seeded season: two brewers (judge/steward + organizer/staffer), a
     * BJCP2021 style under the active set, one judging session, one table,
     * a placed scored entry, an unscored entry, and an unreceived entry.
     */
    private function seedSeason(): void
    {
        DB::table('brewer')->insert([
            ['uid' => self::JUDGE_ID, 'brewerFirstName' => 'Judy', 'brewerLastName' => 'Judge', 'brewerEmail' => self::JUDGE_EMAIL, 'brewerJudgeID' => 'D1234'],
            ['uid' => self::ORGANIZER_ID, 'brewerFirstName' => 'Oscar', 'brewerLastName' => 'Organizer', 'brewerEmail' => self::ORGANIZER_EMAIL, 'brewerJudgeID' => null],
        ]);

        DB::table('staff')->insert([
            ['uid' => self::JUDGE_ID, 'staff_judge' => 1, 'staff_steward' => 1],
            ['uid' => self::ORGANIZER_ID, 'staff_organizer' => 1, 'staff_staff' => 1],
        ]);

        DB::table('styles')->insert([
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'D',
            'brewStyle' => 'P52c Standard Bitter',
            'brewStyleCategory' => 'Light Lager',
            'brewStyleVersion' => 'BJCP2021',
            'brewStyleType' => '1',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'bcoe',
            'brewStyleOG' => '1.030',
            'brewStyleOGMax' => '1.034',
            'brewStyleFG' => '1.008',
            'brewStyleFGMax' => '1.012',
            'brewStyleABV' => '3.2',
            'brewStyleABVMax' => '3.6',
            'brewStyleIBU' => '8',
            'brewStyleIBUMax' => '12',
            'brewStyleSRM' => '3',
            'brewStyleSRMMax' => '4',
        ]);
        $styleId = (int) DB::table('styles')->max('id');
        $this->styleIds[] = $styleId;

        $this->locationIds[] = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => '1750000000',
            'judgingLocName' => 'P52c Hall',
        ]);
        $locationId = (int) DB::table('judging_locations')->max('id');

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'P52c Fixture Table',
            'tableNumber' => random_int(50, 99),
            'tableLocation' => $locationId,
            'tableStyles' => (string) $styleId,
        ]);

        foreach ([
            ['brewName' => 'P52c Placed Entry', 'brewJudgingNumber' => '700001'],
            ['brewName' => 'P52c Unscored Entry', 'brewJudgingNumber' => '700002'],
            ['brewName' => 'P52c Unreceived Entry', 'brewJudgingNumber' => '700003', 'brewReceived' => 0],
        ] as $entry) {
            DB::table('brewing')->insert(array_merge([
                'brewName' => 'P52c Entry',
                'brewCategorySort' => '01',
                'brewCategory' => '1',
                'brewSubCategory' => 'D',
                'brewStyle' => 'P52c Standard Bitter',
                'brewBrewerID' => (string) self::JUDGE_ID,
                'brewConfirmed' => '1',
                'brewPaid' => 1,
                'brewReceived' => 1,
                'brewInfo' => 'special^info',
                'brewMead1' => '',
            ], $entry));
            $this->entryIds[] = (int) DB::table('brewing')->max('id');
        }
        [$placed, $unscored] = $this->entryIds;

        DB::table('judging_scores')->insert([
            'eid' => $placed,
            'bid' => self::JUDGE_ID,
            'scoreTable' => $this->tableId,
            'scoreEntry' => 36,
            'scorePlace' => '1',
            'scoreMiniBOS' => 1,
        ]);

        DB::table('judging_scores_bos')->insert([
            'eid' => $placed,
            'bid' => self::JUDGE_ID,
            'scorePlace' => '1',
        ]);

        DB::table('judging_assignments')->insert([
            ['bid' => self::JUDGE_ID, 'assignment' => 'J', 'assignLocation' => $locationId, 'assignRound' => 1],
            ['bid' => self::JUDGE_ID, 'assignment' => 'S', 'assignLocation' => $locationId, 'assignRound' => 1],
        ]);
    }
}
