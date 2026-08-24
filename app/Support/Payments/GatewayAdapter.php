<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Transport-blind payment gateway contract (spec P3.5a, D7). Both the
 * Stripe Connect adapter (P3.5b) and manual marking (P3.5c) implement this;
 * PaymentService consumes their results identically.
 *
 * Money flow: entrant pays the competition HOST (passthrough — the platform
 * never holds funds). $feeTotal is the entry-creation fee snapshot
 * (payments ledger #9); adapters must not recompute it.
 */
interface GatewayAdapter
{
    /**
     * Start a checkout for a batch of entries.
     *
     * @param  list<int>  $entries  brewing.id values being paid for
     * @param  int  $entrantUid  users.id of the paying entrant
     * @param  string  $feeTotal  decimal string ('25.00') from the fee snapshot
     */
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout;

    /** Transport identifier written to payments.method (PaymentService METHOD_*). */
    public function method(): string;

    /**
     * Verify + translate one callback (webhook body or admin form post).
     * MUST fail closed: anything not signature-verified maps to
     * PaymentEvent::Failed/Cancelled, never Paid (payments ledger #4/#6).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentResult;

    /** Issue a refund for a settled payment; returns the refund event. */
    public function refund(string $paymentRef): PaymentResult;

    /** Abandon an unfinished checkout. Nothing has been paid yet. */
    public function cancel(string $checkoutId): PaymentResult;
}
