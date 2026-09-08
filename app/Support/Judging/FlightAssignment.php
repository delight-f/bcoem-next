<?php

declare(strict_types=1);

namespace App\Support\Judging;

/**
 * Flight/table assignment engine (P4.2). Spec: ledger/flight-assignment.md.
 *
 * Ledger mapping (1:1):
 *   #1 flights per table = ceil(entries / jPrefsFlightEntries); NO min/max
 *      clamp exists anywhere in legacy — none added here.
 *   #2 entry count = brewReceived='1' only, unless table-planning mode
 *      (jPrefsTablePlanning) — then ALL entries count, and every row is
 *      written into flight 1 with a NULL manual order (#5).
 *   #3 new tableNumber = max(existing)+1; deleted tables leave gaps that are
 *      never compacted or reused.
 *   #4 reorder view ORDER BY, verbatim semantics: manual flightEntryOrder
 *      ascending with NULLs LAST ("saved manual order first"), then
 *      brewCategorySort / brewSubCategory / brewJudgingNumber ascending.
 *   #5 judging_flights row shape: flightTable = table id, flightNumber per
 *      table, flightEntryID, flightRound; flightEntryOrder nullable.
 *   #6 tables are organizer-defined style groupings (tableStyles CSV of
 *      style ids) — matching entries to tables happens via that CSV at the
 *      call site; no auto-clustering here. Legacy "regenerate" regenerates
 *      JUDGING NUMBERS, not flights — do not name anything "regenerate".
 *   #7 flights are assigned manually per entry in the UI; this engine only
 *      proposes COUNTS and row shapes, it never picks an entry's flight.
 *   #8 BOS rounds reuse the same structures with flightRound > 1 (consumed
 *      by the scoring slice via round-aware row shape).
 *
 * Deliberately DB-free like EntryLimits: entries/tables come in as plain
 * arrays so the math and row shapes stay unit-testable.
 */
final class FlightAssignment
{
    /**
     * #1 Ceiling sizing, no clamp.
     */
    public static function flightCount(int $entryCount, int $perFlight): int
    {
        return (int) ceil($entryCount / $perFlight);
    }

    /**
     * #1/#2 Proposed flight count for one table's entry list.
     *
     * @param  iterable<array<string, mixed>|object>  $entries  brewing rows belonging to one table (arrays or stdClass row objects)
     * @param  int  $perFlight  jPrefsFlightEntries
     * @param  bool  $planningMode  jPrefsTablePlanning — count ALL entries, not just received ones
     */
    public static function proposeFlights(iterable $entries, int $perFlight, bool $planningMode = false): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            $received = is_object($entry)
                ? (string) ($entry->brewReceived ?? '0')
                : (string) ($entry['brewReceived'] ?? '0');
            if ($planningMode || $received === '1') {
                $count++;
            }
        }

        if ($count === 0) {
            return 0;
        }

        return self::flightCount($count, $perFlight);
    }

    /**
     * #1/#2 Proposed flight count keyed by table id (contract API consumed
     * by the judging admin screens).
     *
     * @param  array<int, iterable<array<string, mixed>|object>>  $entriesByTable  table id => its brewing rows
     * @param  int  $perFlight  jPrefsFlightEntries
     * @param  bool  $planningMode  jPrefsTablePlanning
     * @return array<int, int> table id => proposed flight count
     */
    public static function proposeCounts(array $entriesByTable, int $perFlight, bool $planningMode = false): array
    {
        $counts = [];
        foreach ($entriesByTable as $tableId => $entries) {
            $counts[$tableId] = self::proposeFlights($entries, $perFlight, $planningMode);
        }

        return $counts;
    }

    /**
     * #3 Next table number: max+1 over existing numbers; gaps from deleted
     * tables are never compacted. Empty set starts numbering at 1.
     *
     * @param  list<int>  $existingTableNumbers
     */
    public static function nextTableNumber(array $existingTableNumbers): int
    {
        if ($existingTableNumbers === []) {
            return 1;
        }

        return max($existingTableNumbers) + 1;
    }

    /**
     * #4 Reorder-view ordering, verbatim ORDER BY semantics:
     * `flightEntryOrder IS NULL ASC` puts saved manual order first and NULLs
     * last (intentional legacy behavior), then category sort / subcategory /
     * judging number ascending as tie-breakers.
     *
     * @param  non-empty-string  $flightsAlias  alias of judging_flights in the caller's query
     * @param  non-empty-string  $brewingAlias  alias of brewing in the caller's query
     */
    public static function reorderOrderBy(string $flightsAlias = 'f', string $brewingAlias = 'b'): string
    {
        return sprintf(
            '%1$s.flightEntryOrder IS NULL ASC, %1$s.flightEntryOrder ASC, %2$s.brewCategorySort, %2$s.brewSubCategory, %2$s.brewJudgingNumber ASC',
            $flightsAlias,
            $brewingAlias,
        );
    }

    /**
     * #5/#8 Schema-exact payload for a judging_flights insert. Manual order
     * defaults to NULL (un-ordered); rounds > 1 reuse the same shape for BOS.
     *
     * @return array{flightTable: int, flightNumber: int, flightEntryID: int, flightRound: int, flightEntryOrder: int|null}
     */
    public static function flightRow(int $tableId, int $flightNumber, int $entryId, int $round = 1, ?int $entryOrder = null): array
    {
        return [
            'flightTable' => $tableId,
            'flightNumber' => $flightNumber,
            'flightEntryID' => $entryId,
            'flightRound' => $round,
            'flightEntryOrder' => $entryOrder,
        ];
    }

    /**
     * #2/#5 Planning-mode row: everything lands in flight 1, order NULL.
     *
     * @return array{flightTable: int, flightNumber: int, flightEntryID: int, flightRound: int, flightEntryOrder: null}
     */
    public static function planningRow(int $tableId, int $entryId, int $round = 1): array
    {
        /** @var array{flightTable: int, flightNumber: int, flightEntryID: int, flightRound: int, flightEntryOrder: null} */
        return self::flightRow($tableId, 1, $entryId, $round, null);
    }
}
