<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\ManualGateway;

/**
 * Transport-blindness gate for P3.5c: the manual adapter passes the SAME
 * contract tests as the ticket 12 fake (and P3.5b Stripe) — one contract,
 * every transport.
 */
final class ManualGatewayAdapterTest extends PaymentGatewayContractTestCase
{
    #[\Override]
    protected function adapter(): GatewayAdapter
    {
        return new ManualGateway;
    }

    /**
     * The admin mark-paid intent IS the payload — same keys the
     * ManualPaymentController posts.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function paidPayload(string $eventId, string $providerRef, string $amount): array
    {
        return ['outcome' => 'paid', 'event_id' => $eventId, 'ref' => $providerRef, 'amount' => $amount];
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function failedPayload(string $eventId): array
    {
        // A tampered/missing outcome must fail closed, never mark paid.
        return ['event_id' => $eventId];
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function refundPayload(string $paymentRef, string $eventId): array
    {
        return ['outcome' => 'refund', 'event_id' => $eventId, 'ref' => $paymentRef];
    }
}
