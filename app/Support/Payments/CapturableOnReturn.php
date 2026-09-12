<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Hosted-gateway return leg that must complete the payment server-side
 * (PayPal capture). The read-only SessionCheckout covers Stripe's shape,
 * where the browser return only reads state; PayPal needs a mutating call.
 *
 * Implementations must NOT flip local payment flags — the signature-verified
 * webhook stays the single writer (payments ledger #6). The boolean only
 * selects the UX message (msg=13 vs msg=14).
 */
interface CapturableOnReturn
{
    /**
     * Complete the provider-side return for the order reference the browser
     * came back with. Returns true when the provider reports the payment
     * settled (an already-captured order counts as settled).
     */
    public function captureOnReturn(string $reference): bool;
}
