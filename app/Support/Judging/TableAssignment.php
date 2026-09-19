<?php

declare(strict_types=1);

namespace App\Support\Judging;

use Illuminate\Support\Facades\DB;

/**
 * Judge/steward → judging_assignments writes, shared by the per-table
 * assignment matrix (AssignController) and the pool screen's inline
 * assignment control (PoolAssignController).
 *
 * Storage parity is preserved: the row shape and the entry-conflict guard are
 * identical to the legacy matrix screen, so both call sites produce the same
 * judging_assignments rows (bid, assignment J/S, assignTable, assignFlight,
 * assignRound, assignLocation = the table's session location, assignPlanning,
 * assignRoles = null).
 */
final class TableAssignment
{
    /** error_type returned when the participant has an entry at the table. */
    public const CONFLICT = 4;

    /** Role code stored in judging_assignments.assignment. */
    public static function code(string $role): string
    {
        return $role === 'stewards' ? 'S' : 'J';
    }

    /**
     * Legacy entry_conflict(): does the participant have an entry (received
     * unless planning mode) whose category/subcategory matches one of the
     * table's styles?
     *
     * @param  list<int>  $styleIds
     */
    public static function entryConflict(int $bid, array $styleIds, bool $planning): bool
    {
        $styles = DB::table('styles')->whereIn('id', $styleIds ?: [0])
            ->get(['brewStyleGroup', 'brewStyleNum']);

        foreach ($styles as $style) {
            $q = DB::table('brewing')
                ->where('brewBrewerID', $bid)
                ->where('brewCategorySort', $style->brewStyleGroup)
                ->where('brewSubCategory', $style->brewStyleNum);
            if (! $planning) {
                $q->where('brewReceived', '1');
            }
            if ($q->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Round for a table/flight pair: MAX(flightRound), matching the round the
     * matrix header shows for that flight. Null when the flight is unknown.
     */
    public static function roundForFlight(int $tableId, int $flightNumber): ?int
    {
        $round = DB::table('judging_flights')
            ->where('flightTable', $tableId)
            ->where('flightNumber', $flightNumber)
            ->max('flightRound');

        return $round === null ? null : (int) $round;
    }

    /**
     * Replace a participant's row for (table, round): delete the existing rows
     * then insert one when a flight is chosen. Mirrors the matrix screen's
     * per-round step; flight 0 clears the round without inserting.
     */
    public static function write(int $bid, string $code, int $tableId, int $flightNumber, int $round, int $locationId, int $planning): void
    {
        DB::table('judging_assignments')
            ->where('bid', $bid)
            ->where('assignment', $code)
            ->where('assignTable', $tableId)
            ->where('assignRound', $round)
            ->delete();

        if ($flightNumber > 0) {
            DB::table('judging_assignments')->insert([
                'bid' => $bid,
                'assignment' => $code,
                'assignTable' => $tableId,
                'assignFlight' => $flightNumber,
                'assignRound' => $round,
                'assignLocation' => $locationId,
                'assignPlanning' => $planning,
                'assignRoles' => null,
            ]);
        }
    }

    /**
     * Inline path: table + flight are known, round/location are resolved from
     * the table. Refuses a participant with an entry at that table.
     *
     * @return array{0: int, 1: int} [status, error_type]
     */
    public static function assign(int $bid, string $role, int $tableId, int $flightNumber, bool $planning): array
    {
        $table = DB::table('judging_tables')->where('id', $tableId)->first();
        $round = self::roundForFlight($tableId, $flightNumber);
        if ($table === null || $round === null) {
            return [0, 3];
        }

        $styleIds = array_values(array_filter(array_map(intval(...), explode(',', (string) $table->tableStyles))));
        if (self::entryConflict($bid, $styleIds, $planning)) {
            return [0, self::CONFLICT];
        }

        self::write(
            $bid,
            self::code($role),
            $tableId,
            $flightNumber,
            $round,
            (int) $table->tableLocation,
            $planning ? 1 : 0,
        );

        return [1, 0];
    }

    /**
     * Clear a participant's table rows for their role (optionally one table).
     */
    public static function remove(int $bid, string $role, ?int $tableId = null): void
    {
        DB::table('judging_assignments')
            ->where('bid', $bid)
            ->where('assignment', self::code($role))
            ->when($tableId !== null, fn ($q) => $q->where('assignTable', $tableId))
            ->delete();
    }
}
