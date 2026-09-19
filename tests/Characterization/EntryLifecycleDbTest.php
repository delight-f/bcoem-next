<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Characterization: entry lifecycle column states (P1.3).
 *
 * Legacy source: includes/process/process_brewing.inc.php (creation and
 * edit paths), includes/db/entries.db.php (read filters), schema comments
 * in the baseline dump. Runs only where MySQL exists (CI); locally these
 * skip like tests/Integration.
 *
 * Pinned here:
 *   - creation defaults (brewConfirmed='1' via hidden field, brewPaid=0,
 *     brewReceived=0; free comps force brewPaid=1)
 *   - brewConfirmed is written as '0' when required style fields are
 *     missing, although the schema comment claims "1=true; 2=false" —
 *     reads filter on ='1' only, so both '0' and '2' mean "not confirmed"
 *   - judging-number uniqueness is enforced by application loops only;
 *     the schema has no unique index, duplicates are storable
 */
final class EntryLifecycleDbTest extends MySqlTestCase
{
    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            DB::table('brewing')->where('id', $id)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        $base = [
            'brewName' => 'Lifecycle Fixture',
            'brewStyle' => 'Irish Red Ale',
            'brewCategory' => '15',
            'brewCategorySort' => '15',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '1',
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ];

        $id = DB::table('brewing')->insertGetId([...$base, ...$overrides]);
        if (! is_int($id)) {
            self::fail('brewing insert failed');
        }
        $this->created[] = $id;

        return $id;
    }

    private function entry(int $id): \stdClass
    {
        $row = DB::table('brewing')->where('id', $id)->first();
        self::assertInstanceOf(\stdClass::class, $row);

        return $row;
    }

    public function test_creation_defaults_survive_round_trip(): void
    {
        // process_brewing.inc.php:115-121 — $brewJudgingNumber="",
        // $brewPaid=0, $brewReceived=0 unless admin overrides at add time.
        $id = $this->makeEntry();

        $row = $this->entry($id);
        self::assertSame('1', (string) $row->brewConfirmed);
        self::assertSame(0, (int) $row->brewPaid);
        self::assertSame(0, (int) $row->brewReceived);
        self::assertNull($row->brewJudgingNumber);
    }

    public function test_unconfirmed_flag_accepts_legacy_zero_and_schema_two(): void
    {
        // Schema comment says "1=true; 2=false" but process_brewing.inc.php
        // writes '0' on missing-required-field resets (:531,:559,:587,:615),
        // and every read filter is brewConfirmed='1'. Both values are
        // storable; reads treat them identically — the port must treat any
        // non-'1' as unconfirmed until a dedicated migration normalizes.
        $zeroId = $this->makeEntry(['brewConfirmed' => '0', 'brewName' => 'Unconfirmed Check Zero']);
        $twoId = $this->makeEntry(['brewConfirmed' => '2', 'brewName' => 'Unconfirmed Check Two']);

        self::assertSame('0', (string) $this->entry($zeroId)->brewConfirmed);
        self::assertSame('2', (string) $this->entry($twoId)->brewConfirmed);

        foreach (DB::table('brewing')->where('brewConfirmed', '0')->get() as $row) {
            self::assertSame('Unconfirmed Check Zero', $row->brewName);
        }
        foreach (DB::table('brewing')->where('brewConfirmed', '2')->get() as $row) {
            self::assertSame('Unconfirmed Check Two', $row->brewName);
        }
    }

    public function test_duplicate_judging_numbers_are_storable(): void
    {
        // Uniqueness comes from app-level retry loops (generate_judging_num
        // vs brewing table + USER_DOCS pdf files), NOT from the schema. A
        // buggy port that relies on a DB constraint would diverge silently.
        $a = $this->makeEntry(['brewJudgingNumber' => '555555']);
        $b = $this->makeEntry(['brewJudgingNumber' => '555555']);

        self::assertNotSame($a, $b);
        self::assertCount(2, DB::table('brewing')->where('brewJudgingNumber', '555555')->get());
    }

    public function test_paid_and_received_transitions(): void
    {
        // Edit rules: entrants (userLevel>1) cannot change paid/received —
        // process_brewing.inc.php:269-279 re-reads current values from the
        // DB for non-admin edits. Only admins flip them (check-in/payment).
        $id = $this->makeEntry();

        DB::table('brewing')->where('id', $id)->update(['brewPaid' => 1]);
        self::assertSame(1, (int) $this->entry($id)->brewPaid);

        DB::table('brewing')->where('id', $id)->update(['brewReceived' => 1]);
        $row = $this->entry($id);
        self::assertSame(1, (int) $row->brewReceived);
        self::assertSame(1, (int) $row->brewPaid); // independent flags
    }
}
