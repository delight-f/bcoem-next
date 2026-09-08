<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: evaluation-app import consensus rule (P1.9).
 *
 * Legacy source: ajax/import_scores.ajax.php (:150-240). Evaluations are
 * grouped per entry id (eid) from the `evaluation` table:
 *
 *   - exactly ONE evaluation  -> NOT imported (consensus needs >=2 judges);
 *     the eid lands in a "singles" report bucket
 *   - TWO or more             -> imported; official score = MAX of the
 *     judges' evalFinalScore values (highest wins — NOT an average), place =
 *     max numeric place > 0 else none, mini-BOS = max flag
 *   - existing judging_scores rows only ever get scorePlace / scoreType /
 *     scoreMiniBOS updated — imported data never overwrites entered scores
 */
final class EvalConsensusTest extends TestCase
{
    /**
     * @param  list<float>  $judgeScores
     */
    #[DataProvider('provideConsensusGroups')]
    public function test_import_decision_per_entry(array $judgeScores, bool $expectedImported, float $expectedScore): void
    {
        // ajax/import_scores.ajax.php :186-232 shape.
        if (count($judgeScores) === 1) {
            $imported = false;
            $finalScore = 0.0;
        } elseif ($judgeScores !== []) {
            $imported = true;
            $finalScore = max($judgeScores);
        } else {
            $imported = false;
            $finalScore = 0.0;
        }

        self::assertSame($expectedImported, $imported);
        self::assertSame($expectedScore, $finalScore);
    }

    /** @return iterable<string, array{list<float>, bool, float}> */
    public static function provideConsensusGroups(): iterable
    {
        yield 'single judge not imported' => [[38.0], false, 0.0];
        yield 'two judges take max' => [[38.0, 42.0], true, 42.0];
        yield 'three judges take max' => [[35.5, 40.25, 39.0], true, 40.25];
        yield 'identical scores fine' => [[36.0, 36.0], true, 36.0];
    }

    /**
     * @param  list<int>  $places
     * @param  int|list<int>  $expected
     */
    #[DataProvider('providePlaces')]
    public function test_place_takes_max_numeric_positive_else_none(array $places, int|array $expected): void
    {
        // :229-231 — $evalPlace = max($evalPlace); then kept only if
        // is_numeric && > 0, otherwise emptied (legacy uses an empty array
        // as its "no place" sentinel).
        $place = [];
        if ($places !== []) {
            $place = max($places);
        }
        if ((is_numeric($place)) && ($place > 0)) {
            $place = $place;
        } else {
            $place = [];
        }

        self::assertSame($expected, $place);
    }

    /** @return iterable<string, array{list<int>, int|list<int>}> */
    public static function providePlaces(): iterable
    {
        yield 'max place wins' => [[1, 3, 2], 3];
        yield 'zero places dropped' => [[0, 0], []];
        yield 'mixed keeps positive max' => [[0, 2], 2];
    }
}
