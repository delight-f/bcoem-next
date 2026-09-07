<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Participant → pool role assignment (judge / steward / staff / BOS judge).
 * Legacy: admin/judging_locations.admin.php action=assign block
 * (judging_locations.admin.php:156-431) driven by
 * ?section=admin&go=judging&action=assign&filter={judges|stewards|staff|bos}.
 *
 * The port exposes it as GET /admin/judging/pool-assign?filter={...} — a
 * read/write sibling of the read-only /backoffice/participants list. Each
 * checkbox toggles one staff-column flag (staff_judge / staff_steward /
 * staff_staff / staff_judge_bos) via POST /admin/judging/pool-assign/staff,
 * replicating ajax/save.ajax.php action=judging_staff (lines 174-311):
 *
 *   - no staff row for the uid  -> INSERT one (all columns)
 *   - row exists                -> UPDATE just that column
 *   - judge/steward turned OFF  -> also DELETE that person's
 *                                  judging_assignments rows (J/S)
 *   - staff_organizer           -> clear every staff row's organizer flag,
 *                                  then set (or insert) the chosen uid with
 *                                  its other four flags zeroed
 *
 * The per-table AssignController (judge/steward -> table/flight rows) is a
 * separate screen and is untouched.
 */
final class PoolAssignController extends Controller
{
    /** Roster filters — all other values render the judges pool. */
    private const FILTERS = ['judges', 'stewards', 'staff', 'bos'];

    /** Staff columns reachable from the toggle endpoint (save.ajax.php). */
    private const STAFF_COLUMNS = [
        'staff_judge',
        'staff_steward',
        'staff_staff',
        'staff_judge_bos',
    ];

    /** The five staff flags, defaulted to 0 for brewers without a row. */
    private const STAFF_FLAGS = [
        'staff_judge' => 0,
        'staff_judge_bos' => 0,
        'staff_steward' => 0,
        'staff_organizer' => 0,
        'staff_staff' => 0,
    ];

    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $filter = (string) $request->query('filter', 'judges');
        if (! in_array($filter, self::FILTERS, true)) {
            $filter = 'judges';
        }
        // view=yes (staff: interested only) / view=ranked (bos: ranked
        // judges only) — preserved for the toggle links on the screen.
        $view = (string) $request->query('view', 'default');

        $staffColumn = match ($filter) {
            'judges' => 'staff_judge',
            'stewards' => 'staff_steward',
            'staff' => 'staff_staff',
            'bos' => 'staff_judge_bos',
        };

        $query = DB::table('brewer');
        $query = match ($filter) {
            // Legacy roster queries (includes/db/brewer.db.php:158-213,
            // non-SINGLE branches).
            'judges' => $query->where('brewer.brewerJudge', 'Y'),
            'stewards' => $query->where('brewer.brewerSteward', 'Y'),
            'bos' => $view === 'ranked'
                ? $query->whereRaw("(brewerJudgeRank LIKE 'Recognized%' OR brewerJudgeRank LIKE 'Certified%' OR brewerJudgeRank LIKE 'National%' OR brewerJudgeRank LIKE 'Master%' OR brewerJudgeRank LIKE '%Cicerone' OR brewerJudgeRank LIKE 'Grand%' OR brewerJudgeMead='Y' OR brewerJudgeCider='Y')")
                    ->where('brewer.brewerJudge', 'Y')
                : $query->where('brewer.brewerJudge', 'Y'),
            // staff: ALL brewers (+ interested-only when view=yes).
            default => $view === 'yes'
                ? $query->where('brewer.brewerStaff', 'Y')
                : $query,
        };

        $participants = $query
            ->orderBy('brewer.brewerLastName')->orderBy('brewer.brewerFirstName')
            ->get();

        $uids = $participants->pluck('uid')->map(intval(...))->all();

        // Current staff flags, one row per participant (null => no row).
        $staffRows = $uids === []
            ? collect()
            : DB::table('staff')->whereIn('uid', $uids)->get()->keyBy('uid');

        // Location availability display (judging_locations.admin.php
        // 289-349): judgingLocName resolved from brewerJudgeLocation /
        // brewerStewardLocation "Y-<id>" CSV; the staff filter shows only
        // non-judging locations (judgingLocType=2).
        $locations = DB::table('judging_locations')
            ->when($filter === 'staff', fn ($q) => $q->where('judgingLocType', 2))
            ->get(['id', 'judgingLocName'])
            ->keyBy('id');

