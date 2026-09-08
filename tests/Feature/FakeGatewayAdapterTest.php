<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\Checkout;
use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;

/**
 * The ticket 12 fake adapter: proves the GatewayAdapter contract is
 * satisfiable without any transport (no SDK, no HTTP, no signature keys).
 * It is also the reference implementation P3.5b Stripe and P3.5c manual
 * adapters are measured against via PaymentGatewayContractTestCase.
 */
final class FakeGatewayAdapterTest extends PaymentGatewayContractTestCase
{
    #[\Override]
    protected function adapter(): GatewayAdapter
    {
        return new FakeGateway;
    }

    #[\Override]
    protected function paidPayload(string $eventId, string $providerRef, string $amount): array
    {
        return ['outcome' => 'paid', 'event_id' => $eventId, 'ref' => $providerRef, 'amount' => $amount];
    }

    #[\Override]
    protected function failedPayload(string $eventId): array
    {
        return ['outcome' => 'failed', 'event_id' => $eventId];
    }

    #[\Override]
    protected function refundPayload(string $paymentRef, string $eventId): array
    {
        return ['outcome' => 'refunded', 'event_id' => $eventId, 'ref' => $paymentRef];
    }
}

/** Minimal in-memory gateway: payload shape mirrors the contract only. */
final class FakeGateway implements GatewayAdapter
{
    #[\Override]
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
    {
        sort($entries);

        return new Checkout(
            'fake_'.$entrantUid.'-'.implode('-', $entries),
            '/pay/fake/'.$entrantUid,
        );
    }

    #[\Override]
    public function handleCallback(array $payload): PaymentResult
    {
        return new PaymentResult(
            event: PaymentEvent::from($payload['outcome']),
            eventId: (string) ($payload['event_id'] ?? ''),
            providerRef: isset($payload['ref']) ? (string) $payload['ref'] : null,
            amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
        );
    }

    #[\Override]
    public function refund(string $paymentRef): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Refunded, 'fake_refund_'.$paymentRef, $paymentRef);
    }

    #[\Override]
    public function cancel(string $checkoutId): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Cancelled, 'fake_cancel_'.$checkoutId);
    }

    #[\Override]
    public function method(): string
    {
        return 'fake';
    }
}
