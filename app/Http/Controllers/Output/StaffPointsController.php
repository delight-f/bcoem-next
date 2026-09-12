<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * BJCP experience-points report for the organizer, judges, stewards and
 * staff (legacy output/staff_points.output.php +
 * includes/db/output_staff_points.db.php + lib/output.lib.php).
 *
 * Mirrored math:
 *  - total_points() role maxima from the BJCP schedule (output.lib.php:302);
 *    Staff above 599 entries: floor(entries/100)+3.
 *  - judge_points() (:361): 0.5/session, summed per calendar day and capped
 *    at 1.5/day; distributed sessions (judgingLocType=1) each get their own
 *    1.5 cap (issue #1483: round 1 assignments only); capped at the Judge
 *    maximum, then a flat 1.0 minimum — so every flagged judge shows ≥1.0.
 *  - steward_points() (:470): 0.5 per traditional-session day stewarded,
 *    1.0 competition maximum.
 *  - BOS judge points: +0.5 only when the competition has ≥30 entries AND
 *    ≥5 beer or ≥3 mead/cider categories; below the entry threshold the
 *    alert line prints (the style-count shortfall prints no alert, faithful
 *    to legacy). BOS-only judges (no other staff role, no judging
 *    assignment) display a flat 1.0 with a checkmark when eligible.
 *  - Staff pool: max pool if a single staffer, else rounded to 0.5,
 *    allocated down the alphabetical list while the running total lasts.
 *
 * Note: this is BJCP experience points, unrelated to best-brewer points
 * (ResultsRepository::bestBrewers / BestBrewerPoints) — nothing to reuse.
 */
final class StaffPointsController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        $entries = self::bjcpEntryCount();

        // Style-type tallies over the active set (styles ledger #5-#7
        // predicates); legacy compares brewStyleType against both names
        // ('Cider'/'Mead') and numeric codes ('2'/'3').
        $beer = $mead = $cider = 0;
        foreach ($this->activeStyles($ctx) as $style) {
            $type = (string) $style->brewStyleType;
            if ($type === 'Cider' || $type === '2') {
                $cider++;
            } elseif ($type === 'Mead' || $type === '3') {
                $mead++;
            } else {
                $beer++;
            }
        }

        $bosEligible = $entries >= 30 && ($beer >= 5 || $mead + $cider >= 3);
        $bosPoints = $entries >= 30 && $bosEligible ? 0.5 : 0.0;
        $bosAlert = $entries < 30
            ? 'No BOS Judge points were be awarded since the total number of entries judged was less than the minimum threshold of 30 set by the BJCP.'
            : '';

        $organMax = self::maxPoints($entries, 'Organizer');
        $staffMax = self::maxPoints($entries, 'Staff');
        $judgeMax = self::maxPoints($entries, 'Judge');

        // Staff point pool divided by staffer count, rounded to 0.5.
        $stafferCount = DB::table('staff')->where('staff_staff', '1')->count();
        $staffPool = match (true) {
            $stafferCount === 1 => $staffMax,
            $stafferCount >= 2 => round(($staffMax / $stafferCount) / 0.5) * 0.5,
            default => 0.0,
        };

        $organizerRow = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.staff_organizer', '1')
            ->orderBy('br.brewerLastName')
            ->first(['br.uid', 'br.brewerFirstName', 'br.brewerLastName', 'br.brewerJudgeID']);
        $organizerUid = $organizerRow === null ? null : (int) $organizerRow->uid;

        $name = fn (\stdClass $b): string => ucwords(strtolower((string) $b->brewerLastName)).', '
            .ucwords(strtolower((string) $b->brewerFirstName));

        $organizer = null;
        if ($organizerRow !== null && $organizerRow->brewerLastName !== null) {
            $organizer = [
                'name' => $name($organizerRow),
                'bjcpId' => self::bjcpId($organizerRow->brewerJudgeID),
                'points' => number_format($organMax, 1),
            ];
        }

        // ── Judges ──────────────────────────────────────────────────────
        $judgeUids = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.staff_judge', '1')
            ->orderBy('br.brewerLastName')
            ->pluck('br.uid')
            ->unique()
            ->all();

        $judges = [];
        foreach ($judgeUids as $uid) {
            $brewer = DB::table('brewer')->where('uid', $uid)->first([
                'uid', 'brewerFirstName', 'brewerLastName', 'brewerJudgeID',
            ]);

            if ($brewer === null || $brewer->brewerLastName === null || $brewer->brewerLastName === '') {
                continue;
            }

            $points = $this->judgePoints((int) $uid, $judgeMax, $ctx);
            if ($points <= 0.0) {
                continue;
            }

            $isBos = (int) (DB::table('staff')->where('uid', $uid)->value('staff_judge_bos') ?? 0) === 1;

            $judges[] = [
                'name' => $name($brewer),
                'bjcpId' => self::bjcpId($brewer->brewerJudgeID),
                'points' => $organizerUid === (int) $uid
                    ? '0.0 (Organizer)'
                    : number_format($isBos ? $points + $bosPoints : $points, 1),
                'bos' => $isBos,
            ];
        }

        // BOS judges not otherwise assigned a judging role: flat 1.0 +
        // checkmark when eligible (legacy hardcodes 1.0 there).
        // Legacy's two queries: BOS judges with another staff role (kept only
        // when they have no judging assignment) and BOS judges with no other
        // role at all.
        $multiRoleBosUids = DB::table('staff')->where('staff_judge_bos', '1')->where(function ($q): void {
            $q->where('staff_judge', '1')->orWhere('staff_steward', '1')->orWhere('staff_staff', '1');
        })->pluck('uid');

        $bosOnlyUids = collect();
        foreach ($multiRoleBosUids as $uid) {
            if (! DB::table('judging_assignments')->where('bid', $uid)->exists()) {
                $bosOnlyUids->push($uid);
            }
        }

        foreach (DB::table('staff')->where('staff_judge_bos', '1')->where('staff_judge', '0')->where('staff_steward', '0')->where('staff_staff', '0')->pluck('uid') as $uid) {
            $bosOnlyUids->push($uid);
        }

        foreach ($bosOnlyUids->unique()->values() as $uid) {
            if (! $bosEligible || in_array($uid, $judgeUids, true)) {
                continue;
            }

            $brewer = DB::table('brewer')->where('uid', $uid)->first([
                'uid', 'brewerFirstName', 'brewerLastName', 'brewerJudgeID',
            ]);

            if ($brewer === null || $brewer->brewerLastName === null || $brewer->brewerLastName === '') {
                continue;
            }

            $judges[] = [
                'name' => $name($brewer),
                'bjcpId' => self::bjcpId($brewer->brewerJudgeID),
                'points' => $organizerUid === (int) $uid ? '0.0 (Organizer)' : '1.0',
                'bos' => true,
            ];
        }

        // ── Stewards ────────────────────────────────────────────────────
        $stewards = [];
        $stewardUids = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.staff_steward', '1')
            ->orderBy('br.brewerLastName')
            ->pluck('br.uid')
            ->unique()
            ->all();

        foreach ($stewardUids as $uid) {
            $points = $this->stewardPoints((int) $uid, $ctx);
            if ($points <= 0.0) {
                continue;
            }

            $brewer = DB::table('brewer')->where('uid', $uid)->first([
                'uid', 'brewerFirstName', 'brewerLastName', 'brewerJudgeID',
            ]);

            if ($brewer === null || $brewer->brewerLastName === null || $brewer->brewerLastName === '') {
                continue;
            }

            // Legacy shows steward IDs without validation, unlike the other roles.
            $bjcpId = $brewer->brewerJudgeID !== null && $brewer->brewerJudgeID !== ''
                ? strtoupper(strtr((string) $brewer->brewerJudgeID, self::BJCP_NUM_REPLACE))
                : null;

            $stewards[] = [
                'name' => $name($brewer),
                'bjcpId' => $bjcpId,
                'points' => $organizerUid === (int) $uid
                    ? '0.0 (Organizer)'
                    : number_format($points, 1),
            ];
        }

        // ── Staff ───────────────────────────────────────────────────────
        $staff = [];
        $runningTotal = 0.0;
        $staffUids = DB::table('staff as s')->join('brewer as br', 's.uid', '=', 'br.uid')->where('s.staff_staff', '1')->orderBy('br.brewerLastName')->pluck('br.uid')->unique()->all();

        foreach ($staffUids as $uid) {
            $brewer = DB::table('brewer')->where('uid', $uid)->first([
                'uid', 'brewerFirstName', 'brewerLastName', 'brewerJudgeID',
            ]);

            if ($brewer === null || $brewer->brewerLastName === null || $brewer->brewerLastName === '') {
                continue;
            }

            if ($runningTotal > $staffMax) {
                break;
            }

            $staff[] = [
                'name' => $name($brewer),
                'bjcpId' => self::bjcpId($brewer->brewerJudgeID),
                'points' => $organizerUid === (int) $uid
                    ? '0.0 (Organizer)'
                    : number_format($staffPool < $organMax ? $staffPool : $organMax, 1),
            ];

            $runningTotal += $staffPool;
        }

        return StreamPdf::response('outputs.staff-points', [
            'contestName' => $ctx->contestStr('contestName') ?? '',
            'compId' => $ctx->contestStr('contestID') ?? '',
            'entries' => $entries,
            'days' => $this->totalDays($ctx),
            'sessions' => $this->totalSessions(),
            'flights' => $this->totalFlights(),
            'bosAlert' => $bosAlert,
            'organizer' => $organizer,
            'judges' => $judges,
            'stewards' => $stewards,
            'staff' => $staff,
            'staffMax' => number_format($staffMax, 1),
            'staffOverflow' => $runningTotal > $staffMax,
        ], 'staff-points.pdf');
    }

    /**
     * get_bjcp_entry_count() (upstream v3.1.0 lib/common.lib.php:2733, added
     * by "BJCP Reporting Adjustments", commit ec5360214): judged entries take
     * precedence whenever any exist, else received, else paid, else every
     * entry on record. Replaces 3.0.3's received-first rule
     * (`if ($total_entries_scored > $total_entries_received)`, which reported
     * received whenever it exceeded the judged count).
     *
     * Public so the precedence is testable at its root; both consumers in this
     * controller (the printed "Entries:" figure and the 30-entry BOS gate) read
     * this single seam.
     */
    public static function bjcpEntryCount(): int
    {
        return DB::table('judging_scores')->count()
            ?: DB::table('brewing')->where('brewReceived', '1')->count()
            ?: DB::table('brewing')->where('brewPaid', '1')->count()
            ?: DB::table('brewing')->count();
    }

    /** Active-set styles (version predicates + customs), styles ledger #5-#7. Ordered like legacy's default branch.
     *
     * @return list<\stdClass>
     */
    private function activeStyles(TenantContext $ctx): array
    {
        $set = $ctx->prefsStr('prefsStyleSet');
        $query = DB::table('styles')->where(function ($q) use ($set): void {
            if ($set === 'BJCP2025') {
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2021')->where('brewStyleType', '!=', '2');
                });
            } elseif ($set === 'AABC2025') {
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2022')->where('brewStyleType', '!=', '2');
                });
            } else {
                $q->where('brewStyleVersion', $set);
            }
            $q->orWhere('brewStyleOwn', 'custom');
        });

        return array_values($query->orderBy('brewStyleType')->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get()->all());
    }

    /**
     * total_points() (output.lib.php:302) — BJCP Table 1 role maxima.
     */
    private static function maxPoints(int $entries, string $role): float
    {
        return match ($role) {
            'Organizer' => match (true) {
                $entries < 1 => 0.0,
                $entries <= 49 => 2.0,
                $entries <= 99 => 2.5,
                $entries <= 149 => 3.0,
                $entries <= 199 => 3.5,
                $entries <= 299 => 4.0,
                $entries <= 399 => 4.5,
                $entries <= 499 => 5.0,
                default => 6.0,
            },
            'Staff' => match (true) {
                $entries < 1 => 0.0,
                $entries <= 49 => 1.0,
                $entries <= 99 => 2.0,
                $entries <= 149 => 3.0,
                $entries <= 199 => 4.0,
                $entries <= 299 => 5.0,
                $entries <= 399 => 6.0,
                $entries <= 499 => 7.0,
                $entries <= 599 => 8.0,
                // Above 599: floor(entries/100)+3 (round_down_to_hundred loop).
                default => intdiv($entries, 100) + 3.0,
            },
            default => match (true) { // Judge
                $entries < 1 => 0.0,
                $entries <= 49 => 1.5,
                $entries <= 99 => 2.0,
                $entries <= 149 => 2.5,
                $entries <= 199 => 3.0,
                $entries <= 299 => 3.5,
                $entries <= 399 => 4.0,
                $entries <= 499 => 4.5,
                default => 5.5,
            },
        };
    }

    private const BJCP_NUM_REPLACE = ['(' => '9', ')' => '0', 'o' => '0', 'O' => '0', '-' => ''];

    /** validate_bjcp_id(): TEMP#### ids or letter+4 digits after normalization. */
    private static function bjcpId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $clean = strtoupper(strtr($id, self::BJCP_NUM_REPLACE));
        $valid = preg_match('/^TEMP\d{4}$/i', $clean) === 1
            || (strlen($clean) === 5 && preg_match('/[a-zA-Z]/', $clean) === 1);

        return $valid ? $clean : null;
    }

    /** Day bucket key for a session epoch, in the tenant's configured offset. Divergence from legacy's judge_points(), which used server-local date(); total_days() already used tenant tz, so both are normalized here. */
    private static function dayKey(\stdClass $location, TenantContext $ctx): string
    {
        return (string) DateFmt::date($location->judgingDate, $ctx->prefsStr('prefsTimeZone'), 999, 'system');
    }

    /**
     * judge_points() — see class docblock.
     */
    private function judgePoints(int $uid, float $judgeMax, TenantContext $ctx): float
    {
        $dailyTotals = [];
        $distributedTotals = [];

        foreach ($this->locations($ctx, '<', 2) as $location) {
            $sessions = DB::table('judging_assignments')
                ->where('bid', $uid)
                ->where('assignLocation', $location->id)
                ->where('assignment', 'J')
                ->where('assignRound', '<=', '1')
                ->count();

            if ($sessions === 0) {
                continue;
            }

            $points = $sessions * 0.5;

            if ((int) $location->judgingLocType === 1) {
                $distributedTotals[] = $points;
            } else {
                $key = self::dayKey($location, $ctx);
                $dailyTotals[$key] = ($dailyTotals[$key] ?? 0.0) + $points;
            }
        }

        $points = 0.0;
        foreach ($dailyTotals as $dayTotal) {
            $points += min(1.5, $dayTotal);
        }
        foreach ($distributedTotals as $sessionTotal) {
            $points += min(1.5, $sessionTotal);
        }

        $points = min($points, $judgeMax);

        // Flat 1.0 minimum applies even with zero assignments (legacy :462).
        return max($points, 1.0);
    }

    /**
     * steward_points() — traditional sessions only (judgingLocType < 1),
     * 0.5 per day stewarded, 1.0 competition cap.
     */
    private function stewardPoints(int $uid, TenantContext $ctx): float
    {
        $possibleDays = [];
        $stewardedDays = [];

        foreach ($this->locations($ctx, '<', 1) as $location) {
            $key = self::dayKey($location, $ctx);
            $possibleDays[$key] = true;

            $stewarded = DB::table('judging_assignments')
                ->where('bid', $uid)
                ->where('assignLocation', $location->id)
                ->where('assignment', 'S')
                ->where('assignRound', '<=', '1')
                ->count();

            if ($stewarded > 0) {
                $stewardedDays[$key] = true;
            }
        }

        if ($stewardedDays === []) {
            return 0.0;
        }

        $points = count(array_intersect_key($possibleDays, $stewardedDays)) * 0.5;

        return min($points, 1.0);
    }

    /**
     * @return list<\stdClass>
     */
    private function locations(TenantContext $ctx, string $op, int $type): array
    {
        return array_values(
            DB::table('judging_locations')
                ->where('judgingLocType', $op, $type)
                ->get(['id', 'judgingLocType', 'judgingDate'])
                ->all()
        );
    }

    private function totalDays(TenantContext $ctx): int
    {
        $days = [];

        foreach ($this->locations($ctx, '<', 2) as $location) {
            $days[self::dayKey($location, $ctx)] = true;
        }

        return count($days);
    }

    private function totalSessions(): int
    {
        return DB::table('judging_locations')->where('judgingLocType', '<', 2)->count();
    }

    /** total_flights(): highest flight number per table, plus one round per BOS-eligible style type. */
    private function totalFlights(): int
    {
        $flights = 0;

        foreach (DB::table('judging_flights')
            ->groupBy('flightTable')
            ->selectRaw('MAX(flightNumber) as max_flight')
            ->get() as $row) {
            $flights += (int) $row->max_flight;
        }

        return $flights + DB::table('style_types')->where('styleTypeBOS', 'Y')->count();
    }
}
