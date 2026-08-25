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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
     * jPrefsTablePlanning flips both counting (#2) and the grid to ALL
     * entries instead of received-only.
     */
    private static function planningMode(TenantContext $ctx): bool
    {
        return $ctx->judgingStr('jPrefsTablePlanning') === '1';
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
