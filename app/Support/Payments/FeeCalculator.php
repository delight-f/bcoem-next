<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-entrant competition fees (payments plan W4) — the single home for
 * legacy total_fees()/total_fees_paid()'s per-entrant branch
 * (lib/common.lib.php:907, :1080):
 *
 *   - First $discountNum entries at $fee, remaining entries at $feeDiscount
 *     when volume discounts are enabled (contestEntryFeeDiscount == 'Y').
 *   - Entrants flagged brewer.brewerDiscount = 'Y' (club/special rate,
 *     contestEntryFeePasswordNum) pay the special rate per entry — only
 *     when that pref is non-empty (legacy quirk :921: the flag is ignored
 *     while the rate pref is empty). When BOTH the special rate and volume
 *     discounts apply, the tiered remainder uses the better of the two
 *     rates for the discounted entries (total_fees_paid :1108-1112).
 *   - The entrant's total is capped at $cap when > 0.
 *
 * Pure function of inputs — no container, no DB — so every consumer (pay
 * page, checkout, reconciliation, manual marking, dashboard, purge ajax)
 * computes identical amounts. Money in BCMath strings; legacy used floats,
 * the port keeps the same rounding-free +/* semantics at 2dp.
 */
final class FeeCalculator
{
    /**
     * @param  array{fee: float, feeDiscount: float, discountOn: bool, discountNum: int, special: ?float, cap: float}  $p
     * @return numeric-string
     */
    public static function total(int $entryCount, bool $hasSpecial, array $p): string
    {
        if ($entryCount <= 0) {
            return '0.00';
        }

        // Canonical 2dp strings: float params come from decimal DB columns,
        // and '8' vs '8.00' must produce identical cents.
        $fee = number_format($p['fee'], 2, '.', '');
        $feeDiscount = number_format($p['feeDiscount'], 2, '.', '');
        $special = $p['special'] === null ? null : number_format($p['special'], 2, '.', '');
        $cap = number_format($p['cap'], 2, '.', '');
        $n = max(0, $p['discountNum']);

        if ($hasSpecial && $special !== null) {
            if ($p['discountOn'] && $entryCount > $n) {
                $regular = bcmul((string) $n, $special, 2);
                // Better-of-two for the discounted remainder (:1108-1112).
                $rate = bccomp($special, $feeDiscount, 2) === -1 ? $feeDiscount : $special;
                $total = bcadd($regular, bcmul((string) ($entryCount - $n), $rate, 2), 2);
            } else {
                $total = bcmul((string) $entryCount, $special, 2);
            }
        } elseif ($p['discountOn'] && $n > 0 && $entryCount > $n) {
            $total = bcadd(
                bcmul((string) $n, $fee, 2),
                bcmul((string) ($entryCount - $n), $feeDiscount, 2),
                2,
            );
        } else {
            $total = bcmul((string) $entryCount, $fee, 2);
        }

        if (bccomp($cap, '0.00', 2) === 1 && bccomp($total, $cap, 2) === 1) {
            $total = $cap;
        }

        return $total;
    }

    /**
     * Inputs from the tenant's competition row + one brewer's discount flag.
     *
     * @param  array<string, mixed>|\stdClass|null  $brewer  brewer row (brewerDiscount)
     * @return array{fee: float, feeDiscount: float, discountOn: bool, discountNum: int, special: ?float, cap: float, hasSpecial: bool, transFeeOn: bool, transFeePercent: float, transFeeFixed: float}
     */
    public static function params(TenantContext $ctx, array|\stdClass|null $brewer = null): array
    {
        $specialRate = (string) ($ctx->contestStr('contestEntryFeePasswordNum') ?? '');

        return [
            'fee' => (float) ($ctx->contestStr('contestEntryFee') ?? 0),
            'feeDiscount' => (float) ($ctx->contestStr('contestEntryFee2') ?? 0),
            'discountOn' => $ctx->contestStr('contestEntryFeeDiscount') === 'Y',
            'discountNum' => (int) ($ctx->contestStr('contestEntryFeeDiscountNum') ?? 0),
            'special' => $specialRate === '' ? null : (float) $specialRate,
            'cap' => (float) ($ctx->contestStr('contestEntryCap') ?? 0),
            'hasSpecial' => $specialRate !== ''
                && (string) (is_array($brewer) ? ($brewer['brewerDiscount'] ?? '') : ($brewer instanceof \stdClass ? ($brewer->brewerDiscount ?? '') : '')) === 'Y',
            // Checkout transaction fee passed to the entrant (Payment tab):
            // applied only by withTransactionFee() on the checkout total.
            'transFeeOn' => $ctx->prefsStr('prefsTransFee') === 'Y',
            'transFeePercent' => (float) ($ctx->prefsStr('prefsTransFeePercent') ?? 0),
            'transFeeFixed' => (float) ($ctx->prefsStr('prefsTransFeeFixed') ?? 0),
        ];
    }

    /**
     * Convenience: total for one entrant, params resolved from the tenant
     * and the brewer's discount flag fetched here (legacy shape). The
     * checkout transaction fee (when enabled) rides on top — this is the
     * amount actually charged, so the public pay page, the gateway checkout
     * and admin manual marking all agree.
     */
    public static function forEntrant(TenantContext $ctx, int $uid, int $entryCount): string
    {
        $params = self::params($ctx);
        $hasSpecial = (string) (DB::table('brewer')->where('uid', $uid)->value('brewerDiscount') ?? '') === 'Y';

        return self::withTransactionFee(self::total($entryCount, $hasSpecial, $params), $params);
    }

    /**
     * "Checkout Fees Paid by Entrant" (prefsTransFee): a percentage of the
     * subtotal plus a fixed amount, rounded to cents. Deliberately kept out
     * of total() — that is the entry-fee model entry-fee displays read — so
     * only the checkout/manual amount carries the surcharge.
     *
     * @param  numeric-string  $subtotal
     * @param  array{transFeeOn: bool, transFeePercent: float, transFeeFixed: float}  $p
     */
    private static function withTransactionFee(string $subtotal, array $p): string
    {
        if (! $p['transFeeOn']) {
            return $subtotal;
        }

        $percent = (string) $p['transFeePercent'];
        $fixed = (string) $p['transFeeFixed'];

        // subtotal + (subtotal * percent/100 + fixed), rounded half-up to
        // cents via *100 + 0.5 truncated. A zero rate leaves the total
        // unchanged (the surcharge is 0, never the whole amount).
        $surcharge = bcadd(bcmul($subtotal, bcdiv($percent, '100', 8), 8), $fixed, 8);
        $total = bcadd($subtotal, $surcharge, 8);
        $cents = bcadd(bcmul($total, '100', 6), '0.5', 0);

        return bcdiv($cents, '100', 2);
    }
}
