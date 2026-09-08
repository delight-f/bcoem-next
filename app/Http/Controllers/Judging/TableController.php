<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\BrewController;
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
 * Judging-table config (spec §6 P4.1, ticket 01). Legacy:
 * admin/judging_tables.admin.php + process_judging_tables.inc.php.
 *
 * Storage parity: a table is an organizer-defined style grouping —
 * `tableStyles` is a CSV of `styles.id`s (ledger/flight-assignment.md #6) —
 * plus a display number, a judging-session location and an optional entry
 * limit. Every column is blank_to_null'd.
 *
 * Table numbering follows the assignment-engine contract (ledger #3): a
 * new table gets max(tableNumber)+1; gaps are never compacted. The engine
 * (App\Support\Judging\FlightAssignment, ticket 02) owns nextTableNumber()
 * once it lands; until then the same max+1 is computed inline.
 *
 * ponytail: legacy's add/edit also seeded judging_flights/judging_scores
 * rows for received entries of the table's styles and flipped
 * styles.brewStyleAtLimit — flight population belongs to the P4.2 engine
 * and the at-limit flags to the entry-receipt flow, so neither is
 * duplicated here. Table delete still cascades scores/flights/BOS rows as
 * process_delete.inc.php did, minus its latent bug that deleted
 * judging_assignments rows by judging_scores id collision.
 */
final class TableController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Legacy ?action=assign&filter={judges|stewards|staff|bos} URLs (from
        // the participants dropdown, dashboard stats, sidebar, and nav) land
        // here. In legacy, go=judging&action=assign rendered the pool-assignment
        // screen (judging_locations.admin.php action=assign block) — a
        // participant-role pool manager. In the port, pool assignment lives at
        // /admin/judging/pool-assign?filter={filter}. With an id= param the
        // link targets the per-table AssignController route directly.
        // Redirect both shapes so all callers work.
        if ($request->query('action') === 'assign') {
            $filter = (string) $request->query('filter', '');
            $id = $request->query('id');
            if (is_numeric($id)) {
                $role = in_array($filter, ['judges', 'stewards'], true) ? $filter : 'judges';
                return redirect()->route('admin.judging.assign.show', [
                    'id' => (int) $id,
                    'role' => $role,
                ]);
            }
            // Pool-level: judges/stewards/staff/bos or default.
            $target = in_array($filter, ['judges', 'stewards', 'staff', 'bos'], true)
                ? '/admin/judging/pool-assign?filter='.$filter
                : '/admin/judging/pool-assign';
            // Preserve view=yes (staff availability filter) if present.
            if ($filter === 'staff' && $request->query('view') === 'yes') {
                $target .= '&view=yes';
            }
            return redirect($target);
        }

        // jPrefsTablePlanning drives the mode alert + switch buttons
        // (judging_tables.admin.php:594-651).
               $planning = (string) TenantContext::load()->judgingStr('jPrefsTablePlanning') === '1';

        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get();

        // Legacy get_table_info count_total / score_total per table
        // (common.lib.php:1976-2020): received-entry count summed over the
        // table's style ids (brewReceived=1 unless planning mode), and
        // judging_scores rows at the table.
        $styleCounts = DB::table('brewing')
            ->selectRaw('brewCategorySort, brewSubCategory, COUNT(*) AS n')
            ->when(! $planning, fn ($q) => $q->where('brewReceived', '1'))
            ->groupBy('brewCategorySort', 'brewSubCategory')
            ->get()
            ->keyBy(fn ($r) => $r->brewCategorySort.'^'.$r->brewSubCategory);
        $scoreCounts = DB::table('judging_scores')
            ->selectRaw('scoreTable, COUNT(*) AS n')
            ->groupBy('scoreTable')
            ->pluck('n', 'scoreTable');
        $locationNames = DB::table('judging_locations')->pluck('judgingLocName', 'id');
        $tableStyleNames = DB::table('styles')
            ->whereIn('id', collect($tables)->flatMap(fn ($t) => explode(',', (string) $t->tableStyles))->filter()->unique()->all())
            ->get(['id', 'brewStyle', 'brewStyleGroup', 'brewStyleNum'])
            ->keyBy('id');

        $tables->transform(function ($t) use ($styleCounts, $scoreCounts, $tableStyleNames, $locationNames) {
            $t->tableLocationName = ! empty($t->tableLocation)
                ? (string) ($locationNames[(int) $t->tableLocation] ?? '')
                : '';
            $styleIds = array_filter(explode(',', (string) $t->tableStyles));
            $t->receivedTotal = 0;
            $t->stylesLabel = '';
            $labels = [];
            foreach ($styleIds as $sid) {
                $sid = (int) $sid;
                $style = $tableStyleNames[$sid] ?? null;
                $labels[] = (string) ($style->brewStyle ?? $sid);
                if ($style !== null) {
                    $t->receivedTotal += (int) ($styleCounts[$style->brewStyleGroup.'^'.$style->brewStyleNum]->n ?? 0);
                }
            }
            $t->stylesLabel = implode(', ', $labels);
            $t->scoredTotal = (int) ($scoreCounts[$t->id] ?? 0);

            return $t;
        });

        return view('judging.config.tables', [
            'ctx' => TenantContext::load(),
                        'tables' => $tables,
            'planning' => $planning,
            // Print "By Location" items render only when >1 judging session
            // (legacy $totalRows_judging > 1, judging_tables.admin.php:799-813).
            'sessionCount' => DB::table('judging_locations')->whereIn('judgingLocType', [0, 1])->count(),
            // Pullsheets by Table only when the admin does not obfuscate entry
            // data (judging_tables.admin.php:800-803).
            'obfuscate' => (int) ($request->user()?->userAdminObfuscate ?? 0) === 1,
            // Judges/Stewards Not Assigned to a Table modals (legacy
            // lib/admin.lib.php not_assigned()).
            'unassignedJudges' => $this->unassigned('J', 'staff_judge'),
            'unassignedStewards' => $this->unassigned('S', 'staff_steward'),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.config.table-form', [
            'ctx' => TenantContext::load(),
            'table' => null,
            'usedNumbers' => $this->usedNumbers(),
            'locations' => $this->sessionLocations(),
            'styles' => $this->activeStyles(),
            'styleAssignments' => $this->styleAssignments(),
            'nextTableNumber' => $this->nextTableNumber(),
        ]);
    }

    /**
     * style id => assigned table row (legacy get_table_info 'assigned',
     * common.lib.php:1900: first table whose tableStyles list contains it).
     *
     * @return array<int, object{id: int, tableNumber: string, tableName: string}>
     */
    private function styleAssignments(): array
    {
        $out = [];
        DB::table('judging_tables')
            ->get(['id', 'tableNumber', 'tableName', 'tableStyles'])
            ->each(function ($t) use (&$out) {
                foreach (array_filter(explode(',', (string) $t->tableStyles), 'strlen') as $sid) {
                    $out[(int) $sid] ??= (object) ['id' => (int) $t->id, 'tableNumber' => (string) $t->tableNumber, 'tableName' => (string) $t->tableName];
                }
            });

        return $out;
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('judging_tables')->insert($this->storageRow($request));

        return redirect('/admin/judging/tables');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $table = DB::table('judging_tables')->where('id', $id)->first();
        if ($table === null) {
            return redirect('/admin/judging/tables');
        }

        return view('judging.config.table-form', [
            'ctx' => TenantContext::load(),
            'table' => $table,
            'usedNumbers' => array_diff($this->usedNumbers(), [(int) $table->tableNumber]),
            'locations' => $this->sessionLocations(),
            'styles' => $this->activeStyles(),
            'styleAssignments' => $this->styleAssignments(),
            'nextTableNumber' => null,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('judging_tables')->where('id', $id)->update($this->storageRow($request));

        return redirect('/admin/judging/tables');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Cascade like process_delete.inc.php (go=judging_tables): scores,
        // flights, BOS scores for the affected entries, then the row.
        $scoreIds = DB::table('judging_scores')->where('scoreTable', $id)->pluck('id');
        $entryIds = DB::table('judging_scores')->where('scoreTable', $id)->pluck('eid');

        DB::table('judging_scores')->whereIn('id', $scoreIds->all() ?: [0])->delete();
        DB::table('judging_flights')->where('flightTable', $id)->delete();
        if ($entryIds->isNotEmpty()) {
            DB::table('judging_scores_bos')
                ->whereIn('eid', $entryIds->all())
                ->where('scorePlace', '!=', 5)
                ->delete();
        }
        DB::table('judging_tables')->delete($id);

        return redirect('/admin/judging/tables');
    }

    /**
     * @return array<string, mixed>
     */
    private function storageRow(Request $request): array
    {
        $data = $request->validate([
            'tableName' => ['required', 'string', 'max:255'],
            'tableNumber' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $clash = DB::table('judging_tables')
                        ->where('tableNumber', (int) $value)
                        ->where('id', '!=', (int) (is_string($request->route('id')) ? $request->route('id') : 0))
                        ->exists();
                    if ($clash) {
                        $fail("Table number {$value} is already in use.");
                    }
                },
            ],
            'tableLocation' => ['required', 'integer'],
            'tableEntryLimit' => ['nullable', 'integer', 'min:1'],
            'tableStyles' => ['nullable', 'array'],
            'tableStyles.*' => ['integer'],
        ]);

        $styles = array_values(array_unique(array_map(intval(...), (array) ($data['tableStyles'] ?? []))));

        return [
            'tableName' => self::blankToNull(trim((string) $data['tableName'])),
            // Organizer-defined style grouping: CSV of styles.ids (ledger #6).
            'tableStyles' => self::blankToNull(implode(',', $styles)),
            'tableNumber' => (int) $data['tableNumber'],
            'tableLocation' => (int) $data['tableLocation'],
            'tableEntryLimit' => self::blankToNullNullableInt($data['tableEntryLimit'] ?? null),
        ];
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function blankToNullNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Assignment-engine contract (ledger #3): max+1 over existing numbers,
     * gaps never compacted, 1 when empty — delegated to the engine.
     */
    private static function nextTableNumber(): int
    {
        $numbers = DB::table('judging_tables')->distinct()->pluck('tableNumber')->map(intval(...))->all();

        return FlightAssignment::nextTableNumber(array_values($numbers));
    }

    /**
     * @return list<int>
     */
    private function usedNumbers(): array
    {
        $numbers = DB::table('judging_tables')->distinct()->orderBy('tableNumber')->pluck('tableNumber')->map(intval(...))->all();

        return array_values($numbers);
    }

    /**
     * Signed-up judges/stewards with zero table assignments — the legacy
     * lib/admin.lib.php not_assigned() roster feeding the "Not Assigned to
     * a Table" view-menu modals (mirrors Output\AssignmentsController's
     * bull pen).
     *
     * @return Collection<int, array<string, string>>
     */
    private function unassigned(string $role, string $staffColumn): Collection
    {
        return DB::table('staff')
            ->join('brewer', 'brewer.uid', '=', 'staff.uid')
            ->where($staffColumn, 1)
            ->whereNotExists(static function (Builder $q) use ($role): void {
                $q->select(DB::raw(1))
                    ->from('judging_assignments')
                    ->whereColumn('judging_assignments.bid', 'brewer.uid')
                    ->where('judging_assignments.assignment', $role);
            })
            ->orderBy('brewer.brewerLastName')
            ->get(['brewer.brewerFirstName', 'brewer.brewerLastName', 'brewer.brewerJudgeRank'])
            ->map(static function (\stdClass $b): array {
                $ranks = array_values(array_filter(array_map('trim', explode(',', (string) $b->brewerJudgeRank))));

                return [
                    'name' => trim(($b->brewerLastName ?? '').', '.($b->brewerFirstName ?? '')),
                    'rank' => $ranks[0] ?? 'Non-BJCP',
                ];
            });
    }

    /**
     * Locations eligible to host tables: judging sessions only
     * (judgingLocType < 2), as in the legacy location drop-down.
     *
     * @return Collection<int, \stdClass>
     */
    private function sessionLocations(): Collection
    {
        return DB::table('judging_locations')
            ->whereIn('judgingLocType', [0, 1])
            ->orderBy('id')
            ->get(['id', 'judgingLocName']);
    }

    /**
     * Styles eligible for tables — the active style set + customs, exactly
     * the source the brew form's style drop-down uses.
     *
     * @return Collection<int, \stdClass>
     */
    private function activeStyles(): Collection
    {
        return BrewController::activeStyles(TenantContext::load());
    }
}
