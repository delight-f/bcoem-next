<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eval;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Evaluation sub-app dashboard (spec P4.6): judge view of assigned tables
 * and entries, with the admin surfaces of legacy judging_dashboard /
 * judging_admin folded into one admin panel (ledger port verdict:
 * "fold into P4.6 admin UI"). Legacy dashboard.eval.php's live counters
 * and duplicate-place alerts are out of scope for the minimal port.
 *
 * Auth is route middleware; the admin panel is gated in-controller
 * (userLevel<=1, same pattern as ManualPaymentController).
 */
final class EvalDashboardController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $ctx = TenantContext::load();
        $archive = self::archiveSuffix($request);
        $uid = (int) $user->id;

        // Assigned tables (judges only care about assignment='J').
        $assignments = DB::table('judging_assignments')
            ->where('bid', $uid)
            ->where('assignment', 'J')
            ->get();

        $tables = [];
        foreach ($assignments as $assignment) {
            $table = DB::table('judging_tables')->where('id', $assignment->assignTable)->first();

            if ($table === null) {
                continue;
            }

            $tables[] = [
                'table' => $table,
                'entries' => $this->tableEntries((int) $table->id, $archive),
            ];
        }

        $adminPanel = null;
        if ($user->isAdmin()) {
            $evalCounts = [];

            foreach (DB::table('evaluation')->get(['eid']) as $row) {
                if ($row->eid !== null) {
                    $evalCounts[$row->eid] = ($evalCounts[$row->eid] ?? 0) + 1;
                }
            }

            // Ledger #2: exactly one evaluation ⇒ not importable ("singles").
            $singles = array_keys(array_filter($evalCounts, static fn ($c) => $c === 1));

            $adminPanel = [
                'totalEvaluations' => count($evalCounts),
                'consensusReady' => count($evalCounts) - count($singles),
                'singles' => $singles,
                'officialScores' => (int) DB::table('judging_scores')->count(),
            ];
        }

        return view('eval.dashboard', [
            'ctx' => $ctx,
            'archive' => $archive,
            'tables' => $tables,
            'admin' => $adminPanel,
            // jPrefsScoresheet: 1 full (checklist dropped), 3/4 structured.
            'variant' => in_array((int) $ctx->judgingStr('jPrefsScoresheet'), [3, 4], true) ? 'structured' : 'full',
        ]);
    }

    /**
     * Entries sitting at a judging table via its flights
     * (flightEntryID holds a comma-separated brewing.id list).
     *
     * @return iterable<int, object>
     */
    private function tableEntries(int $tableId, string $archive): iterable
    {
        $ids = [];
        foreach (DB::table('judging_flights')->where('flightTable', $tableId)->get(['flightEntryID']) as $flight) {
            foreach (explode(',', (string) $flight->flightEntryID) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        // Archive suffix support (ledger: archive_suffix): suffixed table
        // names resolve through the connection prefix, e.g. ?archive=_2025
        // reads baseline_brewing_2025. The evaluation/judging_scores tables
        // themselves stay unsuffixed, matching eval/db.eval.php.
        return DB::table('brewing'.$archive)->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'brewName', 'brewCategorySort', 'brewSubCategory', 'brewStyle']);
    }

    /**
     * Validated `?archive=` query param ('' when absent); alphanumeric +
     * underscore so it can only ever extend a table name.
     */
    public static function archiveSuffix(Request $request): string
    {
        $raw = $request->query('archive', '');
        $suffix = is_string($raw) ? $raw : '';

        return preg_match('/^[A-Za-z0-9_]{0,10}$/', $suffix) === 1 ? $suffix : '';
    }
}
