<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Judge/steward assignment roster (spec §7 P5.2).
 *
 * Legacy: output/assignments.output.php via print.output.php
 * section=assignments (?filter=judges|stewards). One row per
 * judging_assignments record joined to the participant (by uid — the
 * assignment admin writes brewer.uid into judging_assignments.bid), the
 * table and the session location, followed by the "Bull Pen" list of
 * signed-up judges/stewards with no assignment in that role (legacy
 * admin.lib.php not_assigned()).
 *
 * DIVERGENCE: legacy's per-view sort variants (view=name|table|location),
 * staff-availability filter and sign-in sheets are not ported; the PDF is
 * a single canonical roster ordered by location, table, round, flight.
 */
final class AssignmentsController extends Controller
{
    /** Legacy assignRoles codes → human labels. */
    private const ROLES = ['HJ' => 'Table Head Judge', 'LJ' => 'Lead Judge', 'MBOS' => 'Mini-BOS Judge'];

    private const CERTS = [
        'Master Cicerone', 'Advanced Cicerone', 'Certified Cicerone',
        'Professional Brewer', 'Certified Cider Guide', 'Certified Pommelier',
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        $role = $request->query('filter') === 'stewards' ? 'S' : 'J';
        $staffColumn = $role === 'S' ? 'staff_steward' : 'staff_judge';

        // First rank token + extra certifications, mirroring legacy display.
        $rows = $this->assignmentQuery($role)->get()->map(static function ($row) {
            $ranks = array_filter(array_map('trim', explode(',', (string) $row->brewerJudgeRank)));
            $rank = $ranks[0] ?? 'Non-BJCP';
            $certs = implode(', ', array_intersect(self::CERTS, $ranks));

            return [
                'name' => trim(($row->brewerLastName ?? '').', '.($row->brewerFirstName ?? '')),
                'club' => $row->brewerClubs,
                'roles' => implode(' / ', array_intersect_key(
                    self::ROLES,
                    array_flip(array_map('trim', explode(',', (string) $row->assignRoles))),
                )),
                'rank' => $certs === '' ? $rank : $rank.', '.$certs,
                'location' => $row->judgingLocName,
                'tableNumber' => $row->tableNumber,
                'tableName' => $row->tableName,
                'round' => $row->assignRound,
                'flight' => $row->assignFlight,
            ];
        });

        // Bull pen: signed-up staff for this role with zero assignments.
        $bullPen = DB::table('staff')
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
            ->map(static function ($b) {
                $ranks = array_filter(array_map('trim', explode(',', (string) $b->brewerJudgeRank)));

                return [
                    'name' => trim(($b->brewerLastName ?? '').', '.($b->brewerFirstName ?? '')),
                    'rank' => $ranks[0] ?? 'Non-BJCP',
                ];
            });

        return StreamPdf::response('outputs.assignments', [
            'contestName' => $ctx->contestStr('contestName'),
            'roleLabel' => $role === 'S' ? 'Steward' : 'Judge',
            'showFlight' => strtoupper((string) $ctx->judgingStr('jPrefsQueued')) !== 'N',
            'rows' => $rows,
            'bullPen' => $bullPen,
        ], 'assignments.pdf');
    }

    private function assignmentQuery(string $role): Builder
    {
        return DB::table('judging_assignments')
            ->join('brewer', 'brewer.uid', '=', 'judging_assignments.bid')
            ->leftJoin('judging_tables', 'judging_tables.id', '=', 'judging_assignments.assignTable')
            ->leftJoin('judging_locations', 'judging_locations.id', '=', 'judging_assignments.assignLocation')
            ->where('judging_assignments.assignment', $role)
            ->orderBy('judging_locations.judgingLocName')
            ->orderBy('judging_tables.tableNumber')
            ->orderBy('judging_assignments.assignRound')
            ->orderBy('judging_assignments.assignFlight')
            ->select(
                'brewer.brewerFirstName',
                'brewer.brewerLastName',
                'brewer.brewerClubs',
                'brewer.brewerJudgeRank',
                'judging_assignments.assignRoles',
                'judging_assignments.assignRound',
                'judging_assignments.assignFlight',
                'judging_tables.tableNumber',
                'judging_tables.tableName',
                'judging_locations.judgingLocName',
            );
    }
}
