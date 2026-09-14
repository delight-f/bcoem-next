<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tables_mode toggle (spec P4.7): ajax/tables_mode.ajax.php — flips the
 * competition between table-planning mode (jPrefsTablePlanning=1, ledger/
 * flight-assignment.md #2) and production mode. Persisted judging_tables
 * configuration is never restructured by a switch; only derived flight
 * data is pruned on the way into competition mode (issue #39).
 *
 * Behaviour:
 *  - enable-planning: ensures the three planning columns exist (legacy
 *    ALTERed them in on first use), dumps every NOT-received entry of
 *    each table's styles into flight 1 of that table, drops a style that
 *    names a missing styles row (cascading the table away only when every
 *    style it names is dangling — a valid style with no entries yet is
 *    kept, issue #39), flags everything planning (1), sets
 *    jPrefsTablePlanning=1.
 *  - enable-competition: deletes flights whose entry was never received
 *    and flags the survivors production (0) — derived data only. Table
 *    configuration is left intact: no table/styles pruning, no cascade
 *    deletion and no TRUNCATE, so an organizer who defined tables but
 *    never held a flight keeps every table and assignment (the switch is
 *    then just the flag flip). Unassigns any judge/steward holding an
 *    entry conflict at their assigned table (conflict check uses
 *    jPrefsTablePlanning as it stood BEFORE this request — legacy read the
 *    stale session copy), sets jPrefsTablePlanning=0.
 *
 * Envelope parity: {"status","error_count","error_type"} stringified; a
 * non-admin hit returns an EMPTY body (legacy echoed nothing outside its
 * gate). Gate is userLevel<=2 per legacy (any logged-in account); CSRF-
 * protected POST is port hardening.
 */
final class TablesModeController extends Controller
{
    public function store(Request $request): Response
    {
        $user = $request->user();

        if ($user === null || (int) $user->userLevel > 2) {
            return response('', 200);
        }

        $section = AjaxController::sterilize((string) $request->input('section', 'default'));

        $status = 0;
        $errorCount = 0;

        if ($section === 'enable-planning') {
            $this->enablePlanning($errorCount);

            if ($errorCount === 0) {
                $status = 1;
            }
        } elseif ($section === 'enable-competition') {
            $this->enableCompetition($request, $errorCount);

            if ($errorCount === 0) {
                $status = 1;
            }
        }

        // Legacy left status at 0 for an unknown section.
        $errorType = $errorCount > 0 ? 3 : 0; // 3 = SQL error

        // Same envelope shape json_encode produced in legacy.
        $payload = json_encode([
            'status' => "$status",
            'error_count' => "$errorCount",
            'error_type' => "$errorType",
        ]);

        return response(
            $payload === false ? '{"status":"0","error_count":"1","error_type":"3"}' : $payload,
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    private function enablePlanning(int &$errorCount): void
    {
        foreach ([['assignPlanning', 'judging_assignments'], ['flightPlanning', 'judging_flights'], ['jPrefsTablePlanning', 'judging_preferences']] as [$column, $table]) {
            if (! Schema::hasColumn($table, $column)) {
                try {
                    DB::statement(sprintf('ALTER TABLE `%s%s` ADD `%s` TINYINT(1) NULL;', $this->prefix(), $table, $column));
                } catch (\Throwable) {
                    $errorCount += 1;
                }
            }
        }

        if ((int) DB::table('judging_flights')->count() > 0) {
            foreach (DB::table('judging_tables')->get(['id', 'tableStyles']) as $table) {
                $keep = [];

                foreach (array_unique(explode(',', (string) $table->tableStyles)) as $styleId) {
                    if ($styleId === '') {
                        continue;
                    }

                    $style = DB::table('styles')->where('id', $styleId)->first(['brewStyleGroup', 'brewStyleNum']);
                    if ($style === null) {
                        continue; // dangling style reference: no entries can match
                    }

                    // A style that exists is configuration: keep it even before
                    // any entry has been submitted. Only dangling references
                    // are dropped, so planning never strips a table's styles.
                    $keep[] = $styleId;

                    $entries = DB::table('brewing')
                        ->where('brewCategorySort', $style->brewStyleGroup)
                        ->where('brewSubCategory', $style->brewStyleNum)
                        ->get(['id', 'brewReceived']);

                    $round = DB::table('judging_flights')
                        ->where('flightTable', $table->id)
                        ->where('flightNumber', 1)
                        ->value('flightRound');

                    foreach ($entries as $entry) {
                        if ((int) $entry->brewReceived === 0) {
                            try {
                                DB::table('judging_flights')->insert([
                                    'flightTable' => $table->id,
                                    'flightNumber' => 1,
                                    'flightEntryID' => $entry->id,
                                    'flightRound' => $round,
                                ]);
                            } catch (\Throwable) {
                                $errorCount += 1;
                            }
                        }
                    }
                }

                if ($keep === []) {
                    $errorCount += $this->deleteTableCascade((int) $table->id);
                } else {
                    try {
                        DB::table('judging_tables')->where('id', $table->id)
                            ->update(['tableStyles' => implode(',', $keep)]);
                    } catch (\Throwable) {
                        $errorCount += 1;
                    }
                }
            }

            try {
                DB::table('judging_flights')->update(['flightPlanning' => 1]);
            } catch (\Throwable) {
                $errorCount += 1;
            }

            try {
                DB::table('judging_assignments')->update(['assignPlanning' => 1]);
            } catch (\Throwable) {
                $errorCount += 1;
            }
        }

        try {
            DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);
        } catch (\Throwable) {
            $errorCount += 1;
        }
    }

    private function enableCompetition(Request $request, int &$errorCount): void
    {
        $request->session()->put('judge_unassign_flag', 0);

        // Conflict checks read the planning flag as it stood before this
        // request (legacy read the stale session copy).
        $planningFlag = (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning');

        $received = DB::table('brewing')->where('brewReceived', 1)->pluck('id')->all();

        $flightEntries = DB::table('judging_flights')->pluck('flightEntryID')
            ->filter(static fn ($id) => $id !== null && $id !== '')
            ->all();

        $unassignFlag = 0;

        // Derived flight data only. No flight rows is a valid state (there
        // is nothing to prune); table configuration is never touched here.
        if ($flightEntries !== []) {
            try {
                if ($received === []) {
                    DB::table('judging_flights')->delete();
                } else {
                    DB::table('judging_flights')->whereNotIn('flightEntryID', $received)->delete();
                }
            } catch (\Throwable) {
                $errorCount += 1;
            }

            try {
                DB::table('judging_flights')->update(['flightPlanning' => 0]);
            } catch (\Throwable) {
                $errorCount += 1;
            }

            foreach (DB::table('judging_tables')->get(['id', 'tableStyles']) as $table) {
                // Unassign judges/stewards holding their own entry in a
                // style this table covers. The table's styles and every
                // other assignment survive the switch untouched.
                foreach (DB::table('judging_assignments')->where('assignTable', $table->id)->get(['id', 'bid']) as $assignment) {
                    if ($this->entryConflict((string) $assignment->bid, (string) $table->tableStyles, $planningFlag)) {
                        try {
                            DB::table('judging_assignments')->where('id', $assignment->id)->delete();
                        } catch (\Throwable) {
                            $errorCount += 1;
                        }

                        $unassignFlag += 1;
                    }
                }
            }
        }

        try {
            DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 0]);
        } catch (\Throwable) {
            $errorCount += 1;
        }

        if ($unassignFlag > 0) {
            $request->session()->put('judge_unassign_flag', 1);
        }
    }

    /** Delete one judging_table plus its assignments and flights. */
    private function deleteTableCascade(int $tableId): int
    {
        $errors = 0;

        foreach ([['judging_tables', 'id'], ['judging_assignments', 'assignTable'], ['judging_flights', 'flightTable']] as [$table, $column]) {
            try {
                DB::table($table)->where($column, $tableId)->delete();
            } catch (\Throwable) {
                $errors += 1;
            }
        }

        return $errors;
    }

    /**
     * Legacy entry_conflict(): true when bid has own entries matching any
     * of the table's styles (received-only once planning mode is off).
     */
    private function entryConflict(string $bid, string $tableStyles, int $planningFlag): bool
    {
        $conflicts = 0;

        if ($tableStyles !== '') {
            foreach (explode(',', $tableStyles) as $styleId) {
                $style = DB::table('styles')->where('id', $styleId)->first(['brewStyleGroup', 'brewStyleNum']);

                if ($style !== null && $bid !== '999999999') {
                    $query = DB::table('brewing')
                        ->where('brewBrewerID', $bid)
                        ->where('brewCategorySort', $style->brewStyleGroup)
                        ->where('brewSubCategory', $style->brewStyleNum);

                    if ($planningFlag === 0) {
                        $query->where('brewReceived', '1');
                    }

                    if ($query->count() > 0) {
                        $conflicts += 1;
                    }
                }
            }
        }

        return $conflicts > 0;
    }

    private function prefix(): string
    {
        return (string) config('database.connections.mysql.prefix');
    }
}
