<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
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
 *
 * (filter=staff) is NOT a roster: the dashboard "Staff Availability" row
 * links here with filter=staff and the legacy staff branch lists who
 * volunteered for non-judging sessions. See staffAvailabilityData().
 */
final class AssignmentsController extends Controller
{
    /** Legacy assignRoles codes → human labels. */
    private const ROLES = ['HJ' => 'Table Head Judge', 'LJ' => 'Lead Judge', 'MBOS' => 'Mini-BOS Judge'];

    private const CERTS = [
        'Master Cicerone', 'Advanced Cicerone', 'Certified Cicerone',
        'Professional Brewer', 'Certified Cider Guide', 'Certified Pommelier',
    ];

    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();

        // The dashboard's "Staff Availability" row (filter=staff) is a
        // different report, not a role of the roster — legacy branches on it
        // before the sign-in/roster split.
        if ($request->query('filter') === 'staff') {
            return $this->staffAvailability($ctx, (string) $request->query('view', ''));
        }

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
        $data = self::signInSheetsData($role);

        return StreamPdf::response('outputs.assignments-signin', [
            'contestName' => $ctx->contestStr('contestName'),
            'roleLabel' => $role === 'S' ? 'Steward' : 'Judge',
            'sheets' => $data['sheets'],
            'blankRows' => $data['blankRows'],
        ], 'assignments-sign-in.pdf');
    }

    /**
     * Data for the sign-in sheets: one sheet per judging session with its
     * assigned participants (name / BJCP ID / waiver), plus the blank-row
     * count for walk-ins.
     *
     * @return array{sheets: list<array{session: string, members: list<array{name: string, bjcpId: string, waiver: string}>}>, blankRows: int}
     */
    public static function signInSheetsData(string $role): array
    {
        $staffColumn = $role === 'S' ? 'staff_steward' : 'staff_judge';

        $assigned = DB::table('judging_assignments')
            ->leftJoin('judging_tables', 'judging_tables.id', '=', 'judging_assignments.assignTable')
            ->join('brewer', 'brewer.uid', '=', 'judging_assignments.bid')
            ->where('judging_assignments.assignment', $role)
            ->orderBy('brewer.brewerLastName')
            ->get([
                'brewer.brewerFirstName', 'brewer.brewerLastName',
                'brewer.brewerJudgeID', 'brewer.brewerJudgeWaiver',
                'judging_assignments.assignLocation',
                'judging_tables.tableLocation',
            ]);

        $sessions = DB::table('judging_locations')->orderBy('id')->get(['id', 'judgingLocName'])->keyBy('id');

        $bySession = [];
        $unmatched = [];
        foreach ($assigned as $a) {
            $member = [
                'name' => trim(($a->brewerLastName ?? '').', '.($a->brewerFirstName ?? '')),
                'bjcpId' => strtoupper((string) $a->brewerJudgeID),
                'waiver' => $a->brewerJudgeWaiver === 'Y' ? 'Yes' : 'No',
            ];

            // Prefer the assignment's own location; fall back to the table's
            // session when assignLocation is unset or dangles, so an
            // assignment is never silently dropped from the sheet.
            $sessionId = (int) $a->assignLocation;
            if (! $sessions->has($sessionId)) {
                $sessionId = (int) $a->tableLocation;
            }
            if ($sessions->has($sessionId)) {
                $bySession[$sessionId][] = $member;
            } else {
                $unmatched[] = $member;
            }
        }

        $sheets = [];
        foreach ($sessions as $session) {
            if (! empty($bySession[$session->id])) {
                $sheets[] = ['session' => (string) $session->judgingLocName, 'members' => $bySession[$session->id]];
            }
        }
        if ($unmatched !== []) {
            $sheets[] = ['session' => 'Unassigned', 'members' => $unmatched];
        }

        // Blank sheet row count: signed-up count for the role, minimum 1
        // (legacy iterated $count from the role's participant count).
        $blankRows = max(1, DB::table('staff')->where($staffColumn, 1)->count());

        return ['sheets' => $sheets, 'blankRows' => $blankRows];
    }

    /**
     * filter=staff (assignments.output.php staff branch): staff availability,
     * NOT an assignments roster. One row per brewer flagged brewerStaff=Y who
     * marked `Y-<id>` availability (brewer.brewerJudgeLocation) at a
     * non-judging session (judging_locations.judgingLocType=2).
     */
    private function staffAvailability(TenantContext $ctx, string $view): Response
    {
        return StreamPdf::response('outputs.assignments-staff', [
            'contestName' => $ctx->contestStr('contestName'),
            'rows' => self::staffAvailabilityData($view, $ctx),
        ], 'assignments-staff.pdf');
    }

    /**
     * Data for the staff availability report. view=name orders by person then
     * session; any other view orders by session then person — the legacy
     * DataTables `aaSorting` pair, so the dashboard's "By Last Name" and "By
     * Non-Judging Session" links are the SAME data set in two orderings (the
     * user-observed equivalence is intended; what was wrong is that
     * filter=staff fell through to the judge roster).
     *
     * @return list<array{name: string, email: string, session: string}>
     */
    public static function staffAvailabilityData(string $view, TenantContext $ctx): array
    {
        $sessions = [];
        foreach (DB::table('judging_locations')->where('judgingLocType', 2)->orderBy('id')
            ->get(['id', 'judgingLocName', 'judgingDate']) as $loc) {
            $sessions[(int) $loc->id] = $loc;
        }

        $tz = $ctx->prefsStr('prefsTimeZone');
        $dateFormat = $ctx->prefsStr('prefsDateFormat');
        $timeFormat = $ctx->prefsStr('prefsTimeFormat');

        $rows = [];
        foreach (DB::table('brewer')->where('brewerStaff', 'Y')
            ->get(['brewerFirstName', 'brewerLastName', 'brewerEmail', 'brewerJudgeLocation']) as $person) {
            foreach (explode(',', (string) $person->brewerJudgeLocation) as $mark) {
                if (preg_match('/^Y-(\d+)$/', trim($mark), $m) !== 1) {
                    continue;
                }
                $loc = $sessions[(int) $m[1]] ?? null;
                if ($loc === null) {
                    continue;
                }
                $rows[] = [
                    'name' => trim(($person->brewerLastName ?? '').', '.($person->brewerFirstName ?? '')),
                    'email' => (string) $person->brewerEmail,
                    // Legacy table_location(..., "known-id"): name + long
                    // date-time without the zone suffix.
                    'session' => $loc->judgingLocName.', '.(string) DateFmt::dateTime(
                        (int) $loc->judgingDate,
                        $tz,
                        $dateFormat,
                        $timeFormat,
                        'long',
                        false,
                    ),
                ];
            }
        }

        usort($rows, $view === 'name'
            ? static fn (array $a, array $b): int => [$a['name'], $a['session']] <=> [$b['name'], $b['session']]
            : static fn (array $a, array $b): int => [$a['session'], $a['name']] <=> [$b['session'], $b['name']]);

        return $rows;
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
