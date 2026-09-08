<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Judging\BosController;
use App\Support\Judging\FlightAssignment;
use App\Support\Results\Place;
use Illuminate\Support\Facades\DB;

/**
 * Score entry + BOS + special best (spec §6 P4.4, ticket 04). Runs a full
 * mini-season on a seeded fixture — table with styles, received entries,
 * score entry, BOS advancement — asserting the DB rows the public winners
 * pages (Slice A) read.
 *
 * Pinned legacy behaviors:
 *   - process_judging_scores.inc.php: saving a table wipes its
 *     judging_scores rows and re-inserts posted ones; a row is written when
 *     ANY of entry/place/miniBOS is non-empty (#5); an empty mini-BOS box
 *     writes 0, never NULL (#4); every text column blank_to_null'd.
 *   - HM stores '5' in judging_scores.scorePlace (FLOAT col) AND in
 *     judging_scores_bos.scorePlace (varchar) so the winners filter
 *     IN ('1'..'5') keeps working (ledger/winners-display.md #3/#4).
 *   - admin_judging_scores_bos.db.php:32-34: eligibility per
 *     styleTypeBOSMethod via Place::bosEligiblePlaces() (explicit list);
 *     Mead/Cider merges scoreTypes 2+3 (:26).
 *   - process_judging_scores_bos.inc.php: place+existing → update,
 *     place+none → insert, cleared place → delete.
 *   - process_special_best_info/data.inc.php: blank_to_null storage,
 *     judging-number→entry resolution, sbi_display_places never written.
 */
