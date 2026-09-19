<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe Connect Standard adapter (P3.5b). Money flow: the entrant pays
 * the competition HOST — Checkout Sessions are created on the host's
 * connected account (passthrough; the platform is never merchant of
 * record), so every remote call carries `stripe_account`.
 *
 * Constructed with plain values so it is container-free and unit-testable;
 * HTTP transport is injectable via ApiRequestor::setHttpClient() for tests
 * (no live calls). Webhook verification uses the SDK's constant-time HMAC
 * check against the per-competition secret in preferences.prefsStripe.
 */
final class StripeGateway implements GatewayAdapter, SessionCheckout
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $currency,
        private readonly string $webhookSecret = '',
        private readonly ?string $accountId = null,
        private readonly string $successUrl = '',
        private readonly string $cancelUrl = '',
    ) {}

    /**
     * Build from the competition's own settings: currency + connected
     * account + webhook secret come from preferences.prefsStripe, the
     * platform secret key from StripeSettings (saved, else env).
     */
    public static function forTenant(string $successUrl = '', string $cancelUrl = ''): self
    {
        $cfg = StripeSettings::config();

        return new self(
            StripeSettings::get()['secret'],
            // ISO currency only — the legacy prefsCurrency display token
            // ('$', 'euro', ...) must never reach Stripe (B1-01).
            strtolower((string) (($cfg['currency'] ?? null) ?: PaymentService::tenantCurrency())),
            (string) ($cfg['webhook_secret'] ?? ''),
            isset($cfg['account_id']) && $cfg['account_id'] !== '' ? (string) $cfg['account_id'] : null,
            $successUrl,
            $cancelUrl,
        );
    }

    #[\Override]
    public function method(): string
    {
        return PaymentService::METHOD_STRIPE;
    }

    #[\Override]
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
    {
        if ($this->accountId === null) {
            throw new \LogicException('Stripe is not connected; run the admin Connect flow first.');
        }

        if (! is_numeric($feeTotal)) {
            throw new \InvalidArgumentException('Fee snapshot must be a decimal string.');
        }

        sort($entries);
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency,
                    // Fee snapshot is a decimal string ('25.00') → cents.
                    'unit_amount' => (int) bcmul($feeTotal, '100'),
                    'product_data' => ['name' => 'Competition entry fees'],
                ],
            ]],
            'metadata' => [
                'entrant_uid' => (string) $entrantUid,
                'entry_ids' => implode('-', $entries),
            ],
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
        ], $this->accountOpts());

        return new Checkout((string) $session->id, $session->url);
    }

    /**
     * Verify one webhook delivery. Payload: ['raw' => body,
     * 'signature' => Stripe-Signature header]. Anything not signature-
     * verified maps to Failed with an empty event id (ledger #6/#4 fail
     * closed); verified events map by type. Unhandled-but-verified types
     * also map to Failed (never Paid) and are simply acked upstream.
     *
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function handleCallback(array $payload): PaymentResult
    {
        try {
            $event = Webhook::constructEvent(
                (string) ($payload['raw'] ?? ''),
                (string) ($payload['signature'] ?? ''),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'signature verification failed');
        }

        $object = (object) ($event->data->object ?? (object) []);
        $eventId = (string) $event->id;

        return match ($event->type) {
            'checkout.session.completed' => new PaymentResult(
                PaymentEvent::Paid,
                $eventId,
                providerRef: isset($object->payment_intent) ? (string) $object->payment_intent : null,
                amount: self::fromCents($object->amount_total ?? null),
                note: 'checkout.session.completed',
                currency: isset($object->currency) ? strtoupper((string) $object->currency) : null,
            ),
            // charge.refunded's payment_intent identifies the ORIGINAL payment.
            'charge.refunded' => new PaymentResult(
                PaymentEvent::Refunded,
                $eventId,
                providerRef: isset($object->payment_intent) ? (string) $object->payment_intent : null,
                note: 'charge.refunded',
                currency: isset($object->currency) ? strtoupper((string) $object->currency) : null,
            ),
            // Async settlement methods (ACH etc.) confirm days later —
            // Stripe docs list these as must-handle alongside completed.
            'checkout.session.async_payment_succeeded' => new PaymentResult(
                PaymentEvent::Paid,
                $eventId,
                providerRef: isset($object->payment_intent) ? (string) $object->payment_intent : null,
                amount: self::fromCents($object->amount_total ?? null),
                note: 'checkout.session.async_payment_succeeded',
                currency: isset($object->currency) ? strtoupper((string) $object->currency) : null,
            ),
            'checkout.session.async_payment_failed' => new PaymentResult(PaymentEvent::Failed, $eventId, note: 'checkout.session.async_payment_failed'),
            'checkout.session.expired' => new PaymentResult(PaymentEvent::Cancelled, $eventId, note: 'checkout.session.expired'),
            default => new PaymentResult(PaymentEvent::Failed, $eventId, note: 'unhandled '.$event->type),
        };
    }

    #[\Override]
    public function refund(string $paymentRef): PaymentResult
    {
        $refund = $this->client()->refunds->create(
            ['payment_intent' => $paymentRef],
            $this->accountOpts(),
        );

        return new PaymentResult(
            PaymentEvent::Refunded,
            (string) $refund->id,
            providerRef: isset($refund->payment_intent) ? (string) $refund->payment_intent : $paymentRef,
            amount: self::fromCents($refund->amount ?? null),
            note: 'stripe refund issued',
            currency: isset($refund->currency) ? strtoupper((string) $refund->currency) : null,
        );
    }

    #[\Override]
    public function retrieveCheckoutSession(string $sessionId): ?object
    {
        try {
            return $this->client()->checkout->sessions->retrieve(
                $sessionId,
                [],
                $this->accountOpts(),
            );
        } catch (ApiErrorException) {
            return null;
        }
    }

    /**
     * @return array{stripe_account?: string}
     */
    private function accountOpts(): array
    {
        return $this->accountId === null ? [] : ['stripe_account' => $this->accountId];
    }

    private function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->secretKey]);
    }

    /** Cents integer → decimal string; null-safe. */
    private static function fromCents(mixed $cents): ?string
    {
        return $cents === null ? null : bcdiv((string) $cents, '100', 2);
    }
}
