<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use App\Support\Judging\FlightAssignment;
use BCOEM\Tests\Integration\MySqlTestCase;

/**
 * Characterization: flight reorder ordering + assignment row shape (P1.5),
 * executed against the baseline schema in CI.
 *
 * The ORDER BY is supplied verbatim by
 * App\Support\Judging\FlightAssignment::reorderOrderBy(), matching legacy
 * includes/db/admin_judging_flights.db.php (reorder view):
 *
 *   ORDER BY f.flightEntryOrder IS NULL ASC,   -- manual order first,
 *            f.flightEntryOrder ASC,           -- NULLs last
 *            b.brewCategorySort, b.brewSubCategory, b.brewJudgingNumber ASC
 *
 * and the judging_flights row shape written by the assign flow is
 * (flightTable, flightNumber, flightEntryID, flightRound[, flightEntryOrder]).
 */
final class FlightAssignmentDbTest extends MySqlTestCase
{
    /**
     * rawQuery auto-prefixes only the FIRST table token (vendored
     * MysqliDb::rawAddPrefix uses $table[0], a scalar). For multi-table or
     * DDL statements we clear the prefix and write full names explicitly.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private static function unprefixedQuery(string $sql, array $params = []): array
    {
        $db = self::db();
        $db->setPrefix('');
        try {
            $rows = $db->rawQuery($sql, $params);
            self::assertIsArray($rows);

            return array_values(array_map(fn ($row): array => (array) $row, $rows));
        } finally {
            $db->setPrefix('baseline_');
        }
    }

    /** @var list<int> */
    private array $entries = [];

    /** @var list<int> */
    private array $flights = [];

    protected function tearDown(): void
    {
        foreach ($this->flights as $id) {
            self::db()->where('id', $id)->delete('judging_flights');
        }
        foreach ($this->entries as $id) {
            self::db()->where('id', $id)->delete('brewing');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        $base = [
            'brewName' => 'Flight Fixture',
            'brewCategorySort' => '15',
            'brewCategory' => '15',
            'brewSubCategory' => 'A',
            'brewJudgingNumber' => '100001',
            'brewBrewerID' => '9003',
            'brewConfirmed' => '1',
            'brewReceived' => 1,
            'brewPaid' => 0,
        ];
        self::db()->insert('brewing', [...$base, ...$overrides]);
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->entries[] = $id;

        return $id;
    }

    private function makeFlight(int $tableId, int $number, int $entryId): int
    {
        self::db()->insert('judging_flights', FlightAssignment::flightRow($tableId, $number, $entryId));
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->flights[] = $id;

        return $id;
    }

    public function test_reorder_puts_manual_order_first_nulls_last(): void
    {
        // Manual order saved for e1/e2; e3 has NULL order -> sorts last even
        // though its category is lower; ties inside each group fall back to
        // sort/sub/judging-number ascending.
        $e1 = $this->makeEntry(['brewCategorySort' => '15', 'brewSubCategory' => 'A', 'brewJudgingNumber' => '300002']);
        $e2 = $this->makeEntry(['brewCategorySort' => '15', 'brewSubCategory' => 'A', 'brewJudgingNumber' => '300001']);
        $e3 = $this->makeEntry(['brewCategorySort' => '02', 'brewSubCategory' => 'B', 'brewJudgingNumber' => '100003']);

        $this->makeFlight(7, 1, $e1);
        $this->makeFlight(7, 1, $e2);
        $this->makeFlight(7, 1, $e3);

        self::db()->where('flightEntryID', $e1)->update('judging_flights', ['flightEntryOrder' => 2]);
        self::db()->where('flightEntryID', $e2)->update('judging_flights', ['flightEntryOrder' => 1]);

        $rows = self::unprefixedQuery(
            'SELECT b.id FROM baseline_judging_flights f JOIN baseline_brewing b '
            .'ON f.flightEntryID = b.id WHERE f.flightTable = ? AND f.flightNumber = ? '
            .'ORDER BY '.FlightAssignment::reorderOrderBy(),
            [7, 1],
        );

        self::assertSame([$e2, $e1, $e3], array_map(intval(...), array_column($rows, 'id')),
            'manual order wins; NULL order sorts after ALL ordered rows');
    }

    public function test_flight_rows_reference_table_and_round(): void
    {
        // Assign-flow row shape: table id, flight number within table,
        // entry id, round. Planning mode writes everything into flight 1.
        $e = $this->makeEntry();
        $fid = $this->makeFlight(9, 1, $e);

        $row = self::db()->where('id', $fid)->getOne('judging_flights');
        self::assertIsArray($row);
        self::assertSame(9, (int) $row['flightTable']);
        self::assertSame(1, (int) $row['flightNumber']);
        self::assertSame($e, (int) $row['flightEntryID']);
        self::assertSame(1, (int) $row['flightRound']);
        self::assertNull($row['flightEntryOrder']);
    }
}
