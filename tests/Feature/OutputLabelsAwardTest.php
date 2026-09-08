<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\LabelsController;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Award / medal / winner-address labels (output/labels.output.php
 * go=judging_scores&action=awards).
 *
 * Seeds a category's placed scores + a best-of-show row so the
 * prefsWinnerMethod=1 (by category) branch emits winner labels, and asserts
 * the empty-sheet contract when no winners exist.
 */
final class OutputLabelsAwardTest extends TestCase
{
    private const ADMIN_EMAIL = 'p52w.admin@brewingcompetitions.com';

    private const ADMIN_ID = 52521;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const NON_ADMIN_ID = 52522;

    private const PREFIX = 'P52w';

    private const BREWER_UID = 90021;

    /** baseline_styles.id referenced by prefsSelectedStyles (21B, BJCP2021). */
    private const STYLE_ID = 517;

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $scoreIds = [];

    /** @var list<int> */
    private array $bosIds = [];

    private string $origWinnerMethod = '0';

    private string $origSelectedStyles = '';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('BCOEM_TEST_DB_HOST') ?: '127.0.0.1';
        $name = getenv('BCOEM_TEST_DB_NAME') ?: 'bcoem_test';
        try {
            new \PDO("mysql:host={$host};dbname={$name}", getenv('BCOEM_TEST_DB_USER') ?: 'root', getenv('BCOEM_TEST_DB_PASS') ?: 'root');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: '.$e->getMessage());
        }

        config()->set('database.connections.mysql', array_merge(config('database.connections.mysql'), [
            'host' => $host,
            'port' => getenv('BCOEM_TEST_DB_PORT') ?: '3306',
            'database' => $name,
            'username' => getenv('BCOEM_TEST_DB_USER') ?: 'root',
            'password' => getenv('BCOEM_TEST_DB_PASS') ?: 'root',
            'prefix' => 'baseline_',
        ]));
        config()->set('database.default', 'mysql');
        DB::purge('mysql');

        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->origWinnerMethod = (string) (DB::table('preferences')->where('id', 1)->value('prefsWinnerMethod') ?? '0');
        $this->origSelectedStyles = (string) (DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles') ?? '');
    }

    protected function tearDown(): void
    {
        DB::table('judging_scores_bos')->whereIn('id', $this->bosIds ?: [0])->delete();
        DB::table('judging_scores')->whereIn('id', $this->scoreIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('brewer')->where('uid', self::BREWER_UID)->delete();
        DB::table('preferences')->where('id', 1)->update([
            'prefsWinnerMethod' => $this->origWinnerMethod === '' ? null : $this->origWinnerMethod,
            'prefsSelectedStyles' => $this->origSelectedStyles === '' ? null : $this->origSelectedStyles,
        ]);
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::NON_ADMIN_ID])->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    private function ctx(): TenantContext
    {
        return TenantContext::load();
    }

