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
 * Divergences from the legacy oracle, adopted from the official Experience
 * Point Award Schedule (issue #25 review):
 *  - Non-scoring style types (wine, rice wine, spirits, kombucha, pulque) are
 *    dropped from the entry count, the category tallies and the BOS gate —
 *    the wine position statement excludes them from the organizer report and
 *    the point structure.
 *  - A participant flagged as both judge and steward earns judge points only.
 *  - A person's combined total is capped at the Organizer maximum; judging
 *    (including BOS) is protected, then the formula-derived steward award,
 *    then the discretionary staff pool.
 *  - The BOS bonus follows captured panel membership with the official
 *    per-panel judge cap; the legacy global staff_judge_bos flag is only the
 *    fallback for competitions with no captured panels.
 *
 * Note: this is BJCP experience points, unrelated to best-brewer points
 * (ResultsRepository::bestBrewers / BestBrewerPoints) — nothing to reuse.
 *
 * `?view=xml` renders the BJCP XML OrgReport instead (see xmlReport()) — the
 * same export-staff section of legacy output/export.output.php, which the
 * legacy dashboard linked as the "XML" item under BJCP Points.
 */
final class StaffPointsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();

        if ((string) $request->query('view', '') === 'xml') {
            return $this->xmlReport($ctx, $request);
        }

        // The dashboard's three links: Print (no params) stays an inline PDF;
        // PDF (?action=download&view=pdf) is an attachment (D1-06).
        $download = $request->query('action') === 'download' || $request->query('view') === 'pdf';

        $entries = self::bjcpEntryCount();

        // Style-type tallies over the active set (styles ledger #5-#7
        // predicates); legacy compares brewStyleType against both names
        // ('Cider'/'Mead') and numeric codes ('2'/'3').
        [$beer, $mead, $cider] = $this->styleTallies($ctx);

        $bosEligible = $entries >= 30 && ($beer >= 5 || $mead + $cider >= 3);
        $bosPoints = $entries >= 30 && $bosEligible ? 0.5 : 0.0;
        $bosAlert = $entries < 30 ? self::BOS_ALERT : '';

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
        $judgeUids = self::roleUids('staff_judge');
        $bosUids = $this->bosJudgeUids();
        $bosNoAssignment = self::bosUidsWithoutAssignment($bosUids);
        $isBosJudge = static fn (int $uid): bool => in_array($uid, $bosUids, true);

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

            $isBos = $isBosJudge((int) $uid);

            $judges[] = [
                'name' => $name($brewer),
                'bjcpId' => self::bjcpId($brewer->brewerJudgeID),
                'points' => $organizerUid === (int) $uid
                    ? '0.0 (Organizer)'
                    : number_format($isBos ? $points + $bosPoints : $points, 1),
                'bos' => $isBos,
            ];
        }

        // BOS judges with no regular judging assignment: flat 1.0 +
        // checkmark when the entry/category counts qualify (legacy hardcodes
        // 1.0 there).
        foreach ($bosNoAssignment as $uid) {
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
        $stewardUids = self::stewardUids($judgeUids);

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
        ], 'staff-points.pdf', $download);
    }

    /**
     * get_bjcp_entry_count() (upstream v3.1.0 lib/common.lib.php:2733, added
     * by "BJCP Reporting Adjustments", commit ec5360214): judged entries take
     * precedence whenever any exist, else received, else paid, else every
     * entry on record. Replaces 3.0.3's received-first rule
     * (`if ($total_entries_scored > $total_entries_received)`, which reported
     * received whenever it exceeded the judged count).
     *
     * Divergence from upstream (issue #25 review): wine, rice wine, spirits,
     * kombucha and pulque entries are excluded. The BJCP position statement
     * says their number "should not be included in the organizer report or
     * used to compute point structure for organizer or staff or stewards or
     * the number of Best of Show judges". Unknown/NULL style types still
     * count, so legacy rows and the upstream precedence contract hold.
     *
     * Public so the precedence is testable at its root; both consumers in this
     * controller (the printed "Entries:" figure and the 30-entry BOS gate) read
     * this single seam.
     */
    public static function bjcpEntryCount(): int
    {
        $excluded = self::nonScoringStyleTypeIds();

        $judged = DB::table('judging_scores as js')
            ->leftJoin('brewing as b', 'js.eid', '=', 'b.id')
            ->where(self::scoringScope('b.brewStyleType', $excluded))
            ->count();

        if ($judged > 0) {
            return $judged;
        }

        $received = DB::table('brewing')
            ->where('brewReceived', '1')
            ->where(self::scoringScope('brewStyleType', $excluded))
            ->count();

        if ($received > 0) {
            return $received;
        }

        $paid = DB::table('brewing')
            ->where('brewPaid', '1')
            ->where(self::scoringScope('brewStyleType', $excluded))
            ->count();

        if ($paid > 0) {
            return $paid;
        }

        return DB::table('brewing')->where(self::scoringScope('brewStyleType', $excluded))->count();
    }

    /** legacy $output_text_034, shared by the PDF and XML reports. */
    private const BOS_ALERT = 'No BOS Judge points were be awarded since the total number of entries judged was less than the minimum threshold of 30 set by the BJCP.';

    /**
     * style_types that earn no BJCP points (the wine position statement).
     * Matched by name so a reseeded or renamed set still resolves.
     *
     * @var list<string>
     */
    private const NON_SCORING_STYLE_TYPES = ['Wine', 'Rice Wine', 'Spirits', 'Kombucha', 'Pulque'];

    /** @return list<int> */
    private static function nonScoringStyleTypeIds(): array
    {
        return array_values(
            DB::table('style_types')
                ->whereIn('styleTypeName', self::NON_SCORING_STYLE_TYPES)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
    }

    /**
     * "keep rows whose style type earns points" where-clause: everything
     * except the known non-scoring types, with NULL/unknown kept.
     *
     * @param  list<int>  $excluded
     * @return \Closure(Builder): void
     */
    private static function scoringScope(string $column, array $excluded): \Closure
    {
        return function (Builder $query) use ($column, $excluded): void {
            if ($excluded === []) {
                return;
            }

            $query->whereNotIn($column, $excluded)->orWhereNull($column);
        };
    }

    /**
     * Active-set style-type counts. Non-scoring types (wine et al.) are
     * dropped rather than folded into the beer tally.
     *
     * @return array{0: int, 1: int, 2: int} [beer, mead, cider]
     */
    private function styleTallies(TenantContext $ctx): array
    {
        $excludedIds = self::nonScoringStyleTypeIds();
        $excludedNames = array_map(strtolower(...), self::NON_SCORING_STYLE_TYPES);

        $beer = $mead = $cider = 0;
        foreach ($this->activeStyles($ctx) as $style) {
            $type = (string) $style->brewStyleType;

            if (in_array((int) $type, $excludedIds, true) || in_array(strtolower($type), $excludedNames, true)) {
                continue;
            }

            if ($type === 'Cider' || $type === '2') {
                $cider++;
            } elseif ($type === 'Mead' || $type === '3') {
                $mead++;
            } else {
                $beer++;
            }
        }

        return [$beer, $mead, $cider];
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

    /**
     * BJCP XML OrgReport — output/export.output.php's export-staff section,
     * `view=xml` (BJCP Database XML Interface Spec 2.1), the artifact
     * organizers submit at https://app.bjcp.org/competitions/report. The port
     * previously carried no XML surface at all.
     *
     * All four "BJCP Reporting Adjustments" behaviours are encoded here: the
     * judged → received → paid → total entry-count cascade (bjcpEntryCount(),
     * which also feeds the 30-entry BOS gate), the <BOSData> panel gate (medal
     * winners alone must not look like a Best of Show), TEMP#### judge IDs
     * (bjcpId()) and the corrected judge / steward / BOS-only point math
     * (judgePoints()/stewardPoints(), shared with the PDF path).
     *
     * Deliberate divergences from the frozen legacy source, each to keep the
     * submitted document valid or to follow the official schedule (see the
     * class docblock for the scoring ones):
     *  - one <JudgeData> per person: legacy can emit a BOS judge twice (judges
     *    loop plus BOS-only loop) and BJCP rejects duplicate entries;
     *  - a steward's valid <JudgeID> is emitted — legacy assigns it to the
     *    wrong variable ($judge_bjcp_id) and so always ships an empty id;
     *  - CompID and text values are XML-escaped.
     */
    private function xmlReport(TenantContext $ctx, Request $request): Response
    {
        $entries = self::bjcpEntryCount();
        [$beer, $mead, $cider] = $this->styleTallies($ctx);
        $meadCider = $mead + $cider;

        $bosEligible = $entries >= 30 && ($beer >= 5 || $meadCider >= 3);
        $bosPoints = $bosEligible ? 0.5 : 0.0;
        $bosAlert = $entries < 30 ? self::BOS_ALERT : '';

        $organMax = self::maxPoints($entries, 'Organizer');
        $staffMax = self::maxPoints($entries, 'Staff');
        $judgeMax = self::maxPoints($entries, 'Judge');

        $stafferCount = DB::table('staff')->where('staff_staff', '1')->count();
        $staffPoints = match (true) {
            $stafferCount === 1 => $staffMax,
            $stafferCount >= 2 => round(($staffMax / $stafferCount) / 0.5) * 0.5,
            default => 0.0,
        };

        $organizerRow = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.staff_organizer', '1')
            ->orderBy('br.brewerLastName')
            ->first(['br.uid', 'br.brewerFirstName', 'br.brewerLastName', 'br.brewerJudgeID']);

        $contestId = (string) ($ctx->contestStr('contestID') ?? '');
        $days = $this->totalDays($ctx);
        $sessions = $this->totalSessions();
        $filename = self::xmlFilename($contestId, (string) ($ctx->contestStr('contestName') ?? ''), $ctx);

        // A report the BJCP portal would reject is returned as the legacy
        // plain-text refusal rather than half a document.
        if ($organizerRow === null || $sessions > $days * 3 || $contestId === '') {
            $output = 'The report cannot be generated for the following reasons:';
            if ($organizerRow === null) {
                $output .= "\n- No organizer has been designated. The BJCP will not accept an XML report without a named Organizer. Designate the Organizer by going to Admin > Entries and Participants > Assign Staff. Choose the Organizer's name from the drop-down list near the top of the page. If the Organizer's name is not present in the drop-down, an account will need to be created for them.";
            }
            if ($sessions > $days * 3) {
                $output .= "\n- Judging sessions exceed the maximum of three (3) per day.";
            }
            if ($contestId === '') {
                $output .= "\n- The BJCP Competition ID is missing. A Competition ID is required for submittal to the BJCP and can be found in the registration confirmation email sent by the BJCP. Add the Competition ID via Admin > Competition Preparation > Edit Competition Info.";
            }

            return self::xmlResponse($filename, $output);
        }

        $judgeUids = self::roleUids('staff_judge');
        $stewardUids = self::stewardUids($judgeUids);
        $staffUids = self::roleUids('staff_staff');
        $bosUids = $this->bosJudgeUids();
        $bosNoAssignment = self::bosUidsWithoutAssignment($bosUids);
        $isBosJudge = static fn (int $uid): bool => in_array($uid, $bosUids, true);
        $organUid = (int) $organizerRow->uid;

        $brewer = self::brewerRows(array_values([...$judgeUids, ...$stewardUids, ...$staffUids, ...$bosUids, $organUid]));

        // Legacy renders the same five role blocks twice — valid ids first,
        // then invalid — over one shared staff point pool.
        $runningTotal = 0.0;
        $bjcp = [];
        $nonBjcp = [];

        foreach ([true, false] as $valid) {
            $lines = [];

            $organId = self::bjcpId($organizerRow->brewerJudgeID);
            if (($organId !== null) === $valid && self::hasName($organizerRow)) {
                array_push($lines, ...self::judgeData(self::personName($organizerRow), $organId ?? '', [
                    ['JudgeRole', 'Organizer'],
                    ['JudgePts', '0.0'],
                    ['NonJudgePts', number_format($organMax, 1)],
                ]));
            }

            foreach ($judgeUids as $uid) {
                $b = $brewer[$uid] ?? null;
                $id = $b === null ? null : self::bjcpId($b->brewerJudgeID);
                $points = $this->judgePoints($uid, $judgeMax, $ctx);

                if ($b === null || ! self::hasName($b) || ($id !== null) !== $valid
                    || $points <= 0.0 || in_array($uid, $staffUids, true) || $organUid === $uid) {
                    continue;
                }

                $isBos = $isBosJudge((int) $uid);
                array_push($lines, ...self::judgeData(self::personName($b), $id ?? '', [
                    ['JudgeRole', $isBos ? 'Judge + BOS' : 'Judge'],
                    ['JudgePts', number_format($isBos ? $points + $bosPoints : $points, 1)],
                    ['NonJudgePts', '0.0'],
                ]));
            }

            foreach ($bosNoAssignment as $uid) {
                $b = $brewer[$uid] ?? null;
                $id = $b === null ? null : self::bjcpId($b->brewerJudgeID);

                if (! $bosEligible || $b === null || ! self::hasName($b) || ($id !== null) !== $valid
                    || in_array($uid, $judgeUids, true) || in_array($uid, $staffUids, true) || $organUid === $uid) {
                    continue;
                }

                array_push($lines, ...self::judgeData(self::personName($b), $id ?? '', [
                    ['JudgeRole', 'BOS Judge'],
                    ['JudgePts', '1.0'],
                    ['NonJudgePts', '0.0'],
                ]));
            }

            foreach ($stewardUids as $uid) {
                $b = $brewer[$uid] ?? null;
                $id = $b === null ? null : self::bjcpId($b->brewerJudgeID);
                $points = $this->stewardPoints($uid, $ctx);

                if ($b === null || ! self::hasName($b) || ($id !== null) !== $valid
                    || $points <= 0.0 || in_array($uid, $staffUids, true) || $organUid === $uid) {
                    continue;
                }

                array_push($lines, ...self::judgeData(self::personName($b), $id ?? '', [
                    ['JudgeRole', 'Steward'],
                    ['JudgePts', '0.0'],
                    ['NonJudgePts', number_format($points, 1)],
                ]));
            }

            foreach ($staffUids as $uid) {
                $b = $brewer[$uid] ?? null;
                $id = $b === null ? null : self::bjcpId($b->brewerJudgeID);

                if ($b === null || ! self::hasName($b) || ($id !== null) !== $valid || $organUid === $uid) {
                    continue;
                }

                $runningTotal += $staffPoints;
                $withinPool = $runningTotal <= $staffMax;
                $isJudge = in_array($uid, $judgeUids, true);
                $isSteward = in_array($uid, $stewardUids, true);
                $isBos = $isJudge && $isBosJudge((int) $uid);
                $isBosOnly = $isBos && in_array($uid, $bosNoAssignment, true);

                $judgePts = $isJudge
                    ? $this->judgePoints($uid, $judgeMax, $ctx) + ($isBos ? $bosPoints : 0.0)
                    : 0.0;
                $stewardPts = $isSteward ? $this->stewardPoints($uid, $ctx) : 0.0;
                $staffPts = $withinPool ? $staffPoints : 0.0;

                $capped = self::capAtOrganizer($organMax, $judgePts, $stewardPts, $staffPts);

                $role = match (true) {
                    $isJudge && $withinPool && $isBosOnly => 'Staff + BOS Judge',
                    $isJudge && $withinPool => $isBos ? 'Staff + Judge + BOS' : 'Staff + Judge',
                    $isJudge => $isBosOnly ? 'BOS Judge' : ($isBos ? 'Judge + BOS' : 'Judge'),
                    $isSteward && $withinPool => 'Staff + Steward',
                    $isSteward => 'Steward',
                    default => 'Staff',
                };

                array_push($lines, ...self::judgeData(self::personName($b), $id ?? '', [
                    ['JudgeRole', $role],
                    ['JudgePts', number_format($capped['judging'], 1)],
                    ['NonJudgePts', number_format($capped['steward'] + $capped['staff'], 1)],
                ]));
            }

            if ($valid) {
                $bjcp = $lines;
            } else {
                $nonBjcp = $lines;
            }
        }

        $maxDate = null;
        foreach (DB::table('judging_locations')->pluck('judgingDate') as $date) {
            if (is_numeric($date) && ($maxDate === null || (int) $date > $maxDate)) {
                $maxDate = (int) $date;
            }
        }
        $compDate = (string) (DateFmt::date($maxDate, $ctx->prefsStr('prefsTimeZone'), 999, 'system') ?? '');

        $output = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n";
        $output .= "<OrgReport>\n";
        $output .= "\t<CompData>\n";
        $output .= "\t\t<CompID>".self::xmlEscape($contestId)."</CompID>\n";
        $output .= "\t\t<CompName>".self::xmlText($ctx->contestStr('contestName'))."</CompName>\n";
        $output .= "\t\t<CompDate>".self::xmlEscape($compDate)."</CompDate>\n";
        $output .= "\t\t<CompEntries>".$entries."</CompEntries>\n";
        $output .= "\t\t<CompDays>".$days."</CompDays>\n";
        $output .= "\t\t<CompSessions>".$sessions."</CompSessions>\n";
        $output .= "\t</CompData>\n";

        $bosData = $this->bosData();
        if ($bosData !== []) {
            $output .= "\t<BOSData>\n";
            foreach ($bosData as $key => $value) {
                $output .= "\t\t<".$key.'>'.$value.'</'.$key.">\n";
            }
            $output .= "\t</BOSData>\n";
        }

        $output .= "\t<BJCPpoints>\n";
        foreach ($bjcp as $line) {
            $output .= $line."\n";
        }
        $output .= "\t</BJCPpoints>\n";

        $output .= "\t<NonBJCP>\n";
        foreach ($nonBjcp as $line) {
            $output .= $line."\n";
        }
        $output .= "\t</NonBJCP>\n";

        $submitter = $request->user();
        $submitterName = DB::table('brewer')
            ->where('uid', (int) $submitter?->id)
            ->first(['brewerFirstName', 'brewerLastName']);

        $output .= "\t<Comments>\n";
        $output .= "\t\tGenerated by BCOEM version ".self::xmlEscape(self::version())."\n";
        $output .= "\t\tInstallation URL: ".self::xmlEscape((string) config('app.url'))."\n";
        $output .= "\t\tSubmitter Name: ".self::xmlEscape(trim((string) ($submitterName->brewerFirstName ?? '').' '.(string) ($submitterName->brewerLastName ?? '')))."\n";
        $output .= "\t\tSubmitter Email: ".self::xmlEscape((string) $submitter?->user_name)."\n";
        if ($bosAlert !== '') {
            $output .= "\t\tNote: ".self::xmlEscape($bosAlert)."\n";
        }
        $output .= "\t</Comments>\n";
        $output .= "\t<SubmissionDate>".(string) DateFmt::dateTime(time(), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'long')."</SubmissionDate>\n";
        $output .= '</OrgReport>';

        return self::xmlResponse($filename, $output);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields
     * @return list<string>
     */
    private static function judgeData(string $name, string $id, array $fields): array
    {
        $lines = ["\t\t<JudgeData>", "\t\t\t<JudgeName>".$name.'</JudgeName>', "\t\t\t<JudgeID>".$id.'</JudgeID>'];

        foreach ($fields as [$tag, $value]) {
            $lines[] = "\t\t\t<".$tag.'>'.$value.'</'.$tag.'>';
        }

        $lines[] = "\t\t</JudgeData>";

        return $lines;
    }

    private static function hasName(\stdClass $brewer): bool
    {
        return (string) ($brewer->brewerFirstName ?? '') !== ''
            && (string) ($brewer->brewerLastName ?? '') !== '';
    }

    private static function personName(\stdClass $brewer): string
    {
        return self::xmlText($brewer->brewerFirstName).' '.self::xmlText($brewer->brewerLastName);
    }

    /** Staff uids holding one role, in brewerLastName order, deduped.
     *
     * @return list<int>
     */
    private static function roleUids(string $column): array
    {
        $uids = [];
        $rows = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.'.$column, '1')
            ->orderBy('br.brewerLastName')
            ->pluck('br.uid');

        foreach ($rows as $uid) {
            $uids[(int) $uid] = true;
        }

        return array_keys($uids);
    }

    /**
     * Steward uids, minus anyone flagged as a judge. The BJCP rules say a
     * participant "may not earn both Judge and Steward Points in a single
     * competition"; the document states no precedence, so the port keeps
     * judging (the higher-value role) and drops the steward award.
     *
     * @param  list<int>  $judgeUids
     * @return list<int>
     */
    private static function stewardUids(array $judgeUids): array
    {
        return array_values(array_diff(self::roleUids('staff_steward'), $judgeUids));
    }

    /**
     * "No single person can receive more total points than the Organizer."
     * Judging (including BOS) is protected, then the formula-derived steward
     * award, then the discretionary staff pool absorbs the reduction.
     *
     * @return array{judging: float, steward: float, staff: float}
     */
    private static function capAtOrganizer(float $organMax, float $judging, float $steward, float $staff): array
    {
        $judging = min($judging, $organMax);
        $remaining = $organMax - $judging;

        $steward = min($steward, $remaining);
        $remaining -= $steward;

        return ['judging' => $judging, 'steward' => $steward, 'staff' => min($staff, $remaining)];
    }

    /**
     * @param  list<int>  $uids
     * @return array<int, \stdClass>
     */
    private static function brewerRows(array $uids): array
    {
        $uids = array_values(array_unique($uids));
        if ($uids === []) {
            return [];
        }

        $rows = [];
        foreach (DB::table('brewer')->whereIn('uid', $uids)->get(['uid', 'brewerFirstName', 'brewerLastName', 'brewerJudgeID']) as $row) {
            $rows[(int) $row->uid] = $row;
        }

        return $rows;
    }

    /**
     * Judges eligible for the 0.5 BOS bonus.
     *
     * Captured panel membership wins when it exists; otherwise the legacy
     * global `staff.staff_judge_bos` flag is used, so competitions that never
     * recorded panels keep their old numbers.
     *
     * @return list<int>
     */
    private function bosJudgeUids(): array
    {
        $panel = $this->bosPanelBonusUids();
        if ($panel !== null) {
            return $panel;
        }

        $uids = [];
        foreach (DB::table('staff')->where('staff_judge_bos', '1')->pluck('uid') as $uid) {
            $uids[(int) $uid] = true;
        }

        return array_keys($uids);
    }

    /**
     * Bonus-eligible judges from captured BOS panel membership, or null when
     * no panel assignments exist at all.
     *
     * A panel is a style type with styleTypeBOS='Y' (the combined Mead/Cider
     * row stands for scoreTypes 2 and 3). Its size is the recorded BOS rows;
     * its cap is the official 3-or-5 rule. Assigned judges past the cap are
     * dropped in brewerLastName order — the document gives a count, not a
     * selection rule.
     *
     * @return list<int>|null
     */
    private function bosPanelBonusUids(): ?array
    {
        if (! DB::table('bos_panel_judges')->exists()) {
            return null;
        }

        $eligible = [];

        foreach (DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get(['id', 'styleTypeName']) as $type) {
            $scoreTypes = (string) $type->styleTypeName === 'Mead/Cider' ? [2, 3] : [(int) $type->id];
            $entries = DB::table('judging_scores_bos')->whereIn('scoreType', $scoreTypes)->count();
            $cap = self::bosPanelCap($entries, in_array(1, $scoreTypes, true));

            if ($cap <= 0) {
                continue;
            }

            $uids = DB::table('bos_panel_judges as p')
                ->join('brewer as b', 'p.uid', '=', 'b.uid')
                ->where('p.bosType', $type->id)
                ->orderBy('b.brewerLastName')
                ->pluck('p.uid');

            foreach ($uids->take($cap) as $uid) {
                $eligible[(int) $uid] = true;
            }
        }

        return array_keys($eligible);
    }

    /**
     * Official per-panel BOS judge cap: 15+ entries of any type → 5;
     * 5-14 entries including beer → 3; 3-14 mead/cider only → 3; below the
     * panel minimum → no panel, no points.
     */
    private static function bosPanelCap(int $entries, bool $includesBeer): int
    {
        if ($entries >= 15) {
            return 5;
        }

        return $entries >= ($includesBeer ? 5 : 3) ? 3 : 0;
    }

    /**
     * The BOS judges holding no table assignment: "If a judge only judges in
     * a BOS panel, that judge earns 1.0 BOS Judge Points and no Judge Points."
     *
     * @param  list<int>  $bosUids
     * @return list<int>
     */
    private static function bosUidsWithoutAssignment(array $bosUids): array
    {
        return array_values(array_filter(
            $bosUids,
            static fn (int $uid): bool => ! DB::table('judging_assignments')->where('bid', $uid)->exists()
        ));
    }

    /**
     * <BOSData> counts. Gated on a BOS panel having actually run — a
     * judging_scores_bos row — because these counts are derived from regular
     * judging's medal winners, which exist for any scored competition.
     *
     * @return array<string, int>
     */
    private function bosData(): array
    {
        if (! DB::table('judging_scores_bos')->exists()) {
            return [];
        }

        $data = [];
        $meadCiderCombined = false;

        foreach ([1, 2, 3, 4] as $type) {
            $info = DB::table('style_types')->where('id', $type)->first(['styleTypeBOS', 'styleTypeBOSMethod', 'styleTypeName']);
            if ($info === null || (string) $info->styleTypeBOS !== 'Y') {
                continue;
            }

            if ((string) $info->styleTypeName === 'Mead/Cider') {
                $meadCiderCombined = true;
            }

            $places = match ((int) $info->styleTypeBOSMethod) {
                1 => ['1'],
                2 => ['1', '2'],
                3 => ['1', '2', '3'],
                default => [],
            };
            if ($places === []) {
                continue;
            }

            $query = DB::table('judging_scores')->whereIn('scorePlace', $places);
            if ($meadCiderCombined) {
                $query->whereIn('scoreType', [2, 3]);
            } else {
                $query->where('scoreType', $type);
            }

            $count = $query->count();
            if ($count === 0) {
                continue;
            }

            if ($type === 1) {
                $data['BOSBeer'] = $count;
            }
            if ($meadCiderCombined) {
                $data['BOSMeadCider'] = $count;
            } elseif ($type === 2) {
                $data['BOSCider'] = $count;
            } elseif ($type === 3) {
                $data['BOSMead'] = $count;
            }
        }

        return $data;
    }

    /** filename() (lib/output.lib.php:128) over the legacy BJCP report name. */
    private static function xmlFilename(string $contestId, string $contestName, TenantContext $ctx): string
    {
        $date = (string) (DateFmt::date(time(), $ctx->prefsStr('prefsTimeZone'), 999, 'system') ?? '');
        $name = ($contestId === '' ? '' : $contestId.'_').$contestName.'_BJCP_Points_Report_'.$date.'.xml';
        $name = ltrim(str_replace(' ', '_', ucwords(str_replace('_', ' ', $name))), '_');

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return $ascii === false ? $name : $ascii;
    }

    private static function xmlResponse(string $filename, string $body): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'application/force-download',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /** h() (lib/common.lib.php:31) — the XML report's escaper. */
    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** h(convert_to_entities($value)) — the report's text-value rendering. */
    private static function xmlText(mixed $value): string
    {
        return self::xmlEscape(self::entities((string) ($value ?? '')));
    }

    /** convert_to_entities() (includes/output.inc.php:21). */
    private static function entities(string $input): string
    {
        $output = (string) preg_replace_callback(
            '/(&#[0-9]+;)/',
            fn (array $m): string => mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES'),
            $input,
        );

        return html_entity_decode($output);
    }

    /** bcoem_sys.version — the running installation's stored BCOEM version. */
    private static function version(): string
    {
        return (string) (DB::table('bcoem_sys')->where('id', 1)->value('version') ?? '');
    }
}
