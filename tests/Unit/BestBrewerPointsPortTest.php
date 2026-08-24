<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use App\Support\Results\BestBrewerPoints;
use PHPUnit\Framework\TestCase;

/**
 * Port-contract tests for BestBrewerPoints — values mirror the pinned
 * legacy characterization in BestBrewerPointsTest.php.
 */
final class BestBrewerPointsPortTest extends TestCase
{
    public function test_coa_single_first_place_from_ten(): void
    {
        // ((10 - 1) / 10)^3 = 0.729
        self::assertSame(0.729, round(BestBrewerPoints::calculate([1], [0], [10], [], '1'), 3));
    }

    public function test_coa_all_five_positions_win(): void
    {
        // 5 × ((5-1)/5)^3 = 2.56
        self::assertSame(2.56, round(BestBrewerPoints::calculate([1, 1, 1, 1, 1], [0], [5, 5, 5, 5, 5], [], '1'), 3));
    }

    public function test_coa_third_place_with_zero_count_siblings(): void
    {
        // zeros contribute ((10-0)/10)^3 = 1.0 each; third adds 0.729 → 2.729
        self::assertSame(2.729, round(BestBrewerPoints::calculate([0, 0, 1], [0], [10, 10, 10], [], '1'), 3));
    }

    public function test_classic_method_multiplies_prefs_by_places(): void
    {
        // prefs 6/4/3/2/1 with one 1st and one HM: 6×1 + 1×1 = 7
        $points = BestBrewerPoints::calculate([1, 0, 0, 0, 1], [0], [6.0, 4.0, 3.0, 2.0, 1.0]);
        self::assertSame(7.0, $points);
    }

    public function test_classic_tiebreaker_chain_appends_shrinking_fractions(): void
    {
        // (6×1st)+(4×2nd) = 10 place points; TBTotalPlaces sums first-three wins = 2 → /100
        $points = BestBrewerPoints::calculate(
            [1, 1, 0, 0, 0], [0], [6.0, 4.0, 3.0, 2.0, 1.0], ['TBTotalPlaces'],
        );
        self::assertSame(10.02, round($points, 2));
    }
}
