<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use App\Support\Eval\EvalConsensus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit pins for the ported consensus engine
 * (App\Support\Eval\EvalConsensus), mirroring the expectations of
 * tests/Characterization/EvalConsensusTest.php against the real code.
 */
final class EvalConsensusRulesTest extends TestCase
{
    /**
     * @param  list<float>  $judgeScores
     */
    #[DataProvider('provideConsensusGroups')]
    public function test_import_decision_per_entry(array $judgeScores, bool $expectedImported, float $expectedScore): void
    {
        $score = EvalConsensus::consensusScore($judgeScores);

        if ($expectedImported) {
            self::assertNotNull($score);
            self::assertSame($expectedScore, $score);
        } else {
            self::assertNull($score);
        }
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
     */
    #[DataProvider('providePlaces')]
    public function test_place_takes_max_numeric_positive_else_none(array $places, ?int $expected): void
    {
        self::assertSame($expected, EvalConsensus::consensusPlace($places));
    }

    /** @return iterable<string, array{list<int>, int|null}> */
    public static function providePlaces(): iterable
    {
        yield 'max place wins' => [[1, 3, 2], 3];
        yield 'zero places dropped' => [[0, 0], null];
        yield 'mixed keeps positive max' => [[0, 2], 2];

        // Legacy emptied non-numeric places before the max; NULL sentinel.
        yield 'junk places yield none' => [[0], null];
    }

    /**
     * @param  list<int|null>  $flags
     */
    #[DataProvider('provideMiniBos')]
    public function test_mini_bos_is_max_flag(array $flags, int $expected): void
    {
        self::assertSame($expected, EvalConsensus::consensusMiniBos($flags));
    }

    /** @return iterable<string, array{list<int|null>, int}> */
    public static function provideMiniBos(): iterable
    {
        yield 'any flag wins' => [[0, 1, 0], 1];
        yield 'no flags' => [[0, 0], 0];
        yield 'nulls only' => [[null, null], 0];
    }
}
