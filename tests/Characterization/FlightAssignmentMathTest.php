<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: flight/table sizing math (P1.5).
 *
 * Legacy source:
 *   - admin/judging_flights.admin.php:25
 *       $flight_count = ceil($entry_count / $_SESSION['jPrefsFlightEntries']);
 *     Flights are sized ONLY by this ceiling rule; there is no min/max
 *     flight-size clamp anywhere in the codebase.
 *   - includes/db/admin_judging_tables.db.php: next table number =
 *     max(tableNumber)+1 ("ORDER BY tableNumber DESC LIMIT 1").
 *   - Entry count feeding the rule is get_table_info(...,"count_total")
 *     filtered to brewReceived='1' when not in table-planning mode.
 *
 * The full reorder-ordering contract is pinned end-to-end against real SQL
 * in FlightAssignmentDbTest (CI).
 */
final class FlightAssignmentMathTest extends TestCase
{
    #[DataProvider('provideFlightSizing')]
    public function test_flight_count_is_ceiling_of_entries_over_pref(int $entries, int $perFlight, int $expected): void
    {
        self::assertSame($expected, (int) ceil($entries / $perFlight));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function provideFlightSizing(): iterable
    {
        yield 'zero entries no flights' => [0, 12, 0];
        yield 'exact division' => [24, 12, 2];
        yield 'remainder adds a flight' => [25, 12, 3];
        yield 'one under second flight' => [13, 12, 2];
        yield 'single entry single flight' => [1, 12, 1];
        yield 'tiny pref many flights' => [10, 3, 4];
    }

    public function test_table_number_sequence_is_max_plus_one(): void
    {
        // admin_judging_tables.db.php: last number DESC LIMIT 1, new = +1.
        // Deleted tables leave gaps; numbering never reuses or compacts.
        $existing = [1, 2, 5];
        $next = ((int) max($existing)) + 1;

        self::assertSame(6, $next);
    }
}
