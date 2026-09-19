<?php

declare(strict_types=1);

namespace Tests\Feature;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Admin move-evaluation pins (issue #1756):
 *  - only an admin may move an evaluation, and the POST needs confirm=yes;
 *  - the entry-derived columns follow the destination (eid, uid=the
 *    destination's brewBrewerID, evalStyle, evalTable) while the judges'
 *    scores and comments are carried over untouched;
 *  - the destination may be given as an entry id or a judging number;
 *  - the official score rows (judging_scores) of BOTH affected entries are
 *    cleared, so the consensus import has to be re-run;
 *  - unknown/self/absent targets change nothing;
 *  - the move block on the output page renders for admins only.
 */
final class EvalMoveTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'admin.evalmove@brewingcompetitions.com';

    private const JUDGE = 'judge.evalmove@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const ADMIN_ID = 9301;

    private const JUDGE_ID = 9302;

    private const SOURCE_BREWER = 7301;

    private const DEST_BREWER = 7302;

    /** @var list<int> */
    private array $entries = [];

    /** @var list<int> */
    private array $tables = [];

    /** @var list<int> */
    private array $flights = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        DB::table('users')->whereIn('user_name', [self::ADMIN, self::JUDGE])->delete();
        DB::table('users')->insert([
            ['id' => self::ADMIN_ID, 'user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => self::JUDGE_ID, 'user_name' => self::JUDGE, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        $ids = $this->entries !== [] ? $this->entries : [0];
        DB::table('evaluation')->whereIn('eid', $ids)->delete();
        DB::table('judging_scores')->whereIn('eid', $ids)->delete();
        DB::table('judging_flights')->whereIn('id', $this->flights !== [] ? $this->flights : [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tables !== [] ? $this->tables : [0])->delete();
        DB::table('brewing')->whereIn('id', $ids)->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::JUDGE_ID])->delete();

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function makeEntry(array $overrides = []): int
    {
        $id = (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'Move Fixture',
            'brewCategorySort' => '10',
            'brewCategory' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => self::SOURCE_BREWER,
            'brewConfirmed' => '1',
            'brewPaid' => 1,
        ], $overrides));
        $this->entries[] = $id;

        return $id;
    }

    /**
     * Wrong entry (beer 10/A) plus destination (cider 12/018, so the style
     * id — and therefore the scoresheet variant inputs — differ).
     *
     * @return array{0: int, 1: int}
     */
    private function makePair(): array
    {
        $source = $this->makeEntry();

        $dest = $this->makeEntry([
            'brewName' => 'Right Entry',
            'brewCategorySort' => '12',
            'brewCategory' => '12',
            'brewSubCategory' => '018',
            'brewBrewerID' => self::DEST_BREWER,
        ]);

        return [$source, $dest];
    }

    /** Flight the entry to a fresh table; returns the table id. */
    private function makeTableForEntry(int $entryId): int
    {
        // evaluation.evalTable is smallint(5) and a reused test database has
        // pushed judging_tables' AUTO_INCREMENT well past it — pin a small id.
        $tableId = 4100 + count($this->tables);

        DB::table('judging_tables')->insert([
            'id' => $tableId,
            'tableName' => 'Eval Move Table',
            'tableNumber' => random_int(100, 999),
            'tableStyles' => '',
        ]);
        $this->tables[] = $tableId;

        $this->flights[] = (int) DB::table('judging_flights')->insertGetId([
            'flightTable' => $tableId,
            'flightNumber' => 1,
            'flightEntryID' => (string) $entryId,
            'flightRound' => 1,
        ]);

        return $tableId;
    }

    /** @param array<string, mixed> $overrides */
    private function makeEvaluation(int $eid, int $judgeUid, array $overrides = []): int
    {
        return (int) DB::table('evaluation')->insertGetId(array_merge([
            'eid' => $eid,
            'uid' => self::SOURCE_BREWER,
            'evalJudgeInfo' => $judgeUid,
            'evalStyle' => null,
            'evalFinalScore' => 40,
            'evalPlace' => 0,
            'evalMiniBOS' => 0,
            'evalInitialDate' => time(),
            'evalUpdatedDate' => time(),
        ], $overrides));
    }

    private function makeScoreRow(int $eid): void
    {
        DB::table('judging_scores')->insert([
            'eid' => $eid,
            'bid' => self::SOURCE_BREWER,
            'scoreTable' => 1,
            'scoreEntry' => 40.0,
            'scorePlace' => null,
            'scoreType' => 0,
            'scoreMiniBOS' => 0,
        ]);
    }

    private function styleId(string $group, string $num): ?int
    {
        $id = DB::table('styles')->where('brewStyleGroup', $group)->where('brewStyleNum', $num)->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function evaluation(int $id): \stdClass
    {
        $row = DB::table('evaluation')->where('id', $id)->first();
        if (! $row instanceof \stdClass) {
            self::fail('evaluation row '.$id.' is missing');
        }

        return $row;
    }

    public function test_move_repoints_entry_derived_columns_and_clears_official_scores(): void
    {
        [$source, $dest] = $this->makePair();
        $tableId = $this->makeTableForEntry($dest);

        $destStyle = $this->styleId('12', '018');
        self::assertNotNull($destStyle, 'baseline styles must carry 12/018 for this pin');

        $evaluationId = $this->makeEvaluation($source, self::JUDGE_ID, [
            'evalStyle' => $this->styleId('10', 'A'),
            'evalFinalScore' => 41,
            'evalOverallComments' => 'Written against the wrong entry',
        ]);

        $this->makeScoreRow($source);
        $this->makeScoreRow($dest);

        $this->loginWithEmail(self::ADMIN);

        $this->post('/eval/move', [
            'evaluationId' => $evaluationId,
            'target' => (string) $dest,
            'confirm' => 'yes',
        ])
            ->assertRedirect(route('eval.output', ['entryId' => $dest, 'archive' => null]))
            ->assertSessionHas('status', fn (mixed $status): bool => is_string($status)
                && str_contains($status, 'moved to entry '.$dest)
                && str_contains($status, 'Import Score Data'));

        $row = $this->evaluation($evaluationId);
        self::assertSame($dest, (int) $row->eid);
        self::assertSame(self::DEST_BREWER, (int) $row->uid);
        self::assertSame($destStyle, (int) $row->evalStyle);
        self::assertSame($tableId, (int) $row->evalTable);
        self::assertSame(self::JUDGE_ID, (int) $row->evalJudgeInfo);

        // The judges' work travels with the evaluation untouched.
        self::assertSame(41, (int) $row->evalFinalScore);
        self::assertSame('Written against the wrong entry', $row->evalOverallComments);

        // Both entries lost their derived official score rows.
        self::assertSame(0, DB::table('judging_scores')->whereIn('eid', [$source, $dest])->count());

        // The evaluation now shows on the destination's output page.
        $this->get('/eval/scoresheet/'.$dest.'/output')
            ->assertOk()
            ->assertSee('Written against the wrong entry');
    }

    public function test_move_accepts_a_judging_number_as_the_destination(): void
    {
        [$source, $dest] = $this->makePair();
        DB::table('brewing')->where('id', $dest)->update(['brewJudgingNumber' => 'ZZ42']);

        $evaluationId = $this->makeEvaluation($source, self::JUDGE_ID);
        $this->loginWithEmail(self::ADMIN);

        $this->post('/eval/move', [
            'evaluationId' => $evaluationId,
            'target' => 'ZZ42',
            'confirm' => 'yes',
        ])->assertSessionHas('status');

        self::assertSame($dest, (int) $this->evaluation($evaluationId)->eid);
    }

    public function test_move_rejects_unknown_absent_and_self_targets(): void
    {
        [$source, $dest] = $this->makePair();
        $evaluationId = $this->makeEvaluation($source, self::JUDGE_ID);
        $this->loginWithEmail(self::ADMIN);

        foreach ([
            ['target' => '999999', 'message' => 'unknown entry'],
            ['target' => (string) $source, 'message' => 'the entry it is already on'],
            ['target' => (string) $dest, 'evaluationId' => 999999, 'message' => 'absent evaluation'],
        ] as $case) {
            $this->from('/eval/scoresheet/'.$source.'/output')
                ->post('/eval/move', [
                    'evaluationId' => $case['evaluationId'] ?? $evaluationId,
                    'target' => $case['target'],
                    'confirm' => 'yes',
                ])
                ->assertSessionHasErrors('target');
        }

        self::assertSame($source, (int) $this->evaluation($evaluationId)->eid);
    }

    public function test_move_requires_admin_and_confirmation(): void
    {
        [$source, $dest] = $this->makePair();
        $evaluationId = $this->makeEvaluation($source, self::JUDGE_ID);

        $payload = ['evaluationId' => $evaluationId, 'target' => (string) $dest, 'confirm' => 'yes'];

        $this->post('/eval/move', $payload)->assertRedirect('/login');

        $this->loginWithEmail(self::JUDGE);
        $this->post('/eval/move', $payload)->assertRedirect('/?msg=99');

        $this->loginWithEmail(self::ADMIN);
        $this->from('/eval/scoresheet/'.$source.'/output')
            ->post('/eval/move', ['evaluationId' => $evaluationId, 'target' => (string) $dest])
            ->assertSessionHasErrors('confirm');

        self::assertSame($source, (int) $this->evaluation($evaluationId)->eid);
    }

    public function test_output_page_shows_move_block_to_admins_only(): void
    {
        [$source] = $this->makePair();
        $this->makeEvaluation($source, self::JUDGE_ID);

        $this->loginWithEmail(self::ADMIN);
        $this->get('/eval/scoresheet/'.$source.'/output')
            ->assertOk()
            ->assertSee('Admin — Move an evaluation')
            ->assertSee('name="target"', false)
            ->assertSee('move-entries', false);

        $this->loginWithEmail(self::JUDGE);
        $this->get('/eval/scoresheet/'.$source.'/output')
            ->assertOk()
            ->assertDontSee('Admin — Move an evaluation');
    }
}
