<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Output\BottleLabelController;
use App\Http\Controllers\Output\LabelsController;
use App\Http\Controllers\Output\SortingController;
use App\Http\Controllers\Output\TableCardsController;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Slice D output pair A (spec §7 P5.2, ticket 02): labels, bottle_label,
 * table_cards, sorting. Admin gate + PDF magic bytes on seeded fixtures
 * (prefix P52a), mirroring legacy quirks per controller docblocks.
 *
 * Legacy surfaces:
 *   - output/labels.output.php (go=participants&action=address_labels)
 *   - output/bottle_label.output.php
 *   - output/table_cards.output.php (placards / tables / tent cards)
 *   - output/sorting.output.php (+ go=cheat)
 */
final class OutputPairsATest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p52a.admin@brewingcompetitions.com';

    private const ADMIN_ID = 52001;

    private const ENTRANT_ID = 52002;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const NON_ADMIN_ID = 52003;

    private const PREFIX = 'P52a';

    private int $tableId = 0;

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $brewerIds = [];

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
    }

    protected function tearDown(): void
    {
        DB::table('judging_assignments')->whereIn('bid', [self::ENTRANT_ID])->delete();
        if ($this->tableId !== 0) {
            DB::table('judging_tables')->delete($this->tableId);
        }
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        foreach ($this->brewerIds as $uid) {
            DB::table('brewer')->where('uid', $uid)->delete();
        }
        DB::table('users')->delete(self::ADMIN_ID);

        parent::tearDown();
    }

    private function login(): void
    {
        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    /**
     * Seed one brewer with two received+confirmed entries; returns ids.
     *
     * @return list<int>
     */
    private function seedEntries(): array
    {
        DB::table('brewer')->insert([
            'uid' => self::ENTRANT_ID,
            'brewerFirstName' => self::PREFIX.'First',
            'brewerLastName' => self::PREFIX.'Last',
            'brewerAddress' => '101 Test Lane',
            'brewerCity' => 'Anytown',
            'brewerState' => 'TX',
            'brewerZip' => '78701',
            'brewerCountry' => 'United States',
            'brewerEmail' => self::PREFIX.'.entrant@brewingcompetitions.com',
            'brewerPhone1' => '5125551234',
            'brewerJudgeRank' => 'Novice, Certified',
        ]);
        $this->brewerIds[] = self::ENTRANT_ID;

        $ids = [];
        foreach ([['28', 'A'], ['28', 'B']] as $i => [$cat, $sub]) {
            $ids[] = DB::table('brewing')->insertGetId([
                'brewName' => self::PREFIX.' Entry '.($i + 1),
                'brewStyle' => 'Common Cider',
                'brewCategory' => ltrim($cat, '0'),
                'brewCategorySort' => $cat,
                'brewSubCategory' => $sub,
                'brewBrewerID' => self::ENTRANT_ID,
                'brewPaid' => '1',
                'brewReceived' => '1',
                'brewConfirmed' => '1',
                'brewJudgingNumber' => '41000'.$i,
            ]);
        }
        $this->entryIds = [...$this->entryIds, ...$ids];

        return $ids;
    }

    private function seedTable(string $stylesCsv): int
    {
        $locId = (int) DB::table('judging_locations')->min('id');

        return $this->tableId = DB::table('judging_tables')->insertGetId([
            'tableName' => self::PREFIX.' Table',
            'tableStyles' => $stylesCsv,
            'tableNumber' => '52',
            'tableLocation' => $locId,
        ]);
    }

    public function test_non_admin_is_redirected_on_all_four_outputs(): void
    {
        // Legacy's userLevel > 1 gate — a logged-in non-staff user, not
        // anonymous traffic (auth middleware handles that).
        DB::table('users')->where('id', self::NON_ADMIN_ID)->delete();
        DB::table('users')->insert([
            'id' => self::NON_ADMIN_ID,
            'user_name' => 'p52a.member@brewingcompetitions.com',
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->loginWithEmail('p52a.member@brewingcompetitions.com');

        try {
            foreach (['labels', 'bottle_label', 'table_cards', 'sorting'] as $output) {
                $this->get('/admin/output/'.$output)->assertRedirect('/?msg=99');
            }
        } finally {
            DB::table('users')->delete(self::NON_ADMIN_ID);
        }
    }

    public function test_labels_address_sheets_stream_pdf(): void
    {
        $this->seedEntries();
        $this->login();

        $response = $this->get('/admin/output/labels');
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('_Avery5160.pdf', (string) $response->headers->get('Content-Disposition'));

        // with_entries payload quirk: summary label precedes the address
        // label; received entries only; %06d judging-number list by default.
        $labels = LabelsController::build(true, 1);
        $this->assertCount(2, $labels);
        $this->assertSame('Entry Summary for '.self::PREFIX.'First '.self::PREFIX.'Last', $labels[0][0]);
        $this->assertSame('2 Entries', $labels[0][1]);

        // Address label: US country line suppressed.
        $this->assertSame(self::PREFIX.'First '.self::PREFIX.'Last', $labels[1][0]);
        // Default view lists %06d judging numbers (user_entry_count :202).
        $this->assertStringContainsString('410000, 410001', $labels[0][2]);

        // view=entry lists %06d entry numbers ordered by id instead.
        $byEntry = LabelsController::build(true, 1, 'entry');
        $this->assertStringContainsString(sprintf('%06d', $this->entryIds[0]), $byEntry[0][2]);
    }

    public function test_bottle_label_streams_pdf_for_selected_entries(): void
    {
        $ids = $this->seedEntries();
        $this->login();

        $response = $this->get('/admin/output/bottle_label?ids='.implode(',', $ids));
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // Baseline prefsEntryForm=5 → standard label w/ barcode+QR, entry
        // number display; jPrefsBottleNum=3 copies per entry → 9 labels/page.
        $data = BottleLabelController::build(TenantContext::load(), implode(',', $ids));
        $cells = $data['view']['cells'];
        $this->assertCount(6, $cells); // 2 entries × 3 copies

        foreach ($cells as $cell) {
            $this->assertTrue($cell['standard']);
            $this->assertFalse($cell['anon']);
            $this->assertTrue($cell['barcodeQr']);
            $this->assertSame('(512) 555-1234', $cell['contact'][2]);
            // QR cell embeds a real scannable QR SVG (legacy qRCreate), not
            // the old "[QR]" text placeholder.
            $this->assertStringStartsWith('<?xml', $cell['qrSvg']);
            $this->assertStringContainsString('<svg', $cell['qrSvg']);
            $this->assertStringContainsString('<path', $cell['qrSvg']);
        }
        $this->assertSame(sprintf('%06d', $ids[0]), $cells[0]['code']);
        $this->assertSame(sprintf('%06d', $ids[1]), $cells[3]['code']);

        // Single-entry mode resolves the brewer from the entry row.
        $single = BottleLabelController::build(TenantContext::load(), '', (string) $ids[1]);
        $this->assertCount(3, $single['view']['cells']);
    }

    public function test_table_cards_modes_stream_pdf(): void
    {
        $this->seedEntries();
        $styleIds = DB::table('styles')->where('brewStyleGroup', '28')
            ->whereIn('brewStyleNum', ['A', 'B'])->pluck('id')->implode(',');
        $this->seedTable($styleIds);
        $this->login();

        foreach (['sorting-placards', 'sorting-tables', 'default'] as $psort) {
            $query = $psort === 'sorting-tables' ? '?psort='.$psort.'&view=master-list' : '?psort='.$psort;
            $response = $this->get('/admin/output/table_cards'.$query);
            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        }

        // Placard payload quirk: confirmed entries grouped by category,
        // zero-padded numeric category number, singular/plural count line.
        $ctx = TenantContext::load();
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get();

        $placard28 = null;
        foreach (TableCardsController::placards() as $p) {
            if ($p['number'] === '28') {
                $placard28 = $p;
            }
        }
        $this->assertNotNull($placard28);
        $this->assertSame('2 Entries', $placard28['count']);
        $this->assertCount(2, $placard28['items']);

        // Master-list quirk: received-only entry count over the table's
        // style CSV (jPrefsTablePlanning != 1 adds brewReceived='1').
        $row52 = null;
        foreach (TableCardsController::tableRows($ctx, $tables) as $r) {
            if ((string) $r['number'] === '52') {
                $row52 = $r;
            }
        }
        $this->assertNotNull($row52);
        $this->assertSame(2, $row52['received']);
    }

    public function test_table_cards_without_any_defined_table_renders_notice_pdf(): void
    {
        $this->login();

        // No fixtures this run: assert via an impossible id filter instead
        // of wiping shared corpus data.
        $response = $this->get('/admin/output/table_cards?id=99999999');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $empty = TableCardsController::tableRows(TenantContext::load(), collect());
        $this->assertSame([], $empty);
    }

    public function test_sorting_sheets_and_cheat_stream_pdf(): void
    {
        $this->seedEntries();
        $this->login();

        foreach (['', '?go=cheat', '?view=entry'] as $query) {
            $response = $this->get('/admin/output/sorting'.$query);
            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        }

        // Category payload quirks: NO received/paid filter on sorting
        // sheets; readable judging number splits 4-char as "0-000";
        // contact cell carries email + US-formatted phone.
        DB::table('brewing')->where('id', $this->entryIds[1])->update(['brewPaid' => '0', 'brewReceived' => '0']);

        $cat28 = null;
        foreach (SortingController::build() as $c) {
            if (str_starts_with($c['title'], 'Category 28:')) {
                $cat28 = $c;
            }
        }
        $this->assertNotNull($cat28, 'category 28 sheet missing');
        $this->assertCount(2, $cat28['rows'], 'unreceived entry must still appear');
        // 6-char judging numbers pass through %06s unchanged
        // (readable_judging_number only splits 5- and 4-char values).
        $this->assertSame('410000', $cat28['rows'][0]['readableJudgingNo']);
        $contact = explode("\n", $cat28['rows'][0]['contact']);
        $this->assertSame(self::PREFIX.'.entrant@brewingcompetitions.com', $contact[0]);
        $this->assertSame('(512) 555-1234', $contact[1]);
    }
}
