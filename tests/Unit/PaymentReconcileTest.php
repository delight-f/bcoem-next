<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Payments\PaymentService;
use PHPUnit\Framework\TestCase;

/**
 * Fee reconciliation (#9): posted amount must equal owed fees
 * (flat contestEntryFee x entry count). Legacy IPN never validated this —
 * undercharging was possible; the port detects the mismatch.
 */
final class PaymentReconcileTest extends TestCase
{
    public function test_matching_amounts_pass(): void
    {
        self::assertTrue(PaymentService::reconcileAmount([1, 2, 3], '7.50', '2.50'));
        self::assertTrue(PaymentService::reconcileAmount([1], '25', '25'));
        self::assertTrue(PaymentService::reconcileAmount([], '0', '2.50'));
    }

    public function test_mismatched_amounts_fail(): void
    {
        // The legacy undercharge hole: fewer cents posted than owed.
        self::assertFalse(PaymentService::reconcileAmount([1, 2], '4.00', '2.50'));
        // Overpayment is also a mismatch worth flagging.
        self::assertFalse(PaymentService::reconcileAmount([1, 2], '6.00', '2.50'));
    }
}
