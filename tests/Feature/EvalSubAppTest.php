<?php

declare(strict_types=1);

namespace Tests\Feature;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Eval sub-app feature pins (P4.6):
 *  - /eval is auth-gated; admin panel + import are userLevel<=1 only;
 *  - the full, checklist, structured and NW Cider scoresheet variants
 *    render per jPrefsScoresheet + the entry's style type;
 *  - process round-trip stores an evaluation bound to the submitting
 *    judge (legacy ownership binding) and 403s foreign edits;
 *  - import consensus round-trip: ≥2 evaluations ⇒ judging_scores row
 *    with MAX score, max place, max mini-BOS; single evaluation stays in
 *    the singles bucket; existing rows keep their entered score and only
 *    gain place/type/mini-BOS; re-import is idempotent.
 */
final class EvalSubAppTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'admin.eval@brewingcompetitions.com';

    private const JUDGE = 'judge.eval@brewingcompetitions.com';

    private const OTHER_JUDGE = 'judge2.eval@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entries = [];

    /** @var list<int> */
    private array $tables = [];

    /** @var list<int> */
    private array $flights = [];

    /** @var list<string> */
    private array $assignments = [];

    /** @var array<string, string> judging_preferences values to restore */
    private array $origJudgingPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        foreach ([self::ADMIN, self::JUDGE, self::OTHER_JUDGE] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        DB::table('users')->insert([
            ['id' => 9201, 'user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => 9202, 'user_name' => self::JUDGE, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => 9203, 'user_name' => self::OTHER_JUDGE, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        DB::table('brewer')->insert([
            ['uid' => 9202, 'brewerFirstName' => 'Eval', 'brewerLastName' => 'Judge', 'brewerEmail' => self::JUDGE, 'brewerJudge' => 'Y'],
            ['uid' => 9203, 'brewerFirstName' => 'Second', 'brewerLastName' => 'Judge', 'brewerEmail' => self::OTHER_JUDGE, 'brewerJudge' => 'Y'],
        ]);

        foreach (['jPrefsScoresheet', 'jPrefsJudgingOpen', 'jPrefsJudgingClosed', 'jPrefsMinWords', 'jPrefsScoreDispMax'] as $key) {
            $this->origJudgingPrefs[$key] = (string) DB::table('judging_preferences')->where('id', 1)->value($key);
        }
    }

    protected function tearDown(): void
    {
        DB::table('evaluation')->whereIn('eid', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('judging_scores')->whereIn('eid', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('judging_flights')->whereIn('id', $this->flights !== [] ? $this->flights : [0])->delete();
        foreach ($this->assignments as $assignTable) {
            DB::table('judging_assignments')->where(['bid' => 9202, 'assignTable' => $assignTable])->delete();
        }
        DB::table('judging_tables')->whereIn('id', $this->tables !== [] ? $this->tables : [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('staff')->where('uid', 9202)->delete();
        DB::table('brewer')->whereIn('uid', [9202, 9203])->delete();
        DB::table('users')->whereIn('id', [9201, 9202, 9203])->delete();
        DB::table('judging_preferences')->where('id', 1)->update($this->origJudgingPrefs);

        parent::tearDown();
    }

    private function login(string $email): void
    {
        $this->loginWithEmail($email);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return int brewing.id
     */
    private function makeEntry(array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'Eval Fixture',
            'brewCategorySort' => '10',
            'brewCategory' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 9202,
            'brewConfirmed' => '1',
            'brewPaid' => 1,
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entries[] = $id;

        return $id;
    }

    /** Entry whose style resolves to a cider (baseline styles 12/018, type 2). */
    private function makeCiderEntry(): int
    {
        return $this->makeEntry([
            'brewCategorySort' => '12',
            'brewCategory' => '12',
            'brewSubCategory' => '018',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvaluation(int $eid, int $judgeUid, array $overrides = []): void
    {
        DB::table('evaluation')->insert(array_merge([
            'eid' => $eid,
            'uid' => 9202,
            'evalJudgeInfo' => $judgeUid,
            'evalStyle' => null,
            'evalFinalScore' => 40,
            'evalPlace' => 0,
            'evalMiniBOS' => 0,
            'evalInitialDate' => time(),
            'evalUpdatedDate' => time(),
        ], $overrides));
    }

    private function makeAssignedTable(int ...$entryIds): int
    {
        DB::table('judging_tables')->insert([
            'tableName' => 'Eval Test Table',
            'tableNumber' => rand(100, 999),
            'tableStyles' => '',
        ]);
        $tableId = (int) DB::table('judging_tables')->max('id');
        $this->tables[] = $tableId;

        if ($entryIds !== []) {
            DB::table('judging_flights')->insert([
                'flightTable' => $tableId,
                'flightNumber' => 1,
                'flightEntryID' => implode(',', $entryIds),
            ]);
            $this->flights[] = (int) DB::table('judging_flights')->max('id');
        }

        DB::table('judging_assignments')->insert([
            'bid' => 9202,
            'assignment' => 'J',
            'assignTable' => $tableId,
        ]);
        $this->assignments[] = (string) $tableId;

        return $tableId;
    }

    /**
     * The legacy endpoint was an XHR call that echoed JSON; assert that
     * contract explicitly (a plain form POST redirects instead — covered by
     * test_import_from_browser_form_redirects_with_summary).
     *
     * @return TestResponse<Response>
     */
    private function import(): TestResponse
    {
        return $this->postJson('/eval/import-scores');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/eval')->assertRedirect('/login');
    }

    public function test_dashboard_renders_assigned_table_and_entries_for_judge(): void
    {
        $entryId = $this->makeEntry();
        $this->makeAssignedTable($entryId);

        $this->login(self::JUDGE);
        $this->get('/eval')
            ->assertOk()
            ->assertSee('Judging Dashboard')
            ->assertSee('Eval Fixture');
    }

    public function test_admin_panel_lists_singles_and_entrant_is_blind_to_it(): void
    {
        $entryId = $this->makeEntry();
        $this->makeEvaluation($entryId, 9202); // single evaluation

        $this->login(self::ADMIN);
        $this->get('/eval')
            ->assertOk()
            ->assertSee('Import Score Data')
            ->assertSee((string) $entryId); // singles alert

        $this->login(self::JUDGE);
        $this->get('/eval')->assertOk()->assertDontSee('Import Score Data');
    }

    public function test_scoresheet_variants_render_per_preference(): void
    {
        $beer = $this->makeEntry();

        // Baseline ships jPrefsScoresheet=3 → structured.
        $this->login(self::JUDGE);
        $this->get("/eval/scoresheet/{$beer}")
            ->assertOk()
            ->assertSee('Structured Scoresheet')
            ->assertSee('Fermentation characteristics'); // tick label

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoresheet' => '1']);
        $this->get("/eval/scoresheet/{$beer}")->assertOk()->assertSee('Full Scoresheet');

        // Preference 2 renders the checklist sheet for a beer entry.
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoresheet' => '2']);
        $this->get("/eval/scoresheet/{$beer}")
            ->assertOk()
            ->assertSee('Checklist Scoresheet')
            ->assertSee('name="evalAromaMalt"', false);

        // …but a cider entry falls back to the full sheet (checklist is beer only).
        $cider = $this->makeCiderEntry();
        $this->get("/eval/scoresheet/{$cider}")->assertOk()->assertSee('Full Scoresheet');
    }

    public function test_preference_4_renders_nw_cider_only_for_cider_entries(): void
    {
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoresheet' => '4']);

        $beer = $this->makeEntry();
        $cider = $this->makeCiderEntry();

        $this->login(self::JUDGE);

        // Beer under preference 4 stays on the generic structured sheet.
        $this->get("/eval/scoresheet/{$beer}")->assertOk()->assertSee('Structured Scoresheet');

        // Cider under preference 4 gets the NW Cider sheet.
        $this->get("/eval/scoresheet/{$cider}")
            ->assertOk()
            ->assertSee('NW Cider Structured Scoresheet')
            ->assertSee('name="evalAppearanceColorChoice"', false);
    }

    public function test_checklist_submission_stores_factor_and_descriptor_columns(): void
    {
        $entryId = $this->makeEntry();

        $this->login(self::JUDGE);
        $this->post('/eval/process', [
            'eid' => $entryId,
            'evalScoresheet' => 2,
            'evalFinalScore' => 40,
            'evalOverallScore' => 8,
            'evalAromaMalt' => 'Malt: Low',
            'evalAromaHops' => 'Hops: Medium',
            'evalAromaChecklistDesc' => ['Malt: Caramel', 'Hops: Citrusy'],
            'evalFlaws' => ['Diacetyl'],
        ])->assertRedirect('/eval?msg=3');

        $row = DB::table('evaluation')->where('eid', $entryId)->first();
        self::assertNotNull($row);
        self::assertSame(2, (int) $row->evalScoresheet);
        self::assertSame('Malt: Low, Hops: Medium', $row->evalAromaChecklist);
        self::assertSame('Malt: Caramel, Hops: Citrusy', $row->evalAromaChecklistDesc);
        self::assertSame('Diacetyl', $row->evalFlaws);

        // The output view renders the checklist columns for this variant.
        $this->get("/eval/scoresheet/{$entryId}/output")
            ->assertOk()
            ->assertSee('Malt: Low, Hops: Medium');
    }

    public function test_nw_cider_submission_stores_json_sections(): void
    {
        $entryId = $this->makeCiderEntry();

        $this->login(self::JUDGE);
        $this->post('/eval/process', [
            'eid' => $entryId,
            'evalScoresheet' => 4,
            'evalFinalScore' => 38,
            'evalOverallScore' => 8,
            'evalAppearanceColorChoice' => 'Gold',
            'evalAppearanceClarity' => '3',
            'evalAromaCharacteristics' => 'Apple, floral',
            'evalAromaIntensity' => '2',
        ])->assertRedirect('/eval?msg=3');

        $row = DB::table('evaluation')->where('eid', $entryId)->first();
        self::assertNotNull($row);
        self::assertSame(4, (int) $row->evalScoresheet);

        $appearance = json_decode((string) $row->evalAppearanceChecklist, true);
        self::assertSame('Gold', $appearance['evalAppearanceColor']);
        self::assertSame('3', $appearance['evalAppearanceClarity']);

        $aroma = json_decode((string) $row->evalAromaChecklist, true);
        self::assertSame('Apple, floral', $aroma['evalAromaCharacteristics']);
        self::assertSame('2', $aroma['evalAromaIntensity']);

        // The output view renders the decoded NW Cider sections.
        $this->get("/eval/scoresheet/{$entryId}/output")
            ->assertOk()
            ->assertSee('Gold')
            ->assertSee('Apple, floral');
    }

    public function test_min_words_enforced_on_comment_fields_by_variant(): void
    {
        $entryId = $this->makeEntry();
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsMinWords' => 5]);

        $this->login(self::JUDGE);

        // Structured sheet: a too-short section comment is rejected.
        $this->from("/eval/scoresheet/{$entryId}")
            ->post('/eval/process', [
                'eid' => $entryId,
                'evalScoresheet' => 3,
                'evalFinalScore' => 40,
                'evalOverallScore' => 8,
                'evalAromaComments' => 'too short',
            ])
            ->assertSessionHasErrors('evalAromaComments');
        self::assertNull(DB::table('evaluation')->where('eid', $entryId)->first());

        // A comment meeting the minimum is stored.
        $this->from("/eval/scoresheet/{$entryId}")
            ->post('/eval/process', [
                'eid' => $entryId,
                'evalScoresheet' => 3,
                'evalFinalScore' => 40,
                'evalOverallScore' => 8,
                'evalAromaComments' => 'this comment now has enough words',
            ])
            ->assertRedirect('/eval?msg=3');
        self::assertSame(
            'this comment now has enough words',
            DB::table('evaluation')->where('eid', $entryId)->value('evalAromaComments'),
        );

        // NW Cider sheet enforces its shared overall-comments field; its
        // structured Characteristics inputs are not comment fields.
        $this->from("/eval/scoresheet/{$entryId}")
            ->post('/eval/process', [
                'eid' => $entryId,
                'evalScoresheet' => 4,
                'evalFinalScore' => 40,
                'evalOverallScore' => 8,
                'evalAromaCharacteristics' => 'short',
                'evalOverallComments' => 'too short',
            ])
            ->assertSessionHasErrors('evalOverallComments');
    }

    public function test_structured_submission_renders_ticks_on_output(): void
    {
        $entryId = $this->makeEntry();

        $this->login(self::JUDGE);
        $this->post('/eval/process', [
            'eid' => $entryId,
            'evalScoresheet' => 3,
            'evalFinalScore' => 40,
            'evalOverallScore' => 8,
            'evalAromaScore' => 10,
            'evalFlavorScore' => 20,
            'aromaTicks' => ['Fermentation characteristics', 'Hoppy'],
            'flavorTicks' => ['Malty'],
        ])->assertRedirect('/eval?msg=3');

        self::assertSame(
            'Fermentation characteristics, Hoppy',
            DB::table('evaluation')->where('eid', $entryId)->value('evalAromaChecklist'),
        );

        // The structured tick grid renders on read-back (variant 3 dispatch).
        $this->get("/eval/scoresheet/{$entryId}/output")
            ->assertOk()
            ->assertSee('Fermentation characteristics')
            ->assertSee('Malty');
    }

    public function test_output_warns_when_scores_exceed_consensus_dispersion(): void
    {
        $entryId = $this->makeEntry();
        $this->makeEvaluation($entryId, 9202, ['evalFinalScore' => 30]);
        $this->makeEvaluation($entryId, 9203, ['evalFinalScore' => 45]);

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoreDispMax' => 10]);

        $this->login(self::ADMIN);
        $this->get("/eval/scoresheet/{$entryId}/output")
            ->assertOk()
            ->assertSee('differ by more than the configured maximum');

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoreDispMax' => 20]);
        $this->get("/eval/scoresheet/{$entryId}/output")
            ->assertOk()
            ->assertDontSee('differ by more than the configured maximum');
    }

    public function test_process_round_trip_binds_evaluation_to_submitting_judge(): void
    {
        $entryId = $this->makeEntry();

        $this->login(self::JUDGE);
        $this->post('/eval/process', [
            'eid' => $entryId,
            'uid' => 9202,
            'evalFinalScore' => 42,
            'evalOverallScore' => 8,
            'evalAromaComments' => 'Malty, clean',
            // A judge cannot sign someone else's scoresheet.
            'evalJudgeInfo' => 9201,
        ])->assertRedirect('/eval?msg=3');

        $row = DB::table('evaluation')->where('eid', $entryId)->first();
        self::assertNotNull($row);
        self::assertSame(42, (int) $row->evalFinalScore);
        self::assertSame(9202, (int) $row->evalJudgeInfo);

        // Another judge cannot edit it; the owner can.
        $this->login(self::OTHER_JUDGE);
        $this->post("/eval/process/{$row->id}", ['eid' => $entryId, 'evalFinalScore' => 30])
            ->assertStatus(403);

        $this->login(self::JUDGE);
        $this->post("/eval/process/{$row->id}", ['eid' => $entryId, 'evalFinalScore' => 44])
            ->assertRedirect('/eval?msg=2');
        self::assertSame(44, (int) DB::table('evaluation')->where('id', $row->id)->value('evalFinalScore'));
    }

    public function test_import_consensus_round_trip_and_idempotency(): void
    {
        // Consensus entry: two judges, MAX wins; place and mini-BOS take
        // the max across judges.
        $consensus = $this->makeEntry();
        $this->makeEvaluation($consensus, 9202, ['evalFinalScore' => 38, 'evalPlace' => 0, 'evalMiniBOS' => 0]);
        $this->makeEvaluation($consensus, 9203, ['evalFinalScore' => 42, 'evalPlace' => 2, 'evalMiniBOS' => 1]);

        // Single evaluation: never imported.
        $single = $this->makeEntry();
        $this->makeEvaluation($single, 9202, ['evalFinalScore' => 40]);

        // Already-scored entry: the entered score must survive untouched;
        // the empty place/mini-BOS get filled from the evaluations.
        $scored = $this->makeEntry();
        DB::table('judging_scores')->insert([
            'eid' => $scored,
            'bid' => 9202,
            'scoreTable' => 1,
            'scoreEntry' => 40.5,
            'scorePlace' => null,
            'scoreType' => 0,
            'scoreMiniBOS' => 0,
        ]);
        $this->makeEvaluation($scored, 9202, ['evalFinalScore' => 39, 'evalPlace' => 3, 'evalMiniBOS' => 0]);
        $this->makeEvaluation($scored, 9203, ['evalFinalScore' => 41, 'evalPlace' => 0, 'evalMiniBOS' => 1]);

        $this->login(self::ADMIN);
        $this->import()
            ->assertStatus(200)
            ->assertJson([
                'status' => '1',
                'scores_imported_count' => '1',
                'scores_updated_count' => '1',
                'singles' => [$single],
                'scored_places_discrepency_count' => 0,
            ]);

        $imported = DB::table('judging_scores')->where('eid', $consensus)->first();
        self::assertNotNull($imported);
        self::assertSame(42.0, (float) $imported->scoreEntry);
        self::assertSame(2.0, (float) $imported->scorePlace);
        self::assertSame(1, (int) $imported->scoreMiniBOS);

        $kept = DB::table('judging_scores')->where('eid', $scored)->first();
        self::assertNotNull($kept);
        self::assertSame(40.5, (float) $kept->scoreEntry, 'entered score overwritten');
        self::assertSame(3.0, (float) $kept->scorePlace);
        self::assertSame(0, (int) $kept->scoreType); // no evalStyle ⇒ untouched

        // Idempotent re-import: nothing new to do.
        $before = DB::table('judging_scores')->count();
        $this->import()->assertJson([
            'status' => '1',
            'scores_imported_count' => '0',
            'scores_updated_count' => '0',
        ]);
        self::assertSame($before, DB::table('judging_scores')->count());
    }

    public function test_import_requires_admin(): void
    {
        // Auth middleware bounces guests to /login before the gate.
        $this->post('/eval/import-scores')->assertRedirect('/login');

        $this->login(self::JUDGE);
        $this->import()->assertRedirect('/?msg=99');
    }

    public function test_import_from_browser_form_redirects_with_summary(): void
    {
        // Regression: the confirm/dashboard forms are plain POSTs, so the
        // browser must land on a page, not the raw JSON envelope.
        $this->login(self::ADMIN);

        $this->post('/eval/import-scores')
            ->assertRedirect('/eval')
            ->assertSessionHas('status', fn (mixed $status): bool => is_string($status)
                && str_starts_with($status, 'Import complete:'));

        $this->get('/eval')->assertSee('Import complete:', false);
    }

    public function test_my_account_gates_judging_dashboard_on_window_and_staff_judge(): void
    {
        // Legacy gates on the staff row (brewer_assignment → staff_judge),
        // not on a judging_assignments row.
        DB::table('staff')->updateOrInsert(['uid' => 9202], ['staff_judge' => 1]);

        // Baseline judging window closed long ago.
        $this->login(self::JUDGE);
        $this->get('/eval/my-account')
            ->assertOk()
            ->assertDontSee('You are assigned as a judge');

        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsJudgingOpen' => (string) (time() - 3600),
            'jPrefsJudgingClosed' => (string) (time() + 3600),
        ]);

        $this->get('/eval/my-account')
            ->assertOk()
            ->assertSee('Judging Dashboard');
    }
}