    /** Point winner-method at "by category" and select the 21B style. */
    private function setWinnerPrefs(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsWinnerMethod' => 1,
            'prefsSelectedStyles' => '{"'.self::STYLE_ID.'":{"brewStyle":"Specialty IPA","brewStyleGroup":"21","brewStyleNum":"B","brewStyleVersion":"BJCP2021"}}',
        ]);
    }

    /**
     * Seed a brewer, two received 21B entries with placed scores and a BOS
     * winner; returns [entry1, entry2].
     *
     * @return array<int, int>
     */
    private function seedWinners(): array
    {
        $this->setWinnerPrefs();

        DB::table('brewer')->where('uid', self::BREWER_UID)->delete();
        DB::table('brewer')->insert([
            'uid' => self::BREWER_UID,
            'brewerFirstName' => self::PREFIX.'First',
            'brewerLastName' => self::PREFIX.'Last',
            'brewerEmail' => self::PREFIX.'.brewer@brewingcompetitions.com',
            'brewerClubs' => 'Test Brewers',
            'brewerCountry' => 'United States',
        ]);

        $ids = [];
        foreach ([['1', self::PREFIX.' Winner'], ['2', self::PREFIX.' Runner']] as [$place, $name]) {
            $ids[] = (int) DB::table('brewing')->insertGetId([
                'brewName' => $name,
                'brewStyle' => 'Specialty IPA',
                'brewCategory' => '21',
                'brewCategorySort' => '21',
                'brewSubCategory' => 'B',
                'brewBrewerID' => self::BREWER_UID,
                'brewBrewerFirstName' => self::PREFIX.'First',
                'brewBrewerLastName' => self::PREFIX.'Last',
                'brewReceived' => '1',
                'brewPaid' => '1',
                'brewConfirmed' => '1',
            ]);
        }
        $this->entryIds = [...$this->entryIds, ...$ids];

        $scoreEntry = fn (int $eid, string $place): int => (int) DB::table('judging_scores')->insertGetId([
            'eid' => $eid,
            'bid' => self::BREWER_UID,
            'scorePlace' => $place,
            'scoreType' => 1,
        ]);
        $this->scoreIds[] = $scoreEntry($ids[0], '1');
        $this->scoreIds[] = $scoreEntry($ids[1], '2');

        $this->bosIds[] = (int) DB::table('judging_scores_bos')->insertGetId([
            'eid' => $ids[0],
            'bid' => self::BREWER_UID,
            'scoreEntry' => $ids[0],
            'scorePlace' => '1',
            'scoreType' => 1,
        ]);

        return $ids;
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

        $this->post('/login', [
            'loginUsername' => self::PREFIX.'.member@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=5160')
            ->assertRedirect('/?msg=99');
    }

    public function test_award_labels_by_category_emit_display_place_and_best_of_show(): void
    {
        $ids = $this->seedWinners();
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=5160');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('_Award_Labels_Avery5160.pdf', (string) $response->headers->get('Content-Disposition'));

        $data = LabelsController::awardLabels($this->ctx(), 'default', '5160');
        /** @var list<list<string>> $labels */
        $labels = $data['view']['labels'];
        $flat = collect($labels)->map(fn ($l) => implode("\n", $l))->implode("\n");

        // Best-of-show line: display_place(1) . " - Best of Show (Beer)".
        $this->assertStringContainsString('1st - Best of Show (Beer)', $flat);
        // Category winner/runner lines: 1st/2nd + category + name + entry + style.
        $this->assertStringContainsString('1st', $flat);
        $this->assertStringContainsString('2nd', $flat);
        $this->assertStringContainsString('IPA', $flat);
        $this->assertStringContainsString(self::PREFIX.'First '.self::PREFIX.'Last', $flat);
        $this->assertStringContainsString("'".self::PREFIX.' Winner'."'", $flat);
        $this->assertStringContainsString('Specialty IPA', $flat);
    }

    public function test_award_labels_with_no_winners_stream_empty_valid_sheet(): void
    {
        // No scores seeded (and winner-method left default), so the winner
        // loops produce no labels — the faithful-empty contract.
        $this->setWinnerPrefs();
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=3422');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('_Award_Labels_Avery3422.pdf', (string) $response->headers->get('Content-Disposition'));

        $data = LabelsController::awardLabels($this->ctx(), 'default', '3422');
        $this->assertSame([], $data['view']['labels']);
    }

    public function test_medal_round_labels_stream_pdf_with_verbatim_filename(): void
    {
        $this->setWinnerPrefs();
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_scores&action=awards&filter=round&psort=5293');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('_Medal_Labels_5293.pdf', (string) $response->headers->get('Content-Disposition'));

        $data = LabelsController::awardLabels($this->ctx(), 'round', '5293');
        $this->assertStringContainsString('_Medal_Labels_5293.pdf', $data['filename']);
        // Medal-round grid dispatches to the round view (grid metrics).
        $this->assertSame('outputs.labels_round', $data['view_name']);
    }

    public function test_winner_address_labels_dedupe_winners_by_brewer(): void
    {
        $this->seedWinners();
        $this->login();

        $response = $this->get('/admin/output/labels?go=judging_scores&action=awards&filter=address&psort=5160');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('_Winner_Address_Labels_Avery5160.pdf', (string) $response->headers->get('Content-Disposition'));

        // Both winners are the same brewer -> exactly one address label.
        $data = LabelsController::awardLabels($this->ctx(), 'address', '5160');
        $this->assertCount(1, $data['view']['labels']);
        $this->assertSame(self::PREFIX.'First '.self::PREFIX.'Last', $data['view']['labels'][0][0]);
    }
}
