<?php

declare(strict_types=1);

namespace App\Support\Results;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Faithful port of the legacy Best Brewer / Best Club computations that
 * feed the awards presentation (awards.php:588-1141) and the results
 * output (output_results_download_bestbrewer / winners-display).
 *
 * Semantics pinned against legacy includes/db/scores_bestbrewer.db.php
 * plus the accumulation loops in awards.php:588-1133:
 *
 * - Source rows are ALL judging_scores rows with scorePlace IS NOT NULL
 *   (NO brewReceived filter — deliberate; a brewer's placed entry is
 *   counted even if un-received, matching legacy's raw query).
 * - Places are five buckets [1st..HM]; a score counts only when it is an
 *   integer 1..5 (legacy: floor(place)==place && 1<=place<=5).
 * - CoA mode (prefsScoringCOA=1) keeps per-pool best-place maps
 *   (Places-data) keyed on table id, category sort, or "cat-sub"
 *   depending on the winner method; classic mode ignores them. Pool
 *   sizes for CoA are COUNT(*) of scored rows per pool.
 *   BOS rows NEVER populate Places-data (legacy's BOS loop only touches
 *   Places and Scores).
 * - prefsBestUseBOS=1 merges judging_scores_bos rows (joined via
 *   bid, matching legacy) into the same buckets.
 * - Tie-breakers chain prefsTieBreakRule1..6 feeds BestBrewerPoints; the
 *   user entry count is the brewer's paid+received entries (club rows
 *   pass 0, as legacy's numeric-id query returns for a club name).
 *
 * The legacy club aggregation uses normalizeClubs (lowercase, strip
 * non-alnum) and keeps the FIRST original club spelling per key.
 */
final class BestBrewerStandings
{
    /**
     * @param  list<object{name:string,club:string|null,points:float,places:list<int>}>  $brewerRows
     * @param  list<object{name:string,club:string|null,points:float,places:list<int>}>  $clubRows
     */
    public function __construct(
        public readonly array $brewerRows,
        public readonly array $clubRows,
        public readonly bool $show4th,
        public readonly bool $showHm,
        public readonly int $brewerCount,
        public readonly int $clubCount,
    ) {}

    /** Build brewer + club standings per the tenant prefs. */
    public static function forAwards(TenantContext $ctx): self
    {
        $pointsMethod = (int) ($ctx->prefs['prefsScoringCOA'] ?? 0) === 1 ? '1' : '0';
        $winnerMethod = (int) ($ctx->prefsStr('prefsWinnerMethod') ?? 0);
        $proEdition = (int) ($ctx->prefsStr('prefsProEdition') ?? 0) === 1;
        $coa = $pointsMethod === '1';

        $showBrewer = (int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0) !== 0;
        $showClub = (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0) !== 0;
        $maxBrewer = (int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0);
        $maxClub = (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0);

        $tiebreakers = array_map(
            static fn (string $k): string => (string) ($ctx->prefs[$k] ?? ''),
            ['prefsTieBreakRule1', 'prefsTieBreakRule2', 'prefsTieBreakRule3', 'prefsTieBreakRule4', 'prefsTieBreakRule5', 'prefsTieBreakRule6'],
        );

        $placePointPrefs = array_map(
            static fn (string $k): float => (float) ($ctx->prefs[$k] ?? 0),
            ['prefsFirstPlacePts', 'prefsSecondPlacePts', 'prefsThirdPlacePts', 'prefsFourthPlacePts', 'prefsHMPts'],
        );

        $useBos = (int) ($ctx->prefs['prefsBestUseBOS'] ?? 0) === 1;

        // Raw score rows, deterministic iteration (legacy had no ORDER BY;
        // last-write-wins per CoA pool made order matter — we pin id order
        // so results are reproducible, a deliberate deviation).
        $scores = DB::table('judging_scores as js')
            ->join('brewing as b', 'js.eid', '=', 'b.id')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->whereNotNull('js.scorePlace')
            ->orderBy('js.id')
            ->get([
                'js.scorePlace', 'js.scoreEntry', 'js.scoreTable',
                'b.brewCoBrewer', 'b.brewCategory', 'b.brewCategorySort',
                'b.brewSubCategory',
                'br.uid', 'br.brewerLastName', 'br.brewerFirstName',
                'br.brewerBreweryName', 'br.brewerClubs',
            ]);

        $bosScores = $useBos
            ? DB::table('judging_scores_bos as jsb')
                ->join('brewing as b', 'jsb.eid', '=', 'b.id')
                ->join('brewer as br', 'br.uid', '=', 'jsb.bid')
                ->whereNotNull('jsb.scorePlace')
                ->orderBy('jsb.id')
                ->get([
                    'jsb.scorePlace', 'jsb.scoreEntry',
                    'b.brewCategory', 'b.brewCategorySort', 'b.brewSubCategory',
                    'br.brewerClubs', 'br.uid',
                ])
            : collect();

        $poolSizes = $coa ? self::poolSizes($ctx, $winnerMethod) : [];

        $brewerAcc = [];
        $clubAcc = [];

        foreach ($scores as $r) {
            self::accumulate($brewerAcc, $clubAcc, $r, $winnerMethod, $proEdition, $coa, (string) $r->uid, false);
        }

        foreach ($bosScores as $r) {
            self::accumulate($brewerAcc, $clubAcc, $r, $winnerMethod, $proEdition, $coa, (string) $r->uid, true);
        }

        $brewerRows = $showBrewer && $brewerAcc !== []
            ? self::scoreRows($brewerAcc, $poolSizes, $tiebreakers, $placePointPrefs, $coa, $proEdition, false, $maxBrewer)
            : [];

        $clubRows = $showClub && ! $proEdition && $clubAcc !== []
            ? self::scoreRows($clubAcc, $poolSizes, $tiebreakers, $placePointPrefs, $coa, $proEdition, true, $maxClub)
            : [];

        $show4th = false;
        $showHm = false;

        foreach ([...$brewerRows, ...$clubRows] as $row) {
            if ($row->places[3] > 0) {
                $show4th = true;
            }
            if ($row->places[4] > 0) {
                $showHm = true;
            }
        }

        return new self(
            $brewerRows,
            $clubRows,
            $show4th,
            $showHm,
            self::participantCount('brewer'),
            self::participantCount('club'),
        );
    }

    /**
     * Per-pool CoA sizes. Method 0 → COUNT(*) of judged scores per table;
     * methods 1/2 → COUNT(*) of received brewing rows per category
     * (legacy awards.php pool branch, :618-703).
     *
     * @return array<string|int, float>
     */
    private static function poolSizes(TenantContext $ctx, int $winnerMethod): array
    {
        if ($winnerMethod === 0) {
            return DB::table('judging_scores')
                ->select('scoreTable')
                ->selectRaw('COUNT(*) as cnt')
                ->groupBy('scoreTable')
                ->pluck('cnt', 'scoreTable')
                ->map(static fn ($v): float => (float) $v)
                ->all();
        }

        // Category pool (methods 1 and 2): per enabled/selected group in the
        // active style set, count received entries (winners_category.db.php).
        $groups = DB::table('styles as s')
            ->where('s.brewStyleActive', 'Y')
            ->where(function ($q) use ($ctx): void {
                $set = (string) ($ctx->prefs['prefsStyleSet'] ?? 'BJCP2025');
                if ($set === 'BJCP2025') {
                    $q->where(function ($qq): void {
                        $qq->where('brewStyleVersion', 'BJCP2025')->where('brewStyleType', '2');
                    })->orWhere(function ($qq): void {
                        $qq->where('brewStyleVersion', 'BJCP2021')->where('brewStyleType', '!=', '2');
                    });
                } else {
                    $q->where('brewStyleVersion', $set);
                }
                $q->orWhere('brewStyleOwn', 'custom');
            })
            ->select('s.brewStyleGroup')
            ->distinct()
            ->get()
            ->pluck('brewStyleGroup');

        $pools = [];
        foreach ($groups as $group) {
            $g = (string) $group;
            $pools[$g] = (float) DB::table('brewing')->where('brewCategorySort', $g)->where('brewReceived', 1)->count();
        }

        return $pools;
    }

    /**
     * Accumulate one score row into brewer + club buckets.
     *
     * @param  array<string,array{Name:string,Clubs:string|null,Places:list<int>,Scores:list<float>,Places-data?:array<string|int,int>}>  $brewerAcc
     * @param  array<string,array{Clubs:string,Places:list<int>,Scores:list<float>,Places-data?:array<string|int,int>}>  $clubAcc
     */
    private static function accumulate(
        array &$brewerAcc,
        array &$clubAcc,
        object $r,
        int $winnerMethod,
        bool $proEdition,
        bool $coa,
        string $uid,
        bool $isBos,
    ): void {
        $place = floor((float) $r->scorePlace);
        $isPlace = $place == (float) $r->scorePlace && $place >= 1 && $place <= 5;
        $placeInt = $isPlace ? (int) $place : 0;
        $score = (float) $r->scoreEntry;
        $clubRaw = (string) ($r->brewerClubs ?? '');
        $clubName = self::normalizeClubs($clubRaw);

        if (! isset($brewerAcc[$uid])) {
            $brewerAcc[$uid] = [
                'Name' => $proEdition
                    ? (string) ($r->brewerBreweryName ?? '') ?: trim((string) $r->brewerFirstName.' '.(string) $r->brewerLastName)
                    : trim((string) $r->brewerFirstName.' '.(string) $r->brewerLastName),
                'Clubs' => $proEdition ? null : $clubRaw,
                'Places' => [0, 0, 0, 0, 0],
                'Scores' => [],
                'TypeBOS' => [],
            ];
            if ($coa) {
                $brewerAcc[$uid]['Places-data'] = [];
            }
        }

        if ($isPlace) {
            $brewerAcc[$uid]['Places'][$placeInt - 1] += 1;
        }
        $brewerAcc[$uid]['Scores'][] = $score;
        if ($isBos) {
            $brewerAcc[$uid]['TypeBOS'][] = 1;
        }

        // CoA Places-data only for non-BOS rows (legacy BOS loop never
        // writes Places-data).
        if ($coa && ! $isBos && $isPlace) {
            $poolKey = $winnerMethod === 0
                ? (string) ($r->scoreTable ?? '')
                : (string) ($r->brewCategorySort ?? '');
            if ($poolKey !== '') {
                $brewerAcc[$uid]['Places-data'][$poolKey] = $placeInt;
            }
        }

        if ($clubRaw === '' || $proEdition) {
            return;
        }

        if (! isset($clubAcc[$clubName])) {
            $clubAcc[$clubName] = [
                'Clubs' => $clubRaw,
                'Places' => [0, 0, 0, 0, 0],
                'Scores' => [],
            ];
            if ($coa) {
                $clubAcc[$clubName]['Places-data'] = [];
            }
        }

        if ($isPlace) {
            $clubAcc[$clubName]['Places'][$placeInt - 1] += 1;
        }
        $clubAcc[$clubName]['Scores'][] = $score;

        if ($coa && ! $isBos && $isPlace) {
            $poolKey = match ($winnerMethod) {
                2 => (string) ($r->brewCategorySort ?? '').'-'.(string) ($r->brewSubCategory ?? ''),
                1 => (string) ($r->brewCategorySort ?? ''),
                default => (string) ($r->scoreTable ?? ''),
            };
            if ($poolKey !== '') {
                $clubAcc[$clubName]['Places-data'][$poolKey] = $placeInt;
            }
        }
    }

    /**
     * @param  array<string,array{Name?:string,Clubs?:string|null,Places:list<int>,Scores:list<float>,Places-data?:array<string|int,int>}>  $rows
     * @return list<object{name:string,club:string|null,points:float,places:list<int>}>
     */
    private static function scoreRows(
        array $acc,
        array $poolSizes,
        array $tiebreakers,
        array $placePointPrefs,
        bool $coa,
        bool $proEdition,
        bool $club,
        int $maxPosition,
    ): array {
        $rows = [];

        foreach ($acc as $key => $a) {
            $points = $coa
                ? BestBrewerPoints::calculate($a['Places-data'] ?? $a['Places'], $a['Scores'], $poolSizes, $tiebreakers, '1')
                : BestBrewerPoints::calculate($a['Places'], $a['Scores'], $placePointPrefs, $tiebreakers, '0', $club ? 0 : self::userEntries((string) $key));

            $rows[] = (object) [
                'name' => (string) ($a['Name'] ?? $a['Clubs'] ?? ''),
                'club' => ($club || $proEdition) ? null : (string) ($a['Clubs'] ?? ''),
                'points' => (float) $points,
                'places' => $a['Places'],
            ];
        }

        // Legacy arsort: descending points, equal points preserve insertion
        // order (PHP 8 stable sort).
        usort($rows, static fn ($a, $b) => $b->points <=> $a->points);

        // Rank + ordinal (legacy bb_position / bb_display_position): equal
        // points share a rank; the running count drives the ordinal suffix.
        $rank = 0;
        $prev = null;
        foreach ($rows as $i => $row) {
            if ($row->points != $prev) {
                $rank = $i + 1;
                $prev = $row->points;
            }
            $row->position = $rank;
            $row->fh = $i + 1;
        }

        if ($maxPosition === -1 || $maxPosition >= count($rows)) {
            return $rows;
        }

        return array_slice($rows, 0, max($maxPosition, 0));
    }

    private static function userEntries(string $uid): int
    {
        if ($uid === '') {
            return 0;
        }

        return (int) DB::table('brewing')->where('brewBrewerID', $uid)->where('brewPaid', 1)->where('brewReceived', 1)->count();
    }

    private static function participantCount(string $type): int
    {
        if ($type === 'club') {
            return (int) DB::table('brewing as b')
                ->join('brewer as br', 'br.uid', '=', 'b.brewBrewerID')
                ->where('b.brewReceived', 1)
                ->whereNotNull('br.brewerClubs')
                ->distinct()
                ->count('br.brewerClubs');
        }

        return (int) DB::table('brewing')->where('brewReceived', 1)->distinct()->count('brewBrewerID');
    }

    private static function normalizeClubs(?string $string): string
    {
        $club = strtolower((string) $string);
        $club = preg_replace('/[^a-z0-9]/i', '', $club) ?? '';
        $club = preg_replace('/  +/', ' ', $club) ?? '';

        return $club;
    }
}