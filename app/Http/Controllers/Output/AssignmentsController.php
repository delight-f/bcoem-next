<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\DateFmt;
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
 * Legacy view variants (assignments.output.php):
 *  - view=name (default): roster ordered by participant name
 *  - view=table: roster ordered/grouped by table number
 *  - view=location: roster filtered to ?location=N, header shows the
 *    session name + judging date
 *  - view=sign-in: per-session sign-in sheets (name / BJCP ID / waiver /
 *    signature) + blank-row sheet, not a roster
 *  - tb=view: legacy print-window chrome — no roster change
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
        $view = (string) $request->query('view', '');
        $locationId = (string) $request->query('location', '');

        if ($view === 'sign-in') {
            return $this->signInSheets($ctx, $role);
        }

        $query = $this->assignmentQuery($role);

        // view=location or a location=N filter restricts the roster to one
        // session (legacy per-session links append view=name|table too —
        // with a single location the ordering is equivalent).
        $sessionHeader = null;
        if (ctype_digit($locationId) && $locationId !== '0') {
            $query->where('judging_assignments.assignLocation', (int) $locationId);
            $loc = DB::table('judging_locations')->where('id', (int) $locationId)->first();
            if ($loc !== null) {
                $sessionHeader = trim($loc->judgingLocName.' — '.DateFmt::dateTime(
                    (int) $loc->judgingDate,
                    $ctx->prefs['prefsTimeZone'] ?? null,
                    $ctx->prefsStr('prefsDateFormat'),
                    $ctx->prefsStr('prefsTimeFormat'),
                ));
            }
        }

        // Legacy orderings: view=name → by participant; view=table → by
        // table; default (view=location or no view) → by session/table.
        if ($view === 'name') {
            $query->reorder('brewer.brewerLastName')->reorder('brewer.brewerFirstName');
        } elseif ($view === 'table') {
            $query->reorder('judging_tables.tableNumber')->reorder('brewer.brewerLastName');
        }

        // First rank token + extra certifications, mirroring legacy display.
        $rows = $query->get()->map($this->rowFormatter());

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
            'sessionHeader' => $sessionHeader,
            'rows' => $rows,
            'bullPen' => $bullPen,
        ], 'assignments.pdf');
    }

    /**
     * view=sign-in (assignments.output.php:198-340): one table per session
     * listing assigned participants (name / BJCP ID for judges / waiver
     * Y-N / signature blank), then a blank-row sheet sized to the
     * role's signup count for walk-ins.
     */
    private function signInSheets(TenantContext $ctx, string $role): Response
    {
        $staffColumn = $role === 'S' ? 'staff_steward' : 'staff_judge';

        $assigned = DB::table('judging_assignments')
            ->join('brewer', 'brewer.uid', '=', 'judging_assignments.bid')
            ->where('judging_assignments.assignment', $role)
            ->orderBy('brewer.brewerLastName')
            ->get([
                'brewer.brewerFirstName', 'brewer.brewerLastName',
                'brewer.brewerJudgeID', 'brewer.brewerJudgeWaiver',
                'judging_assignments.assignLocation',
            ]);

        $sessions = DB::table('judging_locations')->orderBy('id')->get(['id', 'judgingLocName']);

        $sheets = [];
        foreach ($sessions as $session) {
            $members = $assigned->filter(
                fn ($a): bool => (int) $a->assignLocation === (int) $session->id,
            )->map(static function ($a): array {
                return [
                    'name' => trim(($a->brewerLastName ?? '').', '.($a->brewerFirstName ?? '')),
                    'bjcpId' => strtoupper((string) $a->brewerJudgeID),
                    'waiver' => $a->brewerJudgeWaiver === 'Y' ? 'Yes' : 'No',
                ];
            })->values();
            if ($members->isNotEmpty()) {
                $sheets[] = ['session' => (string) $session->judgingLocName, 'members' => $members];
            }
        }

        // Blank sheet row count: signed-up count for the role, minimum 1
        // (legacy iterated $count from the role's participant count).
        $blankRows = max(1, DB::table('staff')->where($staffColumn, 1)->count());

        return StreamPdf::response('outputs.assignments-signin', [
            'contestName' => $ctx->contestStr('contestName'),
            'roleLabel' => $role === 'S' ? 'Steward' : 'Judge',
            'sheets' => $sheets,
            'blankRows' => $blankRows,
        ], 'assignments-sign-in.pdf');
    }

    /** Shared roster row shape for both orderings. */
    private function rowFormatter(): \Closure
    {
        return static function ($row): array {
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
        };
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
