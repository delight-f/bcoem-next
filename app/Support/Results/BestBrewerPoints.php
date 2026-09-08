<?php

declare(strict_types=1);

namespace App\Support\Results;

/**
 * Port of legacy best_brewer_points() (lib/common.lib.php), pinned by
 * tests/Unit/BestBrewerPointsTest.php for the CoA method.
 *
 * Method '0' — classic: place points from preferences (first/second/third/
 * fourth/HM) plus the configured tie-breaker chain appended as shrinking
 * decimal fractions (power of ten grows by 2 or 4 per chain step).
 *
 * Method '1' — CoA: per placed entry, ((tc_entries - place) / tc_entries)^3
 * where tc_entries is the size of the distribution pool (table, category,
 * or subcategory depending on the organizer's winner-place setting).
 */
final class BestBrewerPoints
{
    /**
     * @param  list<int>|array<int|string, int>  $places  win counts per position ([1st, 2nd, 3rd, 4th, HM]); method '1' receives Places-data keyed by pool id instead
     * @param  list<float>  $entryScores  the brewer's entry scores
     * @param  list<float>|array<int|string, float>  $pointsPrefs  method 0: five place-point prefs; method 1: pool sizes keyed by pool id
     * @param  list<string>  $tiebreaker  method 0 tie-breaker chain identifiers
     */
    public static function calculate(
        array $places,
        array $entryScores,
        array $pointsPrefs,
        array $tiebreaker = [],
        string $method = '0',
        int $userNumberOfEntries = 0,
    ): float {
        return $method === '1'
            ? self::coa($places, $pointsPrefs)
            : self::classic($places, $entryScores, $pointsPrefs, $tiebreaker, $userNumberOfEntries);
    }

    /**
     * @param  list<int>|array<int|string, int>  $places
     * @param  list<float>|array<int|string, float>  $poolSizes
     */
    private static function coa(array $places, array $poolSizes): float
    {
        $points = 0.0;

        foreach ($places as $key => $place) {
            $tc = $poolSizes[$key] ?? 0;

            if ($tc <= 0) {
                continue; // division guard; legacy divides by pref value which is entry-count driven
            }

            $points += (($tc - $place) / $tc) ** 3;
        }

        return $points;
    }

    /**
     * @param  list<int>|array<int|string, int>  $places
     * @param  list<float>  $entryScores
     * @param  list<float>|array<int|string, float>  $pointsPrefs
     * @param  list<string>  $tiebreaker
     */
    private static function classic(
        array $places,
        array $entryScores,
        array $pointsPrefs,
        array $tiebreaker,
        int $userNumberOfEntries,
    ): float {
        $ptsFirst = ($pointsPrefs[0] ?? 0) * ($places[0] ?? 0);
        $ptsSecond = ($pointsPrefs[1] ?? 0) * ($places[1] ?? 0);
        $ptsThird = ($pointsPrefs[2] ?? 0) * ($places[2] ?? 0);
        $ptsFourth = ($pointsPrefs[3] ?? 0) * ($places[3] ?? 0);
        $ptsHm = ($pointsPrefs[4] ?? 0) * ($places[4] ?? 0);

        $tbNumPlaces = 0.0;
        $tbFirstPlaces = 0.0;
        $tbNumEntries = 0.0;
        $tbMinScore = 0.0;
        $tbMaxScore = 0.0;
        $tbAvgScore = 0.0;
        $power = 0;

        foreach ($tiebreaker as $step) {
            switch ($step) {
                case 'TBTotalPlaces':
                    $power += 2;
                    $tbNumPlaces = array_sum(array_slice($places, 0, 3)) / 10 ** $power;
                    break;
                case 'TBTotalExtendedPlaces':
                    $power += 2;
                    $tbNumPlaces = array_sum($places) / 10 ** $power;
                    break;
                case 'TBFirstPlaces':
                    $power += 2;
                    $tbFirstPlaces = ($places[0] ?? 0) / 10 ** $power;
                    break;
                case 'TBNumEntries':
                    $power += 4;
                    $tbNumEntries = $userNumberOfEntries > 0 ? floor(100 / $userNumberOfEntries) / 10 ** $power : 0.0;
                    break;
                case 'TBMinScore':
                    $power += 4;
                    $tbMinScore = $entryScores === [] ? 0.0 : floor(10 * min($entryScores)) / 10 ** $power;
                    break;
                case 'TBMaxScore':
                    $power += 4;
                    $tbMaxScore = $entryScores === [] ? 0.0 : floor(10 * max($entryScores)) / 10 ** $power;
                    break;
                case 'TBAvgScore':
                    $power += 4;
                    $tbAvgScore = ($userNumberOfEntries > 0 && $entryScores !== [])
                        ? floor(10 * array_sum($entryScores) / $userNumberOfEntries) / 10 ** $power
                        : 0.0;
                    break;
            }
        }

        return (float) ($ptsFirst + $ptsSecond + $ptsThird + $ptsFourth + $ptsHm
            + $tbNumPlaces + $tbFirstPlaces + $tbNumEntries + $tbMinScore + $tbMaxScore + $tbAvgScore);
    }
}
