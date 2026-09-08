<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Tenant\DateFmt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * "All Entries: All Data" CSV export byte-parity contract (spec §7 P5.3,
 * ticket 03). Pins, against the legacy oracle's observable behavior:
 *   - header row column order verbatim (participant block, SELECT * brewing
 *     columns through the legacy ltrim("brew") label chain — brewBrewerID →
 *     "ID", JuiceSource/Pouring each splitting into two columns — then the
 *     eight judging labels);
 *   - UTF-8 BOM, LF line endings (legacy uses PHP's native fputcsv; the
 *     CRLF-looking polyfill in export.output.php is dead code), '"' quoting
 *     with doubled quotes and '\' escape;
 *   - identical row ordering (no ORDER BY → storage order);
 *   - variable-length rows: unjudged entries omit all eight trailing
 *     judging fields; score rendered via legacy's sprintf("%02s") ("5" →
 *     "05" — the 0 flag zero-pads strings on PHP 8);
 */
final class ExportCsvTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p53.admin@brewingcompetitions.com';

    private const ADMIN_ID = 935301;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const ENTRY_IDS = [530001, 530002, 530003];

    private const BREWER_IDS = [530011, 530012];

    private const LOCATION_ID = 530021;

    private const TABLE_ID = 530031;

    /**
     * Canonical header, in legacy emission order (export.output.php:264-311).
     *
     * @var list<string>
     */
    private const HEADER = [
        // Participant block (prefsProEdition == 0).
        'First Name', 'Last Name',
        'Email Address', 'Address', 'City', 'State/Province', 'Zip/Postal Code', 'Country', 'Club',
        // Brewing table columns through ltrim("brew") + the label chain.
        'Entry Number', 'Entry Name', 'Style', 'Category', 'Category Sort', 'Sub Category',
        'Bottle Date', 'Date', 'Yield', 'Required Info', 'Carbonation', 'Sweetness', 'Strength',
        "Brewer's Specifics", 'Brewer ID', 'Brewer First Name', 'Brewer Last Name', 'Paid', 'ABV', 'Winner Cat',
        'Optional Info', 'Admin Notes', 'Staff Notes', 'Poss Allergens', 'Received', 'Final Gravity',
        'Co Brewer', 'Judging Number', 'Updated', 'Confirmed', 'Box Num',
        'Juice Source', 'Juice Source Other', 'Pouring Inst', 'Rouse Yeast', 'Style Type', 'Packaging',
        // Judging tail (only emitted when flight AND score rows exist).
        'Table', 'Flight', 'Round', 'Score', 'Place', 'Best of Show Place', 'Style Type', 'Location',
    ];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();

        // Pin the shared preferences row: sibling suites run against the same
        // DB and may flip prefsProEdition etc. mid-run; the header layout
        // depends on it. Restore on teardown.
        $this->origPrefs = (array) DB::table('preferences')->where('id', 1)->first();
        DB::table('preferences')->where('id', 1)->update(['prefsProEdition' => 0]);

        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        DB::table('brewer')->insert([
            'id' => self::BREWER_IDS[0],
            'uid' => '530111',
            'brewerFirstName' => 'Ada',
            'brewerLastName' => "O'Neil",
            'brewerEmail' => 'p53.brewer1@example.com',
            'brewerAddress' => '1 Main St',
            'brewerCity' => 'P53ville',
            'brewerState' => 'CA',
            'brewerZip' => '90001',
            'brewerCountry' => 'United States',
            // comma + spaces → exercises fputcsv quoting + doubling
            'brewerClubs' => 'P53 Club, North',
            'brewerJudgeRank' => 'Non-BJCP',
            'brewerJudgeMead' => 'N',
        ]);

        DB::table('brewer')->insert([
            'id' => self::BREWER_IDS[1],
            'uid' => '530112',
            'brewerFirstName' => 'Sam',
            'brewerLastName' => 'Smith',
            'brewerEmail' => 'p53.brewer2@example.com',
            'brewerClubs' => null,
        ]);

        DB::table('judging_locations')->insert([
            'id' => self::LOCATION_ID,
            'judgingLocName' => 'P53 Locale',
            'judgingLocType' => 0,
        ]);

        DB::table('judging_tables')->insert([
            'id' => self::TABLE_ID,
            'tableName' => 'P53 Table',
            'tableNumber' => 3,
            'tableLocation' => self::LOCATION_ID,
        ]);

        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        $this->cleanFixtures();
        parent::tearDown();
    }

    private function cleanFixtures(): void
    {
        DB::table('judging_scores_bos')->whereIn('eid', self::ENTRY_IDS)->delete();
        DB::table('judging_scores')->whereIn('eid', self::ENTRY_IDS)->delete();
        DB::table('judging_flights')->whereIn('flightTable', [self::TABLE_ID])->delete();
        DB::table('judging_tables')->where('id', self::TABLE_ID)->delete();
        DB::table('judging_locations')->where('id', self::LOCATION_ID)->delete();
        DB::table('brewing')->whereIn('id', self::ENTRY_IDS)->delete();
        DB::table('brewer')->whereIn('id', self::BREWER_IDS)->delete();
        DB::table('users')->whereIn('user_name', [self::ADMIN_EMAIL, 'p53.user@brewingcompetitions.com'])->delete();
    }

    public function test_admin_gets_csv_with_legacy_header_order_and_bytes(): void
    {
        DB::table('brewing')->insert($this->entryRow(530001, [
            'brewStyleType' => 3, // style_types id 3 → "Mead"
            'brewInfo' => 'needs head, please',
            'brewInfoOptional' => 'oak aged',
            'brewABV' => 6.5,
            'brewJudgingNumber' => 'C101',
            'brewBoxNum' => 'B12',
            'brewPackaging' => 'keg',
            'brewJuiceSource' => '{"juice_src":["Apple","Pear"],"juice_src_other":["Kiwi"]}',
            'brewPouring' => '{"pouring":"2 oz","pouring_rouse":"swirl"}',
        ]));

        DB::table('brewing')->insert($this->entryRow(530002, [
            'brewBrewerID' => '530112',
            'brewBrewerFirstName' => 'Sam',
            'brewBrewerLastName' => 'Smith',
            'brewName' => 'P53 Lager',
            'brewStyle' => 'Munich Helles',
            'brewCategory' => '4',
            'brewCategorySort' => '4',
            'brewSubCategory' => 'B',
            'brewDate' => '2024-01-06',
            'brewStyleType' => 1, // → "Beer"
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewConfirmed' => 0,
        ]));

        // Entry pointing at a nonexistent brewer: participant fields collapse
        // to empty strings (legacy's suppressed-null concatenation).
        DB::table('brewing')->insert($this->entryRow(530003, [
            'brewBrewerID' => '99999999',
            'brewBrewerFirstName' => 'Ghost',
            'brewBrewerLastName' => 'Brewer',
            'brewName' => 'P53 Ghost Cider',
            'brewStyle' => 'Common Cider',
            'brewCategory' => '28',
            'brewCategorySort' => '28',
            'brewSubCategory' => 'A',
            'brewDate' => '2024-01-07',
            'brewStyleType' => 2, // → "Cider"
        ]));

        // Only entry 530001 is judged + assigned; 530002/530003 must end their
        // rows after "Packaging" (legacy quirk: variable-length rows).
        DB::table('judging_flights')->insert([
            'flightTable' => self::TABLE_ID,
            'flightNumber' => 4,
            'flightEntryID' => '530001',
            'flightRound' => 1,
        ]);
        DB::table('judging_scores')->insert([
            'eid' => 530001,
            'bid' => self::BREWER_IDS[0],
            'scoreTable' => self::TABLE_ID,
            'scoreEntry' => 5, // single digit → sprintf("%02s") renders "05"
            'scorePlace' => 2,
            'scoreType' => 3,
        ]);
        DB::table('judging_scores_bos')->insert(['eid' => 530001, 'scorePlace' => '1']);

        $response = $this->get('/admin/output/export?go=csv&action=all&tb=all');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;filename="', $disposition);
        // tb=all contributes its own "_All" filename segment, like legacy.
        $this->assertMatchesRegularExpression(
            '/_Entries(_All)*_\d{4}-\d{2}-\d{2}\.csv"$/',
            $disposition,
        );

        // StreamedResponse carries its payload in a callback, not ->getContent().
        $body = $response->streamedContent();

        // UTF-8 BOM first, exactly like legacy's fprintf().
        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        // Header row verbatim, LF terminated.
        $this->assertSame("\xEF\xBB\xBF".self::csvLine(self::HEADER)."\n", substr($body, 0, strpos($body, "\n") + 1));

        // Judged entry: full 54-field row.
        $rowA = self::row([
            'First Name' => 'Ada',
            'Last Name' => "O'Neil",
            'Email Address' => 'p53.brewer1@example.com',
            'Address' => '1 Main St',
            'City' => 'P53ville',
            'State/Province' => 'CA',
            'Zip/Postal Code' => '90001',
            'Country' => 'United States',
            'Club' => 'P53 Club, North',
            'Entry Number' => '530001',
            'Entry Name' => 'P53 Pale Ale',
            'Style' => 'American Pale Ale',
            'Category' => '10',
            'Category Sort' => '10',
            'Sub Category' => 'A',
            'Date' => '2024-01-05',
            'Yield' => '5 gal',
            'Required Info' => 'needs head, please',
            'Brewer ID' => '530111',
            'Brewer First Name' => 'Ada',
            'Brewer Last Name' => "O'Neil",
            'Paid' => '1',
            'ABV' => '6.5',
            'Optional Info' => 'oak aged',
            'Received' => '1',
            'Judging Number' => 'C101',
            'Updated' => '2024-02-02 03:04:05',
            'Confirmed' => '1',
            'Box Num' => 'B12',
            'Juice Source' => 'Apple, Pear',
            'Juice Source Other' => 'Kiwi',
            'Pouring Inst' => '2 oz',
            'Rouse Yeast' => 'swirl',
            'Style Type' => 'Mead',
            'Packaging' => 'keg',
            'Table' => '03: P53 Table',
            'Flight' => '4',
            'Round' => '1',
            'Score' => '05',
            'Place' => '2',
            'Best of Show Place' => '1',
            'Location' => 'P53 Locale',
        ]);

        // Unjudged entries: rows end right after Packaging.
        $rowB = self::row([
            'First Name' => 'Sam',
            'Last Name' => 'Smith',
            'Email Address' => 'p53.brewer2@example.com',
            'Entry Number' => '530002',
            'Entry Name' => 'P53 Lager',
            'Style' => 'Munich Helles',
            'Category' => '4',
            'Category Sort' => '4',
            'Sub Category' => 'B',
            'Date' => '2024-01-06',
            'Yield' => '5 gal',
            'Brewer ID' => '530112',
            'Brewer First Name' => 'Sam',
            'Brewer Last Name' => 'Smith',
            'Paid' => '0',
            'Received' => '0',
            'Updated' => '2024-02-02 03:04:05',
            'Confirmed' => '0',
            'Style Type' => 'Beer',
        ], judged: false);

        // First/Last Name still come from the brewing row's denormalized
        // copies; only the brewer-table lookups collapse to "".
        $rowC = self::row([
            'First Name' => 'Ghost',
            'Last Name' => 'Brewer',
            'Entry Number' => '530003',
            'Entry Name' => 'P53 Ghost Cider',
            'Style' => 'Common Cider',
            'Category' => '28',
            'Category Sort' => '28',
            'Sub Category' => 'A',
            'Date' => '2024-01-07',
            'Yield' => '5 gal',
            'Brewer ID' => '99999999',
            'Brewer First Name' => 'Ghost',
            'Brewer Last Name' => 'Brewer',
            'Paid' => '1',
            'Received' => '1',
            'Updated' => '2024-02-02 03:04:05',
            'Confirmed' => '1',
            'Style Type' => 'Cider',
        ], judged: false);

        // Rows appear verbatim, once each, in the same order an unordered
        // SELECT * yields (legacy adds no ORDER BY either — byte parity holds
        // because the harness points both apps at ONE physical copy).
        $expectedRows = ['A' => $rowA, 'B' => $rowB, 'C' => $rowC];
        foreach ($expectedRows as $label => $row) {
            if (substr_count($body, "\n".$row."\n") !== 1) {
                throw new ExpectationFailedException("fixture row $label not found exactly once verbatim:\n$body");
            }
        }

        // Their RELATIVE order must follow a plain SELECT * (the controller's
        // exact query shape — a WHERE id IN range scan walks the PRIMARY index
        // and yields id order instead). Foreign records inserted by sibling
        // suites sharing this DB are tolerated around ours; the harness's cmp
        // leg covers absolute ordering on isolated dumps.
        $scanOrder = DB::table('brewing')->get()
            ->filter(fn (object $r): bool => in_array((int) $r->id, self::ENTRY_IDS, true))
            ->map(fn (object $r): string => match ((int) $r->id) {
                530001 => $rowA,
                530002 => $rowB,
                default => $rowC,
            })
            ->all();

        $positions = array_map(fn (string $row): int => (int) strpos($body, "\n".$row."\n"), $scanOrder);
        $this->assertSame($positions, array_slice($positions, 0), '');
        $ordered = $positions;
        sort($ordered);
        $this->assertSame($ordered, $positions, 'CSV rows do not follow storage order');

        // LF-only endings across our span (native fputcsv behavior).
        if ($positions === []) {
            self::fail('No CSV rows found in response body');
        }
        $span = substr($body, min($positions), max($positions) - min($positions));
        $this->assertStringNotContainsString("\r", $span);
        unset($positions, $ordered, $span);
        // Download filename carries today's date in the tenant timezone.
        $today = DateFmt::date(
            time(),
            (string) DB::table('preferences')->value('prefsTimeZone'),
            '1',
            'system',
        ) ?? '';
        $this->assertStringEndsWith('_'.$today.'.csv"', $disposition);
    }

    public function test_non_admin_is_redirected(): void
    {
        DB::table('users')->insert([
            'id' => self::ADMIN_ID + 1,
            'user_name' => 'p53.user@brewingcompetitions.com',
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->post('/login', [
            'loginUsername' => 'p53.user@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $this->get('/admin/output/export?go=csv&action=all&tb=all')
            ->assertRedirect('/?msg=99');
    }

    /**
     * Baseline brewing row; per-test values override the defaults.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entryRow(int $id, array $overrides): array
    {
        return array_merge([
            'id' => $id,
            'brewName' => 'P53 Pale Ale',
            'brewStyle' => 'American Pale Ale',
            'brewCategory' => '10',
            'brewCategorySort' => '10',
            'brewSubCategory' => 'A',
            'brewDate' => '2024-01-05',
            'brewYield' => '5 gal',
            'brewBrewerID' => '530111',
            'brewBrewerFirstName' => 'Ada',
            'brewBrewerLastName' => "O'Neil",
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewJudgingNumber' => null,
            'brewUpdated' => '2024-02-02 03:04:05',
            'brewConfirmed' => 1,
            'brewStyleType' => 3,
        ], $overrides);
    }

    /**
     * Build one expected CSV record by zipping values onto the canonical
     * header — impossible to drift out of alignment. Labels are unique:
     * the participant "First Name"/"Last Name" vs the brewing columns'
     * "Brewer First Name"/"Brewer Last Name".
     *
     * Unjudged rows ($judged = false) end at "Packaging": legacy emits no
     * trailing judging fields when flight or score rows are missing.
     *
     * @param  array<string, string>  $values
     */
    private static function row(array $values, bool $judged = true): string
    {
        $fields = [];
        foreach (self::HEADER as $label) {
            if (! $judged && $label === 'Table') {
                break;
            }

            $fields[] = $values[$label] ?? '';
        }

        return self::csvLine($fields);
    }

    /**
     * Serialize one CSV record exactly as both apps do (native fputcsv).
     *
     * @param  list<int|string>  $fields
     */
    private static function csvLine(array $fields): string
    {
        $fp = fopen('php://temp', 'r+');
        self::assertNotFalse($fp);
        fputcsv($fp, $fields, ',', '"', '\\');
        rewind($fp);
        $line = (string) stream_get_contents($fp);
        fclose($fp);

        return rtrim($line, "\n");
    }
}
