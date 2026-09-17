<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Judge/steward → table/flight assignment screen (spec §6 P4.3). Legacy:
 * admin/judging_assign.admin.php + process_judging_assignments.inc.php.
 *
 * Storage parity: judging_assignments rows carry exactly the legacy columns
 * (bid, assignment J/S, assignTable, assignFlight, assignRound,
 * assignLocation = the table's session location, assignPlanning =
 * jPrefsTablePlanning, assignRoles).
 *
 * Preference/conflict surfacing mirrors legacy judge_alert()/like_dislike()
 * precedence per participant × flight:
 *   1. entry conflict (participant has an entry in the table's styles) —
 *      control DISABLED and, like legacy's render-time cleanup, any
 *      conflicting assignment row is deleted;
 *   2. already assigned to this table+flight+round — "Assigned";
 *   3. assigned elsewhere at this location in the same round — "busy";
 *   4. likes overlap with the table's style ids — preferred (green);
 *      dislikes overlap without likes — non-preferred (red); else available.
 *
 * POST semantics: for every submitted participant×round either a flight
 * number is chosen (existing round rows for that table are replaced with
 * one fresh row) or 0 clears them — the same net state legacy's
 * unassign/reassign checkbox dance produced.
 */
final class AssignController extends Controller
{
    public function show(Request $request, int $id, string $role): View|RedirectResponse
    {
        if (! in_array($role, ['judges', 'stewards'], true)) {
            abort(404);
        }

        $ctx = TenantContext::load();
        $table = DB::table('judging_tables')->where('id', $id)->first();
        if ($table === null) {
            return redirect('/admin/judging/flights');
        }

        $planning = $ctx->judgingStr('jPrefsTablePlanning') === '1';
        $styleIds = array_values(array_filter(array_map(intval(...), explode(',', (string) $table->tableStyles))));

        // One row per flightNumber; "assign to rounds" keeps a single round
        // per flight across its rows.
        $flights = DB::table('judging_flights')
            ->where('flightTable', $id)
            ->groupBy('flightNumber')
            ->orderBy('flightNumber')
            ->selectRaw('flightNumber, MAX(flightRound) as flightRound')
            ->get();

        $roleColumn = $role === 'judges' ? 'brewerJudge' : 'brewerSteward';
        $assignmentCode = $role === 'judges' ? 'J' : 'S';

        $participants = DB::table('brewer')
            ->where(function ($q) use ($roleColumn, $id, $assignmentCode): void {
                $q->where($roleColumn, 'Y')
                    ->orWhereExists(function ($sub) use ($id, $assignmentCode): void {
                        $sub->selectRaw('1')
                            ->from('judging_assignments')
                            ->whereColumn('judging_assignments.bid', 'brewer.uid')
                            ->where('assignTable', $id)
                            ->where('assignment', $assignmentCode);
                    });
            })
            ->orderBy('brewerLastName')->orderBy('brewerFirstName')
            ->get();

        $rows = [];
        foreach ($participants as $participant) {
            $bid = (int) $participant->uid;

            $conflict = $this->entryConflict($bid, $styleIds, $planning);

            // Legacy deletes conflicting assignments while rendering the
            // screen; the port performs the identical cleanup here.
            if ($conflict) {
                DB::table('judging_assignments')
                    ->where('bid', $bid)->where('assignTable', $id)
                    ->where('assignment', $assignmentCode)->delete();
            }

            $assignments = DB::table('judging_assignments')->where('bid', $bid)->get();

            $perFlight = [];
            foreach ($flights as $flight) {
                $round = (int) $flight->flightRound;
                $here = $assignments->first(
                    fn (\stdClass $a): bool => (int) $a->assignTable === $id
                        && (int) $a->assignFlight === (int) $flight->flightNumber
                        && (int) $a->assignRound === $round,
                );
                $elsewhere = $assignments->first(
                    fn (\stdClass $a): bool => (int) $a->assignRound === $round
                        && (int) $a->assignLocation === (int) $table->tableLocation
                        && (! $here || (int) $a->id !== (int) $here->id),
                );

                $status = match (true) {
                    $conflict => 'conflict',
                    $here !== null => 'assigned',
                    $elsewhere !== null => 'busy',
                    $this->likesOverlap((string) $participant->brewerJudgeLikes, $styleIds) => 'preferred',
                    $this->dislikesOverlap((string) $participant->brewerJudgeDislikes, $styleIds) => 'non-preferred',
                    default => 'available',
                };

                $perFlight[] = [
                    'flightNumber' => (int) $flight->flightNumber,
                    'round' => $round,
                    'status' => $status,
                    'assignedFlight' => $here !== null ? (int) $here->assignFlight : 0,
                ];
            }

            $rows[] = [
                'uid' => $bid,
                'name' => trim((string) $participant->brewerFirstName.' '.(string) $participant->brewerLastName),
                'flights' => $perFlight,
                'conflict' => $conflict,
                'ineligible' => (string) $participant->{$roleColumn} !== 'Y',
            ];
        }

        return view('judging.assign', [
            'ctx' => $ctx,
            'role' => $role,
            'table' => $table,
            'flights' => $flights,
            'rows' => $rows,
            'locationId' => (int) $table->tableLocation,
            'assignPlanning' => (int) ($ctx->judgingStr('jPrefsTablePlanning') ?? 0),
        ]);
    }

    public function store(Request $request, int $id, string $role): RedirectResponse
    {
        if (! in_array($role, ['judges', 'stewards'], true)) {
            abort(404);
        }

        $ctx = TenantContext::load();
        $table = DB::table('judging_tables')->where('id', $id)->first();
        if ($table === null) {
            return redirect('/admin/judging/flights');
        }

        $data = $request->validate([
            'assign' => ['nullable', 'array'],
            'assign.*' => ['array'],
            'assign.*.*' => ['integer', 'min:0'],
        ]);

        $planning = $ctx->judgingStr('jPrefsTablePlanning') === '1';
        $assignment = $role === 'judges' ? 'J' : 'S';
        $styleIds = array_values(array_filter(array_map(intval(...), explode(',', (string) $table->tableStyles))));

        foreach ($data['assign'] ?? [] as $bid => $rounds) {
            $bid = (int) $bid;
            if ($this->entryConflict($bid, $styleIds, $planning)) {
                continue; // never (re)assign a conflicted participant
            }

            foreach ($rounds as $round => $flight) {
                $round = (int) $round;

                DB::table('judging_assignments')
                    ->where('bid', $bid)
                    ->where('assignTable', $id)
                    ->where('assignRound', $round)
                    ->where('assignment', $assignment)
                    ->delete();

                if ((int) $flight > 0) {
                    DB::table('judging_assignments')->insert([
                        'bid' => $bid,
                        'assignment' => $assignment,
                        'assignTable' => $id,
                        'assignFlight' => (int) $flight,
                        'assignRound' => $round,
                        'assignLocation' => (int) $table->tableLocation,
                        'assignPlanning' => (int) ($ctx->judgingStr('jPrefsTablePlanning') ?? 0),
                        'assignRoles' => null,
                    ]);
                }
            }
        }

        return redirect('/admin/judging/flights/'.$id.'/assign/'.$role);
    }

    /**
     * Legacy entry_conflict(): does the participant have an entry (received
     * unless planning mode) whose category/subcategory matches one of the
     * table's styles?
     *
     * @param  list<int>  $styleIds
     */
    private function entryConflict(int $bid, array $styleIds, bool $planning): bool
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
     * Legacy like_dislike() compares the participant's stored style-id CSVs
     * directly against the table's tableStyles ids.
     *
     * @param  list<int>  $styleIds
     */
    private function csvOverlap(?string $csv, array $styleIds): bool
    {
        return array_intersect(
            array_filter(array_map(intval(...), explode(',', (string) $csv))),
            $styleIds,
        ) !== [];
    }

    /**
     * @param  list<int>  $styleIds
     */
    private function likesOverlap(string $likes, array $styleIds): bool
    {
        return $likes !== '' && $this->csvOverlap($likes, $styleIds);
    }

    /**
     * @param  list<int>  $styleIds
     */
    private function dislikesOverlap(string $dislikes, array $styleIds): bool
    {
        return $dislikes !== '' && $this->csvOverlap($dislikes, $styleIds);
    }
}
