<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Judging\FlightAssignment;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Flight definition screen (spec §6 P4.3). Legacy:
 * admin/judging_flights.admin.php (filter=define) +
 * includes/process/process_judging_flights.inc.php (add/edit actions).
 *
 * Ledger/flight-assignment.md #7: flights are assigned MANUALLY via a radio
 * per entry — this screen proposes only the COUNT
 * (FlightAssignment::proposeFlights, #1/#2) and never picks an entry's
 * flight. The grid lists the table's received entries (all entries in
 * table-planning mode) ordered by the engine's verbatim ORDER BY (#4), and
 * the POST writes schema-exact judging_flights rows via
 * FlightAssignment::flightRow() (#5): update flightNumber for entries that
 * already have a round-1 row, insert otherwise.
 *
 * Legacy's "assign flights to rounds" sub-screen is out of scope here; the
 * round column stays at 1 and BOS rounds reuse these structures with
 * flightRound > 1 (ledger #8).
 */
final class FlightController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get();
        $counts = [];
        foreach ($tables as $table) {
            $entries = self::tableEntries((int) $table->id, (string) $table->tableStyles, self::planningMode($ctx));
            $perFlight = max(1, (int) ($ctx->judgingStr('jPrefsFlightEntries') ?? 0));
            $counts[$table->id] = FlightAssignment::proposeFlights($entries, $perFlight, true);
        }

        return view('judging.flights', [
            'ctx' => $ctx,
            'tables' => $tables,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, int $id): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $planning = self::planningMode($ctx);
        $table = DB::table('judging_tables')->where('id', $id)->first();
        if ($table === null) {
            return redirect('/admin/judging/flights');
        }

        $entries = self::gridEntries($id, (string) $table->tableStyles, $planning);
        $perFlight = max(1, (int) ($ctx->judgingStr('jPrefsFlightEntries') ?? 0));
        $flightCount = FlightAssignment::proposeFlights($entries, $perFlight, true);

        return view('judging.flights-table', [
            'ctx' => $ctx,
            'planning' => $planning,
            'table' => $table,
            'entries' => $entries,
            'flightCount' => $flightCount,
            'entryCount' => count($entries),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $ctx = TenantContext::load();
        $table = DB::table('judging_tables')->where('id', $id)->first();
        if ($table === null) {
            return redirect('/admin/judging/flights');
        }

        $validIds = self::gridEntries($id, (string) $table->tableStyles, self::planningMode($ctx))
            ->pluck('id')->map(intval(...))->all();

        $data = $request->validate([
            'flights' => ['nullable', 'array'],
            'flights.*' => ['integer', 'min:1'],
        ]);

        $flightCount = max(1, FlightAssignment::proposeFlights(
            self::gridEntries($id, (string) $table->tableStyles, self::planningMode($ctx)),
            max(1, (int) ($ctx->judgingStr('jPrefsFlightEntries') ?? 0)),
            true,
        ));

        foreach ($data['flights'] ?? [] as $entryId => $flightNumber) {
            $entryId = (int) $entryId;
            $flightNumber = (int) $flightNumber;

            // Radios only exist for entries on this table's grid and flights
            // within the proposed count.
            if (! in_array($entryId, $validIds, true) || $flightNumber > $flightCount) {
                continue;
            }

            $existing = DB::table('judging_flights')
                ->where('flightTable', $id)
                ->where('flightRound', 1)
                ->where('flightEntryID', (string) $entryId)
                ->first();

            if ($existing !== null) {
                DB::table('judging_flights')->where('id', $existing->id)
                    ->update(['flightNumber' => $flightNumber]);
            } else {
                DB::table('judging_flights')->insert(
                    FlightAssignment::flightRow($id, $flightNumber, $entryId),
                );
            }
        }

        return redirect('/admin/judging/flights/'.$id);
    }

    /**
     * "Assign Flights to Rounds" sub-screen (legacy go=judging_flights
     * &action=assign&filter=rounds): every defined table with its location,
     * one select per defined flight. Current round per flight follows
     * flight_round_number() (admin.lib.php:747): the flight's round only
     * counts once EVERY judging_flights row for that table/flight has a
     * non-empty round, and then it's the latest row's value.
     */
    public function rounds(Request $request): View|RedirectResponse
    {
        $rows = [];
        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get();
        foreach ($tables as $table) {
            $location = $table->tableLocation !== null
                ? DB::table('judging_locations')->where('id', $table->tableLocation)->first()
                : null;

            $maxFlight = (int) DB::table('judging_flights')
                ->where('flightTable', $table->id)->max('flightNumber');

            $flights = [];
            for ($i = 1; $i <= $maxFlight; $i++) {
                $flights[$i] = self::flightRoundNumber((int) $table->id, $i);
            }

            $rows[] = ['table' => $table, 'location' => $location, 'flights' => $flights];
        }

        return view('judging.flights-rounds', [
            'ctx' => TenantContext::load(),
            'rows' => $rows,
        ]);
    }

    /**
     * Legacy process_judging_flights.inc.php action=assign: when a
     * table/flight's round CHANGES, delete all judge/steward assignments
     * pinned to the old round, then move every judging_flights row of that
     * table/flight to the new round. Unchanged pairs are no-ops.
     */
    public function assignRounds(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rounds' => ['required', 'array'],
            'rounds.*' => ['array'],
            'rounds.*.*' => ['nullable', 'regex:/^\d*$/'],
        ]);

        foreach ($data['rounds'] as $tableId => $flights) {
            $tableId = (int) $tableId;
            if (DB::table('judging_tables')->where('id', $tableId)->doesntExist()) {
                continue;
            }

            foreach ((array) $flights as $flightNumber => $round) {
                $flightNumber = (int) $flightNumber;
                $previous = self::flightRoundNumber($tableId, $flightNumber);
                $round = (string) ($round ?? '');

                if ($round === $previous) {
                    continue;
                }

                DB::table('judging_assignments')
                    ->where('assignTable', $tableId)
                    ->where('assignFlight', $flightNumber)
                    ->where('assignRound', $previous)
                    ->delete();

                // Legacy stored the raw posted value on an INT column, so
                // "Not Assigned" landed as 0 — mirrored.
                DB::table('judging_flights')
                    ->where('flightTable', $tableId)
                    ->where('flightNumber', $flightNumber)
                    ->update(['flightRound' => $round === '' ? 0 : (int) $round]);
            }
        }

        return redirect('/admin/judging/flights/rounds');
    }

    /**
     * jPrefsTablePlanning flips both counting (#2) and the grid to ALL
     * entries instead of received-only.
     */
    private static function planningMode(TenantContext $ctx): bool
    {
        return $ctx->judgingStr('jPrefsTablePlanning') === '1';
    }

    /** Legacy flight_round_number(): "" unless every row has a round; then latest. */
    private static function flightRoundNumber(int $tableId, int $flightNumber): string
    {
        $rounds = DB::table('judging_flights')
            ->where('flightTable', $tableId)
            ->where('flightNumber', $flightNumber)
            ->orderBy('id')
            ->pluck('flightRound');

        if ($rounds->isEmpty() || $rounds->contains(fn ($r) => (int) $r <= 0)) {
            return '';
        }

        return (string) (int) $rounds->last();
    }

    /**
     * Grid rows: entry + any existing round-1 flight row, ordered by the
     * engine's verbatim ORDER BY (#4 — saved manual order first, NULLs last,
     * then category/subcategory/judging number).
     *
     * Laravel prefixes join ALIASES along with tables when the connection
     * carries a prefix (baseline_), so the aliases are built prefixed and
     * handed to reorderOrderBy() verbatim.
     *
     * @return Collection<int, \stdClass>
     */
    private static function gridEntries(int $tableId, string $tableStyles, bool $planning): Collection
    {
        [$f, $b] = self::aliases();

        return self::entryQuery($tableStyles, $planning)
            ->leftJoin('judging_flights as flights', function ($join) use ($tableId): void {
                $join->on('flights.flightEntryID', '=', 'b.id')
                    ->where('flights.flightTable', $tableId)
                    ->where('flights.flightRound', 1);
            })
            // Raw fragment must spell the aliases exactly as the grammar
            // wraps them (prefix-qualified). Both aliases are internal
            // sanitized strings, never user input.
            ->orderByRaw(FlightAssignment::reorderOrderBy(...self::aliases())) // @phpstan-ignore argument.type
            ->get(['b.id', 'b.brewJudgingNumber', 'b.brewCategorySort', 'b.brewSubCategory', 'b.brewStyle',
                'flights.id as flightId', 'flights.flightNumber']);
    }

    /**
     * @return array{0: non-empty-string, 1: non-empty-string} [flights alias, brewing alias],
     *                                                         prefix-qualified to survive the grammar's alias wrapping.
     */
    private static function aliases(): array
    {
        $prefix = (string) config('database.connections.mysql.prefix');

        return [$prefix.'flights', $prefix.'b'];
    }

    /**
     * Plain entry list (no flight join) for counting.
     *
     * @return list<array<string, mixed>>
     */
    private static function tableEntries(int $tableId, string $tableStyles, bool $planning): array
    {
        $rows = self::entryQuery($tableStyles, $planning)
            ->get(['b.id'])
            ->map(fn (\stdClass $r): array => (array) $r)
            ->all();

        return array_values($rows);
    }

    private static function entryQuery(string $tableStyles, bool $planning): Builder
    {
        $styleIds = array_values(array_filter(array_map(intval(...), explode(',', $tableStyles))));
        $styles = DB::table('styles')->whereIn('id', $styleIds ?: [0])
            ->get(['brewStyleGroup', 'brewStyleNum']);

        $query = DB::table('brewing as b');

        if (! $planning) {
            $query->where('b.brewReceived', '1');
        }

        $query->where(function ($q) use ($styles): void {
            foreach ($styles as $style) {
                $q->orWhere(function ($qq) use ($style): void {
                    $qq->where('b.brewCategorySort', $style->brewStyleGroup)
                        ->where('b.brewSubCategory', $style->brewStyleNum);
                });
            }
        });

        return $query;
    }
}