        // Entry counts per participant (dashboard participant-stat shape).
        $entryCounts = $uids === []
            ? collect()
            : DB::table('brewing')
                ->whereIn('brewBrewerID', $uids)
                ->selectRaw('brewBrewerID, COUNT(*) AS n')
                ->groupBy('brewBrewerID')
                ->pluck('n', 'brewBrewerID');

        // BOS eligibility: participant's placed scores (bos_judge_eligible,
        // admin.lib.php:782-807) — scorePlace rows against their brewing
        // entries, ordered by table.
        $placingRows = $uids === []
            ? collect()
            : DB::table('judging_scores as js')
                ->join('brewing as b', 'b.id', '=', 'js.eid')
                ->whereIn('b.brewBrewerID', $uids)
                ->whereNotNull('js.scorePlace')
                ->orderBy('js.scoreTable')
                ->get(['b.brewBrewerID', 'js.scorePlace', 'js.scoreTable'])
                ->groupBy('b.brewBrewerID');

        $rows = [];
        $checkedEmails = [];

        foreach ($participants as $p) {
            $uid = (int) $p->uid;
            $staff = $staffRows->get($uid);
            $flags = self::STAFF_FLAGS;
            foreach ($flags as $col => $default) {
                $flags[$col] = (int) ($staff->{$col} ?? $default);
            }
            $checked = $flags[$staffColumn] === 1;
            $assignmentLabel = self::assignmentLabel($flags);

            // Copy-paste email lists for the per-filter email modal:
            // checked participants whose label contains the filter role.
            if ($checked) {
                $inPool = $filter === 'judges' && str_contains($assignmentLabel, 'Judge')
                    || $filter === 'stewards' && str_contains($assignmentLabel, 'Steward')
                    || $filter === 'staff' && str_contains($assignmentLabel, 'Staff')
                    || $filter === 'bos' && str_contains($assignmentLabel, 'BOS');
                if ($inPool) {
                    $checkedEmails[] = (string) $p->brewerEmail;
                }
            }

            // Location availability: explode the "Y-<id>" CSV for the role
            // that applies to this filter (judges/staff read
            // brewerJudgeLocation, stewards brewerStewardLocation).
            $locationCsv = in_array($filter, ['judges', 'staff'], true)
                ? (string) ($p->brewerJudgeLocation ?? '')
                : (string) ($p->brewerStewardLocation ?? '');
            $preferenceNames = [];
            foreach (explode(',', $locationCsv) as $flag) {
                if (str_starts_with($flag, 'Y-')) {
                    $locId = (int) substr($flag, 2);
                    $name = (string) ($locations[$locId]->judgingLocName ?? '');
                    if ($name !== '') {
                        $preferenceNames[] = $name;
                    }
                }
            }
            sort($preferenceNames);

            // Placing entries for the BOS filter (display_place method 1).
            $placingText = '';
            foreach ($placingRows[$uid] ?? [] as $placing) {
                $display = self::displayPlace((string) $placing->scorePlace);
                if ($display !== 'N/A') {
                    $placingText .= $display.': Table '.$placing->scoreTable.', ';
                }
            }

            $rows[] = [
                'uid' => $uid,
                'name' => trim((string) $p->brewerLastName.', '.(string) $p->brewerFirstName),
                'firstName' => (string) $p->brewerFirstName,
                'email' => (string) $p->brewerEmail,
                'flags' => $flags,
                'checked' => $checked,
                'assignmentLabel' => $assignmentLabel,
                // Organizer's row is locked on the staff screen.
                'isOrganizer' => $flags['staff_organizer'] === 1,
                // Cross-role locks (judging_locations.admin.php:362-364):
                // a steward cannot also be a judge and vice versa.
                'disabled' => $filter === 'staff' && $flags['staff_organizer'] === 1
                    || $filter === 'stewards' && $flags['staff_judge'] === 1
                    || $filter === 'judges' && $flags['staff_steward'] === 1,
                // "Has Entries In..." (judge_entries, common.lib.php:3463).
                'entryCount' => (int) ($entryCounts[$uid] ?? 0),
                'judgeId' => (string) ($p->brewerJudgeID ?? ''),
                'rankDisplay' => self::rankDisplay((string) ($p->brewerJudgeRank ?? ''), $p),
                'preferences' => implode('<br>', $preferenceNames),
                'placingEntries' => rtrim($placingText, ', '),
                'hasPlacingEntries' => $placingText !== '',
            ];
        }

