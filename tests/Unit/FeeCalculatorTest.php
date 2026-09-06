<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Payments\FeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * FeeCalculator vs legacy total_fees()/total_fees_paid() per-entrant
 * branch (lib/common.lib.php:907+). Expected values hand-computed from
 * the legacy control flow.
 *
 * @covers \App\Support\Payments\FeeCalculator
 */
final class FeeCalculatorTest extends TestCase
{
    /** @return array{fee: float, feeDiscount: float, discountOn: bool, discountNum: int, special: ?float, cap: float} */
    private static function p(
        float $fee = 8.0,
        float $feeDiscount = 5.0,
        bool $discountOn = false,
        int $discountNum = 0,
        ?float $special = null,
        float $cap = 0.0,
    ): array {
        return [
            'fee' => $fee,
            'feeDiscount' => $feeDiscount,
            'discountOn' => $discountOn,
            'discountNum' => $discountNum,
            'special' => $special,
            'cap' => $cap,
        ];
    }

    public function test_zero_entries_is_free(): void
    {
        self::assertSame('0.00', FeeCalculator::total(0, false, self::p()));
    }

    public function test_flat_fee_multiplies(): void
    {
        // entry_discount == "N" → n * entry_fee (:942-943).
        self::assertSame('24.00', FeeCalculator::total(3, false, self::p()));
    }

    public function test_volume_tier_splits_at_discount_number(): void
    {
        // a = N*fee; b = (count-N)*feeDiscount; count > N → a+b (:939-941).
        self::assertSame('26.00', FeeCalculator::total(4, false, self::p(discountOn: true, discountNum: 2)));
        // count == N → all regular (:942).
        self::assertSame('16.00', FeeCalculator::total(2, false, self::p(discountOn: true, discountNum: 2)));
        // count < N → all regular.
        self::assertSame('8.00', FeeCalculator::total(1, false, self::p(discountOn: true, discountNum: 2)));
    }

    public function test_special_rate_replaces_both_rates(): void
    {
        // brewerDiscount == "Y" + rate set, entry_discount N → count*special (:934).
        self::assertSame('18.00', FeeCalculator::total(3, true, self::p(special: 6.0)));
    }

    public function test_special_rate_with_tier_uses_better_rate_for_remainder(): void
    {
        // :926-931 — a = N*special (6), remainder rate = max(special, tier) = 6 → 6 + 2×6.
        self::assertSame('18.00', FeeCalculator::total(3, true, self::p(discountOn: true, discountNum: 1, special: 6.0)));
        // feeDiscount 9 > special 6 → remainder at 9.
        self::assertSame('24.00', FeeCalculator::total(3, true, self::p(feeDiscount: 9.0, discountOn: true, discountNum: 1, special: 6.0)));
    }

    public function test_special_flag_without_rate_pref_is_ignored(): void
    {
        // :921 quirk — flag Y with empty contestEntryFeePasswordNum falls
        // through to the regular branch.
        self::assertSame('24.00', FeeCalculator::total(3, true, self::p()));
    }

    public function test_cap_clamps_totals_above_it(): void
    {
        // :944-947 — total >= cap → cap.
        self::assertSame('20.00', FeeCalculator::total(4, false, self::p(cap: 20.0)));
        // total < cap unaffected.
        self::assertSame('16.00', FeeCalculator::total(2, false, self::p(cap: 20.0)));
        // Cap wins over tiers too.
        self::assertSame('20.00', FeeCalculator::total(10, false, self::p(discountOn: true, discountNum: 2, cap: 20.0)));
    }

    public function test_negative_entry_count_is_free(): void
    {
        self::assertSame('0.00', FeeCalculator::total(-2, false, self::p()));
    }
}
