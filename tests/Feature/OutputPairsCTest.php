<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Outputs\OutputFormat;
use Illuminate\Support\Facades\DB;

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

        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/logout');

        foreach (self::OUTPUTS as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/login');
        }
    }

    public function test_non_admin_is_rejected(): void
    {
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => self::JUDGE_EMAIL,
            'loginPassword' => 'bcoem',
        ]);

        foreach (self::OUTPUTS as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/?msg=99');
        }
    }

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
