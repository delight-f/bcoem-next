<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use App\Support\Judging\FlightAssignment;
use PHPUnit\Framework\TestCase;

/**
 * Unit pins for the flight/table assignment engine (P4.2), covering the
 * edge cases named in ledger/flight-assignment.md: planning mode (#2),
 * gap preservation in table numbering (#3), NULLs-last manual ordering
 * semantics (#4, SQL executed end-to-end in FlightAssignmentDbTest),
 * and the judging_flights row shape (#5/#8).
 */
final class FlightAssignmentTest extends TestCase
{
    /** @return array<string, int|string|null> */
    private static function entry(?string $received): array
    {
        return ['id' => random_int(1, PHP_INT_MAX), 'brewReceived' => $received];
    }

    public function test_received_only_count_when_not_planning(): void
    {
        $entries = [
            self::entry('1'),
            self::entry('0'),
            self::entry('1'),
            self::entry(null),
        ];

        // 2 received of 4 present: ceil(2/12) = 1.
        self::assertSame(1, FlightAssignment::proposeFlights($entries, 12));
    }

    public function test_planning_mode_counts_everything_into_flight_one(): void
    {
        // #2: planning mode ignores brewReceived entirely — nothing has been
        // received yet, but every entry is proposed into flight 1.
        $entries = [
            self::entry('0'),
            self::entry('0'),
            self::entry(null),
        ];

        self::assertSame(1, FlightAssignment::proposeFlights($entries, 12, true));
        self::assertSame(0, FlightAssignment::proposeFlights($entries, 12));
    }

    public function test_no_entries_no_flights_even_in_planning_mode(): void
    {
        self::assertSame(0, FlightAssignment::proposeFlights([], 12));
        self::assertSame(0, FlightAssignment::proposeFlights([], 12, true));
    }

    public function test_sizing_has_no_clamp(): void
    {
        // #1: no min/max clamp — a huge pref yields 1 flight, a tiny pref many.
        self::assertSame(1, FlightAssignment::flightCount(5000, 5000));
        self::assertSame(50, FlightAssignment::flightCount(500, 10));
        self::assertSame(1, FlightAssignment::flightCount(1, 12));
    }

    public function test_propose_counts_keys_by_table(): void
    {
        $counts = FlightAssignment::proposeCounts(
            [
                3 => [self::entry('1'), self::entry('1'), self::entry('0')],
                7 => [self::entry('1')],
                9 => [],
            ],
            12,
        );

        self::assertSame([3 => 1, 7 => 1, 9 => 0], $counts);
    }

    public function test_empty_table_set_starts_numbering_at_one(): void
    {
        self::assertSame(1, FlightAssignment::nextTableNumber([]));
    }

    public function test_gaps_are_never_compacted(): void
    {
        // #3: deleted tables leave holes; next number rides on max+1 only.
        self::assertSame(6, FlightAssignment::nextTableNumber([1, 2, 5]));
        self::assertSame(42, FlightAssignment::nextTableNumber([1, 40, 41]));
        self::assertSame(2, FlightAssignment::nextTableNumber([1]));
    }

    public function test_reorder_order_by_is_nulls_last_verbatim(): void
    {
        // #4: `IS NULL ASC` puts saved manual order FIRST and NULLs LAST,
        // then sort/sub/judging-number ascending. Must match the legacy SQL
        // byte-for-byte (FlightAssignmentDbTest executes it against MySQL).
        self::assertSame(
            'f.flightEntryOrder IS NULL ASC, f.flightEntryOrder ASC, '
            .'b.brewCategorySort, b.brewSubCategory, b.brewJudgingNumber ASC',
            FlightAssignment::reorderOrderBy(),
        );
        self::assertSame(
            'jf.flightEntryOrder IS NULL ASC, jf.flightEntryOrder ASC, '
            .'br.brewCategorySort, br.brewSubCategory, br.brewJudgingNumber ASC',
            FlightAssignment::reorderOrderBy('jf', 'br'),
        );
    }

    public function test_flight_row_shape(): void
    {
        // #5: table id, per-table number, entry id, round; order nullable.
        self::assertSame(
            ['flightTable' => 9, 'flightNumber' => 1, 'flightEntryID' => 55, 'flightRound' => 1, 'flightEntryOrder' => null],
            FlightAssignment::flightRow(9, 1, 55),
        );
        // #8: BOS rounds reuse the same shape with round > 1 and manual order.
        self::assertSame(
            ['flightTable' => 9, 'flightNumber' => 2, 'flightEntryID' => 56, 'flightRound' => 2, 'flightEntryOrder' => 4],
            FlightAssignment::flightRow(9, 2, 56, 2, 4),
        );
    }

    public function test_planning_row_is_flight_one_with_null_order(): void
    {
        // #2/#5: planning-mode rows land in flight 1, manual order NULL.
        self::assertSame(
            ['flightTable' => 4, 'flightNumber' => 1, 'flightEntryID' => 77, 'flightRound' => 1, 'flightEntryOrder' => null],
            FlightAssignment::planningRow(4, 77),
        );
    }
}
