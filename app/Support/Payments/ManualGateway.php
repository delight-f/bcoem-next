<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Manual marking adapter (spec P3.5c): the admin IS the transport. There
 * is no hosted flow and no remote gateway — createCheckout no-ops, and
 * handleCallback verifies an admin-submitted mark-paid intent posted by
 * ManualPaymentController.
 *
 * Fail closed (payments ledger #6): only an explicit outcome=paid intent
 * maps to Paid; anything else (missing/typo/tampered field) maps to Failed
 * and can never mark entries.
 */
final class ManualGateway implements GatewayAdapter
{
    /** Off-line collection methods accepted on the admin form (ticket 13). */
    public const PAY_METHODS = ['check', 'cash', 'dropoff', 'bank-transfer'];

    #[\Override]
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
    {
        sort($entries);

        // No hosted page exists: null redirectUrl marks this adapter as
        // terminal-in-UI (Checkout docblock).
        return new Checkout(
            'manual_'.$entrantUid.'-'.implode('-', $entries),
            null,
        );
    }

    #[\Override]
    public function method(): string
    {
        return PaymentService::METHOD_MANUAL;
    }

    /**
     * @param  array<string, mixed>  $payload  admin mark-paid intent:
     *                                         outcome, event_id, ref, amount, pay_method, reference
     */
    #[\Override]
    public function handleCallback(array $payload): PaymentResult
    {
        $outcome = $payload['outcome'] ?? null;

        if ($outcome === 'refund') {
            return new PaymentResult(
                event: PaymentEvent::Refunded,
                eventId: (string) ($payload['event_id'] ?? ''),
                providerRef: isset($payload['ref']) ? (string) $payload['ref'] : null,
            );
        }

        if ($outcome !== 'paid') {
            return new PaymentResult(PaymentEvent::Failed, (string) ($payload['event_id'] ?? ''));
        }

        return new PaymentResult(
            event: PaymentEvent::Paid,
            eventId: (string) ($payload['event_id'] ?? ''),
            providerRef: isset($payload['ref']) ? (string) $payload['ref'] : null,
            amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
            note: (string) ($payload['note'] ?? ''),
        );
    }

    /**
     * Local refund: nothing remote to call, so the refund event simply
     * identifies the original row (providerRef slot) for markRefunded().
     */
    #[\Override]
    public function refund(string $paymentRef): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Refunded, 'manual_refund_'.$paymentRef, $paymentRef);
    }

    #[\Override]
    public function cancel(string $checkoutId): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Cancelled, 'manual_cancel_'.$checkoutId);
    }
}
