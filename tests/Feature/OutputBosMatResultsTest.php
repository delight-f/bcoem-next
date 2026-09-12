<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * BOS cup mats and results PDF families (spec §7 P5.1): every legacy
 * ?action=/?go= URL shape that the parity linkmap records must return a
 * valid application/pdf with the verbatim filename, and the rendered
 * mat/table content must carry the group/heading copy the legacy
 * algorithm emits. Seeded against the same anon-base-shaped fixture the
 * pull-sheet variants use.
 */
final class OutputBosMatResultsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'bmr.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9701;

    private const ADMIN_PASS = 'bcoem';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $scoreIds = [];

    /** @var list<int> */
    private array $bosScoreIds = [];

    private int $tableId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->whereIn('id', [self::ADMIN_ID, 9702])->delete();
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
        DB::table('judging_scores_bos')->whereIn('id', $this->bosScoreIds ?: [0])->delete();
        DB::table('judging_scores')->whereIn('id', $this->scoreIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('judging_tables')->where('id', $this->tableId)->delete();
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, 9702])->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/logout');
        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => self::ADMIN_PASS,
        ]);
    }

    /**
     * One active Beer style, one received entry placed 1st (scoreType 1),
     * and one BOS row for the same entry. Returns the entry id.
     */
    private function seedBosMat(): int
    {
        $styleId = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'BMR Irish Red Ale',
            'brewStyleGroup' => '17',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'bcoe',
            'brewStyleReqSpec' => 1,
        ]);
        $this->styleIds[] = $styleId;

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'BMR Main Table',
            'tableNumber' => 9701,
            'tableLocation' => 1,
        ]);

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'BMR Entry One',
            'brewStyle' => 'BMR Irish Red Ale',
            'brewCategory' => '17',
            'brewCategorySort' => '17',
            'brewSubCategory' => 'A',
            'brewJudgingNumber' => '970101',
            'brewInfo' => '',
            'brewInfoOptional' => '',
            'brewComments' => '',
            'brewMead1' => '',
            'brewMead2' => '',
            'brewMead3' => '',
            'brewPossAllergens' => '',
            'brewBrewerFirstName' => 'Ada',
            'brewBrewerLastName' => 'Lovelace',
            'brewBrewerID' => 1,
            'brewPaid' => 0,
            'brewReceived' => '1',
            'brewConfirmed' => '1',
        ]);
        $this->entryIds[] = $entryId;

        $this->scoreIds[] = (int) DB::table('judging_scores')->insertGetId([
            'eid' => $entryId,
            'scoreTable' => $this->tableId,
            'scoreType' => 1,
            'scorePlace' => 1,
            'scoreEntry' => 38,
            'scoreMiniBOS' => 0,
        ]);

        $this->bosScoreIds[] = (int) DB::table('judging_scores_bos')->insertGetId([
            'eid' => $entryId,
            'scorePlace' => 1,
        ]);

        return $entryId;
    }

    public function test_bos_mat_shapes_return_pdf_with_filename(): void
    {
        $this->seedBosMat();
        $this->login();

        $urls = [
            '/admin/output/bos_mat',
            '/admin/output/bos_mat?action=blank',
            '/admin/output/bos_mat?action=blank&view=mini-bos',
            '/admin/output/bos_mat?action=blank&view=pro-am',
            '/admin/output/bos_mat?action=mini-bos',
            '/admin/output/bos_mat?action=mini-bos&filter=entry&view=1',
            '/admin/output/bos_mat?action=pro-am&sort=1&view=1',
            '/admin/output/bos_mat?filter=entry&view=1',
            '/admin/output/bos_mat?view=1',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $response->assertHeader('Content-Disposition', 'inline; filename="bos_mat.pdf"');
            $this->assertSame('%PDF', substr((string) $response->getContent(), 0, 4), $url);
        }
    }

    public function test_bos_mat_default_renders_group_and_mat_copy(): void
    {
        $this->seedBosMat();
        $this->login();

        $response = $this->get('/admin/output/bos_mat');
        $response->assertOk();
        $html = (string) $response->getContent() === '' ? '' : $this->decodePdfText($response);

        $this->assertStringContainsString('Best of Show: Beer', $html);
        $this->assertStringContainsString('BMR Irish Red Ale', $html);
        $this->assertStringContainsString('Table 9701: BMR Main Table', $html);
        $this->assertStringContainsString('970101', $html);
    }

    public function test_bos_mat_blank_uses_view_heading(): void
    {
        $this->seedBosMat();
        $this->login();

        $html = $this->decodePdfText($this->get('/admin/output/bos_mat?action=blank&view=mini-bos'));
        $this->assertStringContainsString('Mini-BOS', $html);
        $this->assertStringNotContainsString('Best of Show', $html);
    }

    /**
     * Issue 32: a group with no qualifying entries must not print a page of
     * blank squares — no tiles at all, just the "no entries" notice.
     */
    public function test_bos_mat_skips_groups_without_entries(): void
    {
        $this->seedBosMat();
        $this->login();

        // Style type 2 (Cider, BOS=Y) has no scored entries.
        $empty = $this->decodePdfText($this->get('/admin/output/bos_mat?view=2'));
        $this->assertStringContainsString('No best of show entries are present.', $empty);
        $this->assertStringNotContainsString('BMR Irish Red Ale', $empty);
        $this->assertStringNotContainsString('Table 9701', $empty);
    }

    /**
     * Issue 32: a group longer than one 2×3 page continues onto further
     * pages instead of being truncated to the first six entries.
     */
    public function test_bos_mat_paginates_groups_over_six_entries(): void
    {
        $this->seedBosMat();
        $this->seedExtraBeerEntries(7);
        $this->login();

        $html = $this->decodePdfText($this->get('/admin/output/bos_mat?filter=entry'));

        $this->assertStringContainsString('BMR Irish Red Ale', $html);
        foreach (range(2, 8) as $n) {
            $this->assertStringContainsString('BMR Ale '.$n, $html, 'entry '.$n.' must not be dropped');
        }
    }

    /** Seven more Beer entries on the seed table, same style, all placed 1st. */
    private function seedExtraBeerEntries(int $count): void
    {
        for ($n = 2; $n <= $count + 1; $n++) {
            $id = (int) DB::table('brewing')->insertGetId([
                'brewName' => 'BMR Entry '.$n,
                'brewStyle' => 'BMR Ale '.$n,
                'brewCategory' => '17',
                'brewCategorySort' => '17',
                'brewSubCategory' => 'A',
                'brewJudgingNumber' => '9701'.$n.'0',
                'brewBrewerID' => 1,
                'brewReceived' => '1',
                'brewConfirmed' => '1',
            ]);
            $this->entryIds[] = $id;
            $this->scoreIds[] = (int) DB::table('judging_scores')->insertGetId([
                'eid' => $id,
                'scoreTable' => $this->tableId,
                'scoreType' => 1,
                'scorePlace' => 1,
                'scoreEntry' => 38,
                'scoreMiniBOS' => 0,
            ]);
        }
    }

    public function test_results_shapes_return_pdf_with_filename(): void
    {
        $this->seedBosMat();
        $this->login();

        $urls = [
            '/admin/output/results?go=all&action=print&tb=scores&view=default',
            '/admin/output/results?go=all&action=print&view=winners&psort=table-entry-count-asc',
            '/admin/output/results?go=best&action=print&filter=default&view=default',
            '/admin/output/results?go=judging_scores&action=print&filter=none&view=winners',
            '/admin/output/results?go=judging_scores&action=print&tb=scores&view=default',
            '/admin/output/results?go=judging_scores_bos&action=print&tb=bos&view=default',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $response->assertHeader('Content-Disposition', 'inline; filename="results.pdf"');
            $this->assertSame('%PDF', substr((string) $response->getContent(), 0, 4), $url);
        }
    }

    public function test_results_go_all_lead_and_bos_copy(): void
    {
        $this->seedBosMat();
        $this->login();

        $html = $this->decodePdfText($this->get('/admin/output/results?go=all&action=print&tb=scores&view=default'));
        $this->assertStringContainsString('1 entries judged and', $html);

        // BOS table only when a judging_scores_bos row exists.
        $this->assertStringContainsString('BMR Entry One', $html);
        $this->assertStringContainsString('BMR Irish Red Ale', $html);
    }

    public function test_bos_mat_and_results_require_admin(): void
    {
        $this->seedBosMat();
        // Anonymous bounces to /login.
        $this->get('/admin/output/bos_mat')->assertRedirect('/login');
        $this->get('/admin/output/results')->assertRedirect('/login');
        // Non-admin (userLevel 2) bounces to /?msg=99.
        DB::table('users')->insert([
            'id' => 9702,
            'user_name' => 'bmr.user@brewingcompetitions.com',
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->post('/login', ['loginUsername' => 'bmr.user@brewingcompetitions.com', 'loginPassword' => 'bcoem']);
        $this->get('/admin/output/bos_mat')->assertRedirect('/?msg=99');
        $this->get('/admin/output/results')->assertRedirect('/?msg=99');
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function decodePdfText(TestResponse $response): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, (string) $response->getContent());
        $text = shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null') ?: '';

        @unlink($tmp);

        return $text;
    }
}
