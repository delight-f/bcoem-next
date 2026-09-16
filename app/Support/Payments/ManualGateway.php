<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Tenant\TenantContext;

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

    /**
     * The off-line methods THIS install offers: the Payment tab's "Accept
     * Cash?" / "Accept Checks?" switches gate cash/check, while dropoff and
     * bank-transfer are always available. A method is treated as enabled
     * unless it was explicitly stored as off ('0'/'N'), so a legacy install
     * that never touched the switches keeps its full list.
     *
     * @return list<string>
     */
    public static function methodsFor(TenantContext $ctx): array
    {
        $enabled = static fn (?string $value): bool => ! in_array($value, ['0', 'N'], true);

        return array_values(array_filter(
            self::PAY_METHODS,
            static fn (string $method): bool => match ($method) {
                'cash' => $enabled($ctx->prefsStr('prefsCash')),
                'check' => $enabled($ctx->prefsStr('prefsCheck')),
                default => true,
            },
        ));
    }

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
}
