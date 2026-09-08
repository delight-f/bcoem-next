<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\LabelsController;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bottle-label matrix (output/labels.output.php go=entries): the six-pair
 * default view, the required-info view=all/special branch, the 5167
 * quick-sort labels and the round/category-round labels.
 *
 * Seeds a brewer with a special-ingredient entry (21B, brewStyleReqSpec=1),
 * a mead entry (M1A) and a plain lager (02B) so each branch is exercised
 * against the baseline styles table.
 */
final class OutputLabelsBottleTest extends TestCase
{
    private const ADMIN_EMAIL = 'p52l.admin@brewingcompetitions.com';

    private const ADMIN_ID = 52501;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const NON_ADMIN_ID = 52502;
    private const BREWER_ID = 52503;

    private const PREFIX = 'P52l';

    /** @var array<string, int> */
    private array $entryIds = [];

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
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', array_values($this->entryIds) ?: [0])->delete();
        DB::table('brewer')->where('uid', self::BREWER_ID)->delete();
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

    /**
     * Seed the three entries: special-ingredient Style S + allergens, a mead
     * entry, and a plain lager. Returns entry ids keyed by label.
     *
     * @return array<string, int>
     */
    private function seedEntries(): array
    {
        DB::table('brewer')->where('uid', self::BREWER_ID)->delete();
        $brewerId = DB::table('brewer')->insertGetId([
            'uid' => self::BREWER_ID,
            'brewerFirstName' => self::PREFIX.'First',
            'brewerLastName' => self::PREFIX.'Last',
            'brewerAddress' => '101 Test Lane',
            'brewerCity' => 'Anytown',
            'brewerState' => 'TX',
            'brewerZip' => '78701',
            'brewerCountry' => 'United States',
            'brewerEmail' => self::PREFIX.'.brewer@brewingcompetitions.com',
            'brewerPhone1' => '5125551234',
        ]);

        $entry = fn (string $cat, string $sub, string $style, array $extra): int => (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => self::PREFIX.' Entry '.$sub,
            'brewStyle' => $style,
            'brewCategory' => ltrim($cat, '0'),
            'brewCategorySort' => $cat,
            'brewSubCategory' => $sub,
            'brewBrewerID' => $brewerId,
            'brewBrewerFirstName' => self::PREFIX.'First',
            'brewBrewerLastName' => self::PREFIX.'Last',
            'brewPaid' => '1',
            'brewReceived' => '1',
            'brewConfirmed' => '1',
            'brewUpdated' => '2026-08-14 05:34:20',
        ], $extra));

        $this->entryIds = [];
        $this->entryIds['special'] = $entry('21', 'B', 'Specialty IPA', [
            'brewPossAllergens' => 'Wheat, Barley',
            'brewInfo' => 'Special 21C Hazy^Standard Strength',
            'brewJudgingNumber' => '52501',
        ]);
        $this->entryIds['mead'] = $entry('M1', 'A', 'Dry Mead', [
            'brewMead1' => 'Sparkling',
            'brewMead2' => 'Medium Sweet',
            'brewMead3' => 'Hydromel',
            'brewJudgingNumber' => '52502',
        ]);
        $this->entryIds['plain'] = $entry('02', 'B', 'International Amber Lager', [
            'brewJudgingNumber' => '52503',
        ]);

        return $this->entryIds;
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

        $this->get('/admin/output/labels?action=bottle-entry&filter=default&psort=5160')
            ->assertRedirect('/?msg=99');
    }

    public function test_bottle_entry_default_view_streams_pdf_with_six_pairs(): void
    {
        $ids = $this->seedEntries();
        $this->login();

        $response = $this->get('/admin/output/labels?action=bottle-entry&filter=default&psort=5160');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('_Bottle_Labels_Entry_Numbers_Avery5160.pdf', (string) $response->headers->get('Content-Disposition'));

        $data = LabelsController::bottleDefaults($this->ctx(), 'bottle-entry', 'default', 'default', '5160');
        $flat = collect($data['view']['labels'])->flatten()->implode("\n");
        $this->assertStringContainsString(sprintf('%06s (21B)', $ids['special']), $flat);
        $this->assertStringContainsString('(02B)', $flat);
    }

    public function test_bottle_judging_default_view_uses_judging_number_but_entry_filename(): void
    {
        $this->seedEntries();
        $this->login();

        $this->get('/admin/output/labels?action=bottle-judging&filter=default&psort=5160')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Legacy quirk: the judging-number sheet still carries the
        // "_Bottle_Labels_Entry_Numbers" filename.
        $data = LabelsController::bottleDefaults($this->ctx(), 'bottle-judging', 'default', 'default', '5160');
        $this->assertStringContainsString('_Bottle_Labels_Entry_Numbers', $data['filename']);
        $flat = collect($data['view']['labels'])->flatten()->implode("\n");
        $this->assertStringContainsString(sprintf('%06s', 52501), $flat);
    }

    public function test_required_info_view_special_emits_allergen_and_markers(): void
    {
        $this->seedEntries();
        $this->login();

        $this->get('/admin/output/labels?action=bottle-entry&filter=default&view=special&psort=5160&sort=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $data = LabelsController::bottleRequiredInfo($this->ctx(), 'bottle-entry', 'special', 'default', 'default', 'default', 1, '5160');
        $this->assertStringContainsString('_Bottle_Labels_Entry_Numbers_Req_Info_Avery5160.pdf', $data['filename']);

        $flat = collect($data['view']['labels'])->map(fn ($lines) => implode("\n", $lines))->implode("\n");
        $this->assertStringContainsString('Allergens: Wheat, Barley', $flat);
        $this->assertStringContainsString('*Standard*', $flat);
        $this->assertStringContainsString('*Spark*', $flat);
        $this->assertStringContainsString('*Med Sweet*', $flat);
        $this->assertStringContainsString('*Hydro*', $flat);
    }

    public function test_quicksort_streams_pdf_and_emits_bos_bottle(): void
    {
        $this->seedEntries();
        $this->login();

        $this->get('/admin/output/labels?action=bottle-judging&filter=default&view=quicksort&psort=5167')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $data = LabelsController::bottleQuicksort($this->ctx(), 'default');
        $this->assertStringContainsString('_QuickSort_Labels_Judging_Numbers.pdf', $data['filename']);
        $flat = collect($data['view']['cells'])->map(fn ($c) => implode('|', $c['lines']))->implode("\n");
        $this->assertStringContainsString('#3/BOS', $flat);

        // 5-digit judging numbers become split+hyphenated (readable_judging_number).
        $this->assertStringContainsString('52-501', $flat);
    }

    public function test_round_labels_stream_pdf_with_parenthesized_category(): void
    {
        $this->seedEntries();
        $this->login();

        $this->get('/admin/output/labels?action=bottle-entry-round&filter=default&psort=OL32&sort=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $data = LabelsController::bottleRound($this->ctx(), 'bottle-entry-round', 'default', 1, 'OL32');
        $this->assertStringContainsString('_Round_Bottle_Labels_Entry_Numbers_.50_Inch.pdf', $data['filename']);
        $flat = collect($data['view']['cells'])->map(fn ($lines) => implode('|', $lines))->implode("\n");
        $this->assertStringContainsString('(21B)', $flat);
        // Round labels use brewCategory (already ltrim'd of leading zeros in
        // the seed), so category "02" shows as "(2B)" — a legacy quirk.
        $this->assertStringContainsString('(2B)', $flat);

        // filter=recent only emits entries updated after the reg deadline.
        $recent = LabelsController::bottleRound($this->ctx(), 'bottle-entry-round', 'recent', 1, 'OL5275WR');
        $this->assertStringContainsString('_Added_After_Reg_Close', $recent['filename']);
    }

    public function test_category_round_streams_pdf(): void
    {
        $this->seedEntries();
        $this->login();

        $this->get('/admin/output/labels?action=bottle-category-round&filter=default&psort=OL32&sort=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $data = LabelsController::bottleCategoryRound($this->ctx(), 'default', 1, 'OL32');
        $this->assertStringContainsString('_Round_Bottle_Labels_Category_Only_.50_Inch.pdf', $data['filename']);
        $flat = collect($data['view']['cells'])->map(fn ($lines) => implode('|', $lines))->implode("\n");
        $this->assertStringContainsString('21B', $flat);
    }
}