final class JudgingScoresBosTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'scores.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9401;

    private const ENTRANT_ID = 9402;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $styleIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $origStyleTypes = [];

    /** @var list<int> */
    private array $specialBestIds = [];

    private int $tableId = 0;

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
        foreach ($this->specialBestIds as $sid) {
            DB::table('special_best_data')->where('sid', $sid)->delete();
            DB::table('special_best_info')->delete($sid);
        }
        DB::table('judging_scores')->whereIn('eid', $this->entryIds ?: [0])->delete();
        DB::table('judging_scores_bos')->whereIn('eid', $this->entryIds ?: [0])->delete();
        DB::table('judging_flights')->where('flightTable', $this->tableId)->delete();
        DB::table('judging_tables')->delete($this->tableId);
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();

        foreach ([self::ENTRANT_ID] as $uid) {
            DB::table('brewer')->where('uid', $uid)->delete();
        }
        foreach ($this->origStyleTypes as $id => $row) {
            if ($row === []) {
                DB::table('style_types')->where('id', $id)->delete();
            } else {
                DB::table('style_types')->where('id', $id)->update($row);
            }
        }
        DB::table('users')->delete(self::ADMIN_ID);

        parent::tearDown();
    }

    /**
     * Full mini-season: assign → score → BOS. Two entries scored on one
     * table; 1st place + mini-BOS on one, HM ('5') on the other; then the
     * BOS round for the beer style type (BOSMethod=2 ⇒ top two eligible).
     */
    public function test_mini_season_assign_score_bos(): void
    {
        // ── Fixture: brewer, styles, style types, table, received entries.
        DB::table('brewer')->insert([
            'uid' => self::ENTRANT_ID,
            'brewerFirstName' => 'Mini',
            'brewerLastName' => 'Season',
            'brewerEmail' => 'mini.season@brewingcompetitions.com',
        ]);

        $styleA = $this->style('28', 'A', 'Common Cider', 'Cider');
        $styleD = $this->style('1', 'D', 'Standard Bitter', 'Ale');
        $beerType = $this->styleType(1, 'Beer', 'Y', '2');
        $this->styleType(2, 'Cider', 'Y', '1');
        $this->table($styleD.','.$styleA);

        $bitter = $this->entry(['brewJudgingNumber' => '400001']);
        $cider = $this->entry(['brewJudgingNumber' => '400002', 'brewCategorySort' => '28', 'brewCategory' => '28', 'brewSubCategory' => 'A']);

        // Assign: flight rows via the engine's row shape (contract API).
        foreach ([$bitter, $cider] as $eid) {
            DB::table('judging_flights')->insert(FlightAssignment::flightRow($this->tableId, 1, $eid));
        }

        // ── Score the table: bitter gets 36 pts / 1st / mini-BOS checked;
        // cider gets only a place of HM ('5') — no score, unchecked box.
        $this->put('/admin/judging/scores/'.$this->tableId, [
            'score_id' => [(string) $bitter, (string) $cider],
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scorePlace'.$bitter => '1',
            'scoreMiniBOS'.$bitter => '1',
            'scoreType'.$bitter => '1',
            'eid'.$cider => (string) $cider,
            'bid'.$cider => (string) self::ENTRANT_ID,
            'scoreEntry'.$cider => '',
            'scorePlace'.$cider => '5',
            'scoreMiniBOS'.$cider => '',
            'scoreType'.$cider => '2',
        ])->assertRedirect('/admin/judging/scores');

        // Smoke-render the list + per-table form with real rows present.
        $this->get('/admin/judging/scores')->assertOk();
        $this->get('/admin/judging/scores/'.$this->tableId.'/edit')->assertOk();

        $rows = DB::table('judging_scores')->where('scoreTable', $this->tableId)->get()->keyBy('eid');
        $this->assertSame(2, $rows->count());

        $bitterRow = $rows[$bitter];
        self::assertNotNull($bitterRow, 'bitter row must exist after scoring');
        $this->assertSame(36.0, (float) $bitterRow->scoreEntry);
        $this->assertSame('1', (string) $bitterRow->scorePlace);
        $this->assertSame(1, (int) $bitterRow->scoreMiniBOS);
        $this->assertSame(self::ENTRANT_ID, (int) $bitterRow->bid);

        // Mini-BOS empty ⇒ 0 written, not NULL (#4).
        $ciderRow = $rows[$cider];
        self::assertNotNull($ciderRow, 'cider row must exist after scoring');
        $this->assertNotNull($ciderRow->scoreMiniBOS, 'empty checkbox must write 0, not NULL');
        $this->assertSame(0, (int) $ciderRow->scoreMiniBOS);
        $this->assertNull($ciderRow->scoreEntry, 'blank_to_null: empty score column is NULL');
        // '5' stored, never literal 'HM', so the winners filter sees it.
        $this->assertSame('5', (string) $ciderRow->scorePlace);

        // ── Partial re-save: ONLY cider's mini-BOS changes; the wipe-and-
        // reinsert must keep its place and stay a single row.
        $this->put('/admin/judging/scores/'.$this->tableId, [
            'score_id' => [(string) $bitter, (string) $cider],
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scorePlace'.$bitter => '1',
            'scoreMiniBOS'.$bitter => '1',
            'scoreType'.$bitter => '1',
            'eid'.$cider => (string) $cider,
            'bid'.$cider => (string) self::ENTRANT_ID,
            'scoreEntry'.$cider => '',
            'scorePlace'.$cider => '5',
            'scoreMiniBOS'.$cider => '1',
            'scoreType'.$cider => '2',
        ])->assertRedirect('/admin/judging/scores');

        $rows = DB::table('judging_scores')->where('scoreTable', $this->tableId)->get()->keyBy('eid');
        $this->assertSame(2, $rows->count(), 're-save must not duplicate rows');
        $ciderRow = $rows[$cider];
        self::assertNotNull($ciderRow, 'cider row must survive partial re-save');
        $this->assertSame('5', (string) $ciderRow->scorePlace, 'place preserved across partial re-save');
        $this->assertSame(1, (int) $ciderRow->scoreMiniBOS);
        $this->assertNull($ciderRow->scoreEntry);

        // A fully-empty slot writes NO row at all (#5).
        $third = $this->entry(['brewJudgingNumber' => '400003']);
        $payload = [
            'score_id' => [(string) $bitter, (string) $cider, (string) $third],
            'eid'.$third => (string) $third,
            'bid'.$third => (string) self::ENTRANT_ID,
            'scoreEntry'.$third => '',
            'scorePlace'.$third => '',
            'scoreMiniBOS'.$third => '',
            'scoreType'.$third => '1',
        ];
        unset($payload['scoreEntry'.$third], $payload['scorePlace'.$third], $payload['scoreMiniBOS'.$third]);
        $this->put('/admin/judging/scores/'.$this->tableId, $payload + [
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scorePlace'.$bitter => '1',
            'scoreMiniBOS'.$bitter => '1',
            'scoreType'.$bitter => '1',
            'eid'.$cider => (string) $cider,
            'bid'.$cider => (string) self::ENTRANT_ID,
            'scoreEntry'.$cider => '',
            'scorePlace'.$cider => '5',
            'scoreMiniBOS'.$cider => '1',
            'scoreType'.$cider => '2',
        ])->assertRedirect('/admin/judging/scores');

        $this->assertFalse(DB::table('judging_scores')->where('eid', $third)->exists(), 'all-empty slot must not write a row');
        $this->assertSame(2, DB::table('judging_scores')->where('scoreTable', $this->tableId)->count());

        // ── BOS round for Beer (BOSMethod=2 ⇒ top two eligible): the HM
        // cider row is scoreType 2 anyway; only the 1st bitter qualifies.
        $eligible = DB::table('judging_scores')
            ->whereIn('scoreType', ['1'])
            ->whereIn('scorePlace', Place::bosEligiblePlaces(2))
            ->pluck('eid')
            ->all();
        $this->assertSame([$bitter], $eligible, 'method 2 admits 1st/2nd only — HM is not eligible');

        $this->get('/admin/judging/bos/'.$beerType.'/edit')->assertOk();

        // First save without an existing row ⇒ INSERT path.
        $this->put('/admin/judging/bos/'.$beerType, [
            'score_id' => [(string) $bitter],
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scoreType'.$bitter => '1',
            'scorePlace'.$bitter => '5', // HM at BOS level still stores '5'
        ])->assertRedirect('/admin/judging/bos');

        $bos = DB::table('judging_scores_bos')->where('eid', $bitter)->sole();
        $this->assertSame('5', (string) $bos->scorePlace);
        $this->assertSame(36.0, (float) $bos->scoreEntry);

        // Second save WITH existing row id ⇒ UPDATE path, no duplicate insert.
        $bosId = (int) $bos->id;
        $this->put('/admin/judging/bos/'.$beerType, [
            'score_id' => [(string) $bitter],
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scoreType'.$bitter => '1',
            'id'.$bitter => (string) $bosId,
            'scorePlace'.$bitter => '1',
        ])->assertRedirect('/admin/judging/bos');

        $this->assertSame(1, DB::table('judging_scores_bos')->where('eid', $bitter)->count());
        $this->assertSame('1', (string) DB::table('judging_scores_bos')->where('id', $bosId)->value('scorePlace'));

        // Cleared place + existing row ⇒ DELETE path (:61-71).
        $this->put('/admin/judging/bos/'.$beerType, [
            'score_id' => [(string) $bitter],
            'eid'.$bitter => (string) $bitter,
            'bid'.$bitter => (string) self::ENTRANT_ID,
            'scoreEntry'.$bitter => '36',
            'scoreType'.$bitter => '1',
            'id'.$bitter => (string) $bosId,
            'scorePlace'.$bitter => '',
        ])->assertRedirect('/admin/judging/bos');

        $this->assertFalse(DB::table('judging_scores_bos')->where('eid', $bitter)->exists(), 'cleared place deletes the BOS row');

        $this->get('/admin/judging/bos')->assertOk();
    }

    /**
     * Eligibility per styleTypeBOSMethod against real rows: method 3 on a
     * merged Mead/Cider type admits 1st+2nd from BOTH scoreTypes 2 and 3;
     * '5'/HM rows are never eligible (explicit list, not string >=).
     */
    public function test_bos_eligibility_by_method_and_mead_cider_merge(): void
    {
        $styleM = $this->style('26', 'A', 'Traditional Mead', 'Mead');
        $this->styleType(2, 'Cider', 'Y', '1');
        $meadCider = $this->styleType(3, 'Mead/Cider', 'Y', '3');
        $this->table((string) $styleM);

        $first = $this->entry(['brewJudgingNumber' => '500001', 'brewCategorySort' => '26', 'brewCategory' => '26', 'brewSubCategory' => 'A']);
        $second = $this->entry(['brewJudgingNumber' => '500002', 'brewCategorySort' => '26', 'brewCategory' => '26', 'brewSubCategory' => 'A']);
        $hm = $this->entry(['brewJudgingNumber' => '500003', 'brewCategorySort' => '26', 'brewCategory' => '26', 'brewSubCategory' => 'A']);

        foreach ([[$first, '1'], [$second, '2'], [$hm, '5']] as [$eid, $place]) {
            DB::table('judging_scores')->insert([
                'eid' => $eid,
                'bid' => self::ENTRANT_ID,
                'scoreTable' => $this->tableId,
                'scoreEntry' => '30',
                'scorePlace' => $place,
                'scoreType' => 3, // mead side of the merged pair
                'scoreMiniBOS' => 0,
            ]);
        }

        $rows = $this->invokeEligible($meadCider);
        $this->assertCount(2, $rows, 'method 3 admits top three places only — the HM row is out');
        // Legacy orders by scoreTable only; beyond that MySQL order is
        // unspecified — assert the SET of eligible entries.
        $this->assertEqualsCanonicalizing([$first, $second], array_map(
            static fn ($r): int => $r instanceof \stdClass ? (int) $r->eid : 0,
            $rows,
        ));

        $this->get('/admin/judging/bos/'.$meadCider.'/edit')->assertOk();
        $this->get('/admin/judging/bos')->assertOk();
    }

    /**
     * Special-best CRUD parity: info write skips sbi_display_places exactly
     * like legacy's process script; data rows resolve judging numbers to
     * brewing ids; unknown judging numbers are skipped (legacy msg=24).
     */
    public function test_special_best_crud_resolves_judging_numbers(): void
    {
        $style = $this->style('1', 'D', 'Standard Bitter', 'Ale');
        $this->table((string) $style);
        $entry = $this->entry(['brewJudgingNumber' => '600001']);

        $this->post('/admin/judging/special-best', [
            'sbi_name' => 'Pro-Am with Test Brewery',
            'sbi_description' => '<b>Best</b> pro-am entry',
            'sbi_places' => '2',
            'sbi_rank' => '3',
        ])->assertRedirect('/admin/judging/special-best');

        $this->get('/admin/judging/special-best')->assertOk();
        $this->get('/admin/judging/special-best/create')->assertOk();
        $sbi = DB::table('special_best_info')->where('sbi_name', 'Pro-Am with Test Brewery')->sole();
        $this->specialBestIds[] = (int) $sbi->id;

        // Render the entries form (blank slots, sbi_places=2).
        $this->get('/admin/judging/special-best/'.((int) $sbi->id).'/entries')->assertOk();
        $this->assertSame('Best pro-am entry', (string) $sbi->sbi_description, 'strip_tags like the legacy purifier pass');
        $this->assertNull($sbi->sbi_display_places, 'legacy process never writes sbi_display_places');

        // Two slots: one resolves, one unknown → skipped.
        $this->put('/admin/judging/special-best/'.((int) $sbi->id).'/entries', [
            'slot_id' => ['new0', 'new1'],
            'sidnew0' => (string) $sbi->id,
            'entry_existsnew0' => 'N',
            'sbd_judging_nonew0' => '600001',
            'sbd_placenew0' => '1',
            'sidnew1' => (string) $sbi->id,
            'entry_existsnew1' => 'N',
            'sbd_judging_nonew1' => '999999',
            'sbd_placenew1' => '2',
        ])->assertRedirect('/admin/judging/special-best/'.((int) $sbi->id).'/entries?msg=24');

        $data = DB::table('special_best_data')->where('sid', (int) $sbi->id)->get();
        $this->assertCount(1, $data, 'unknown judging number skipped');
        $row = $data[0];
        self::assertNotNull($row, 'resolved data row must exist');
        $this->assertSame($entry, (int) $row->eid);
        $this->assertSame(self::ENTRANT_ID, (int) $row->bid);
        $this->assertSame('1', (string) $row->sbd_place);
        $this->assertNull($row->sbd_comments, 'blank_to_null on comments');

        // Edit path: update the existing row by its real row-id slot key.
        $rowKey = (string) $row->id;
        $this->put('/admin/judging/special-best/'.((int) $sbi->id).'/entries', [
            'slot_id' => [$rowKey],
            'sid'.$rowKey => (string) $sbi->id,
            'entry_exists'.$rowKey => 'Y',
            'sbd_judging_no'.$rowKey => '600001',
            'sbd_place'.$rowKey => '2',
        ])->assertRedirect('/admin/judging/special-best-data');

        $this->assertSame('2', (string) DB::table('special_best_data')->where('id', (int) $rowKey)->value('sbd_place'));
        $this->assertSame(1, DB::table('special_best_data')->where('sid', (int) $sbi->id)->count());

        // Delete cascades the category's data rows (process_delete.inc.php:66-101).
        $this->delete('/admin/judging/special-best/'.((int) $sbi->id))->assertRedirect('/admin/judging/special-best');
        $this->assertFalse(DB::table('special_best_info')->where('id', (int) $sbi->id)->exists());
        $this->assertSame(0, DB::table('special_best_data')->where('sid', (int) $sbi->id)->count());

        $this->get('/admin/judging/special-best-data')->assertOk();
    }

    public function test_guest_is_rejected(): void
    {
        $this->post('/logout');

        $this->get('/admin/judging/scores')->assertRedirect('/login');
        $this->get('/admin/judging/bos')->assertRedirect('/login');
        $this->get('/admin/judging/special-best')->assertRedirect('/login');
    }

    /**
     * The scores index must render legacy's control set
     * (admin/judging_scores.admin.php default view): All Tables / View BOS
     * buttons, "Add or Update Scores For..." populated from judging_tables,
     * the Print menu with per-style-type BOS pullsheets, and the
     * scores-entered status line. One dropdown selection followed through.
     */
    public function test_scores_index_renders_legacy_control_set(): void
    {
        $this->styleType(1, 'Beer', 'Y', '2');
        $this->styleType(2, 'Cider', 'Y', '1');
        $this->table('1');
        $entry = $this->entry(['brewJudgingNumber' => '800001']);
        // Unpaid/unreceived entries never reach the status-line denominator
        // (total_paid_received filters brewPaid='1' AND brewReceived='1').
        $this->entry(['brewJudgingNumber' => '800002', 'brewPaid' => 0, 'brewReceived' => 0]);

        $scoresEntered = DB::table('judging_scores')->count();
        $paidReceived = DB::table('brewing')->where('brewPaid', '1')->where('brewReceived', '1')->count();
        // The shared baseline DB may already carry score rows; the
        // empty-state paragraph only renders at exactly zero.
        $response = $this->get('/admin/judging/scores')
            ->assertOk()
            ->assertSee('All Tables')
            ->assertSee('View BOS Entries and Places')
            ->assertSee('Add or Update Scores For...')
            ->assertSee('Print...')
            ->assertSee('BOS Pullsheet for Beer')
            ->assertSee('BOS Pullsheet for Cider')
            ->assertSee("Scores have been entered for {$scoresEntered} of {$paidReceived} entries marked as paid and received.", false);
        if ($scoresEntered === 0) {
            $response->assertSee('No scores have been entered. If tables have been defined', false);
        }

        $table = DB::table('judging_tables')->find($this->tableId);
        /** @var \stdClass|null $table */
        self::assertNotNull($table, 'table must exist for status-line render');
        $response->assertSee("Table {$table->tableNumber}: {$table->tableName}", false);

        // Following one dropdown selection lands on the score grid, 200.
        $this->get('/admin/judging/scores/'.$this->tableId.'/edit')->assertOk();

        // With a score row present, the empty-state paragraph disappears.
        DB::table('judging_scores')->insert([
            'eid' => $entry,
            'bid' => self::ENTRANT_ID,
            'scoreTable' => $this->tableId,
            'scoreEntry' => '30',
            'scorePlace' => '1',
            'scoreType' => 1,
            'scoreMiniBOS' => 0,
        ]);
        $scoresEntered++;
        $this->get('/admin/judging/scores')
            ->assertOk()
            ->assertSee("Scores have been entered for {$scoresEntered} of {$paidReceived} entries marked as paid and received.", false)
            ->assertDontSee('no-scores-entered');
    }

    /**
     * The BOS index must render legacy's control set
     * (admin/judging_scores_bos.admin.php default view): All Scores / All
     * Tables buttons, "Add or Update..." per BOS style type, Print menu with
     * pullsheet + both Cup Mats variants. One dropdown selection through.
     */
    public function test_bos_index_renders_legacy_control_set(): void
    {
        $beerType = $this->styleType(1, 'Beer', 'Y', '2');
        $this->styleType(2, 'Cider', 'Y', '1');

        $this->get('/admin/judging/bos')
            ->assertOk()
            ->assertSee('All Scores')
            ->assertSee('All Tables')
            ->assertSee('Add or Update...')
            ->assertSee('BOS Places for Beer')
            ->assertSee('BOS Places for Cider')
            ->assertSee('Print...')
            ->assertSee('BOS Pullsheet for Beer')
            ->assertSee('BOS Cup Mats (Judging Numbers)')
            ->assertSee('BOS Cup Mats (Entry Numbers)');

        // Cup Mats items point at the existing port outputs.
        $this->get(route('outputs.bos_mat'))->assertOk();

        // Following one dropdown selection lands on the BOS places form, 200.
        $this->get('/admin/judging/bos/'.$beerType.'/edit')->assertOk();
    }

    // ── Fixture helpers ──────────────────────────────────────────────────

    private function style(string $group, string $num, string $name, string $type): int
    {
        DB::table('styles')->insert([
            'brewStyleGroup' => $group,
            'brewStyleNum' => $num,
            'brewStyle' => $name,
            'brewStyleType' => $type,
            'brewStyleVersion' => 'BJCP2021',
            'brewStyleOwn' => 'bcoe',
        ]);
        $id = (int) DB::table('styles')->max('id');
        $this->styleIds[] = $id;

        return $id;
    }

    private function styleType(int $id, string $name, string $bos, string $method): int
    {
        if (! isset($this->origStyleTypes[$id])) {
            $row = DB::table('style_types')->where('id', $id)->first();
            $this->origStyleTypes[$id] = $row === null ? [] : (array) $row;
        }

        DB::table('style_types')->updateOrInsert(['id' => $id], [
            'styleTypeName' => $name,
            'styleTypeBOS' => $bos,
            'styleTypeBOSMethod' => $method,
        ]);

        return $id;
    }

    private function table(string $styleCsv): void
    {
        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Scores Fixture Table',
            'tableNumber' => random_int(50, 99),
            'tableLocation' => 1,
            'tableStyles' => $styleCsv,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'Scores Fixture Entry',
            'brewCategorySort' => '1',
            'brewCategory' => '1',
            'brewSubCategory' => 'D',
            'brewBrewerID' => (string) self::ENTRANT_ID,
            'brewConfirmed' => '1',
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewJudgingNumber' => sprintf('%06d', random_int(700000, 799999)),
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entryIds[] = $id;

        return $id;
    }

    /**
     * @return list<object>
     */
    private function invokeEligible(int $styleTypeId): array
    {
        $method = new \ReflectionMethod(BosController::class, 'eligible');

        return $method->invoke(null, $styleTypeId);
    }
}