        // All brewers for the staff organizer dropdown (judging_locations
        // .db.php:100-102), ordered like the legacy select.
        $allBrewers = DB::table('brewer')
            ->orderBy('brewerLastName')->orderBy('brewerFirstName')
            ->get(['uid', 'brewerFirstName', 'brewerLastName']);
        $organizerUid = (int) (DB::table('staff')->where('staff_organizer', 1)->value('uid') ?? 0);

        return view('judging.pool_assign', [
            'ctx' => $ctx,
            'filter' => $filter,
            'view' => $view,
            'staffColumn' => $staffColumn,
            'rows' => $rows,
            'checkedEmails' => $checkedEmails,
            'allBrewers' => $allBrewers,
            'organizerUid' => $organizerUid,
        ]);
    }

    /**
     * POST /admin/judging/pool-assign/staff — the pool checkbox/organizer
     * save (legacy ajax/save.ajax.php action=judging_staff, lines 174-311).
     */
    public function toggle(Request $request): JsonResponse
    {
        $status = 0;
        $errorType = 0;

        $user = $request->user();

        if (! ($user instanceof User)) {
            $status = 9; // no session (save.ajax.php envelope)
        } elseif ((string) $request->input('action', 'default') !== 'judging_staff'
            || (int) $user->userLevel > 1
        ) {
            $status = 0; // non-admin / unknown action: silent no-op
        } else {
            $go = (string) $request->input('go', 'default');
            $uid = (int) $request->input('id', 0);

            if ($go === 'staff_organizer') {
                [$status, $errorType] = $this->toggleOrganizer((int) $request->input('staff_organizer', 0));
            } elseif (in_array($go, self::STAFF_COLUMNS, true) && $uid > 0) {
                [$status, $errorType] = $this->toggleStaffColumn($uid, $go, (string) $request->input($go, ''));
            } else {
                $errorType = 3; // unknown column (legacy failed the query)
            }
        }

        return response()->json([
            'status' => (string) $status,
            'query' => '', // legacy save.ajax.php envelope fields
            'post' => '0',
            'input' => '',
            'id' => (string) $request->input('id', 'default'),
            'error_type' => (string) $errorType,
        ]);
    }

    /**
     * Set/clear a single staff-flag column for a uid; when a judge/steward
     * flag is turned OFF also delete the person's table assignments.
     *
     * @return array{0: int, 1: int} [status, error_type]
     */
    private function toggleStaffColumn(int $uid, string $go, string $raw): array
    {
        $value = ($raw === '' || (int) $raw === 0) ? 0 : 1;

        try {
            $row = DB::table('staff')->where('uid', $uid)->first();

            if ($row === null) {
                DB::table('staff')->insert([
                    'uid' => $uid,
                    'staff_judge' => 0,
                    'staff_judge_bos' => 0,
                    'staff_steward' => 0,
                    'staff_organizer' => 0,
                    'staff_staff' => 0,
                ]);
            }

            DB::table('staff')->where('uid', $uid)->update([$go => $value]);

            if (($go === 'staff_judge' || $go === 'staff_steward') && $value === 0) {
                DB::table('judging_assignments')
                    ->where('bid', $uid)
                    ->where('assignment', $go === 'staff_judge' ? 'J' : 'S')
                    ->delete();
            }
        } catch (\Throwable) {
            return [0, 3]; // SQL error
        }

        return [1, 0];
    }

    /**
     * Designate the competition organizer: clear every staff row's
     * staff_organizer, then set the chosen uid (inserting a fresh row with
     * the other four flags zeroed when none exists yet).
     *
     * @return array{0: int, 1: int} [status, error_type]
     */
    private function toggleOrganizer(int $uid): array
    {
        if ($uid <= 0 || ! DB::table('brewer')->where('uid', $uid)->exists()) {
            return [0, 3];
        }

        try {
            DB::transaction(function () use ($uid): void {
                DB::table('staff')->update(['staff_organizer' => 0]);

                $row = DB::table('staff')->where('uid', $uid)->first();
                $data = [
                    'staff_organizer' => 1,
                    'staff_staff' => 0,
                    'staff_judge' => 0,
                    'staff_judge_bos' => 0,
                    'staff_steward' => 0,
                ];
                if ($row === null) {
                    $data['uid'] = $uid;
                    DB::table('staff')->insert($data);
                } else {
                    DB::table('staff')->where('uid', $uid)->update($data);
                }
            });
        } catch (\Throwable) {
            return [0, 3];
        }

        return [1, 0];
    }

    /**
     * display_place(place, 1) (common.lib.php:2669-2678): 1st-4th ordinal,
     * "HM" for 5/HM, "N/A" otherwise.
     */
    private static function displayPlace(string $place): string
    {
        return match ($place) {
            '1', '2', '3', '4' => self::ordinal((int) $place),
            '5', 'HM' => 'HM',
            default => 'N/A',
        };
    }

    private static function ordinal(int $n): string
    {
        $suffix = match ($n % 100) {
            11, 12, 13 => 'th',
            default => match ($n % 10) {
                1 => 'st', 2 => 'nd', 3 => 'rd',
                default => 'th',
            },
        };

        return $n.$suffix;
    }

    /**
     * Legacy bjcp_rank(rank, 1) + designations + the mead/cider override
     * (judging_locations.admin.php:391-415).
     */
    private static function rankDisplay(string $rankCsv, \stdClass $brewer): string
    {
        $ranks = array_values(array_filter(array_map('trim', explode(',', $rankCsv))));
        $primary = $ranks[0] ?? '';

        $level = match ($primary) {
            'Experienced' => 'Level 0:',
            'Apprentice', 'Provisional', 'Rank Pending' => 'Level 1:',
            'Recognized', 'Professional Brewer', 'Beer Sommelier', 'Judge with Sensory Training' => 'Level 2:',
            'Certified', 'Certified Cider Guide', 'Mead Judge', 'Cider Judge' => 'Level 3:',
            'National', 'Certified Cicerone', 'Certified Pommelier' => 'Level 4:',
            'Master', 'Honorary Master', 'Master Cicerone' => 'Level 5:',
            'Grand Master', 'Honorary Grand Master' => 'Level 6:',
            default => 'Level 0:',
        };

        $display = ($primary === '' || $primary === 'None')
            ? $level.' Non-BJCP Judge'
            : $level.' '.$primary;

        // Level 0 with mead/cider certification -> Certified Cider or Mead
        // Judge (judging_locations.admin.php:396).
        if (str_contains($display, 'Level 0:')
            && (($brewer->brewerJudgeMead ?? 'N') === 'Y' || ($brewer->brewerJudgeCider ?? 'N') === 'Y')
        ) {
            $display = 'Level 3: Certified Cider or Mead Judge';
        }

        if (($brewer->brewerJudgeMead ?? 'N') === 'Y') {
            $display .= '<br><em>Certified Mead Judge</em>';
        }
        if (($brewer->brewerJudgeCider ?? 'N') === 'Y') {
            $display .= '<br><em>Certified Cider Judge</em>';
        }

        // designations() (common.lib.php:223-230): remaining comma ranks.
        foreach ($ranks as $i => $rank) {
            if ($i !== 0 && $rank !== '' && $rank !== $primary) {
                $display .= '<br>'.$rank;
            }
        }

        return $display;
    }

    /**
     * Legacy brewer_assignment(uid, 1) label (common.lib.php:2854-2861):
     * Organizer, BOS, Judge, Steward, Staff in that order.
     *
     * @param  array<string, int>  $flags
     */
    private static function assignmentLabel(array $flags): string
    {
        $r = [];
        if ($flags['staff_organizer'] === 1) {
            $r[] = 'Organizer';
        }
        if ($flags['staff_judge_bos'] === 1) {
            $r[] = 'BOS';
        }
        if ($flags['staff_judge'] === 1) {
            $r[] = 'Judge';
        }
        if ($flags['staff_steward'] === 1) {
            $r[] = 'Steward';
        }
        if ($flags['staff_staff'] === 1) {
            $r[] = 'Staff';
        }

        return implode(', ', $r);
    }
}
