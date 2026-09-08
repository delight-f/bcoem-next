<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\StripeGateway;
use Stripe\ApiRequestor;

/**
 * The P3.5b Stripe adapter against the SAME contract tests as the ticket
 * 12 fake (transport-blindness gate). No live calls: Checkout/expire/
 * refund requests are served by a canned in-memory transport, and webhook
 * payloads are signed locally with the exact Stripe HMAC scheme.
 */
final class StripeGatewayContractTest extends PaymentGatewayContractTestCase
{
    private const SECRET = 'whsec_contract';

    private StripeTestClient $transport;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new StripeTestClient([
            '/v1/checkout/sessions' => StripeTestClient::json([
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'amount_total' => 2500,
                'payment_intent' => 'pi_test_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            ]),
            '/expire' => StripeTestClient::json([
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'status' => 'expired',
            ]),
        ]);
        ApiRequestor::setHttpClient($this->transport);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null); // @phpstan-ignore argument.type (resetting restores the default transport lazily)
    }

    #[\Override]
    protected function adapter(): GatewayAdapter
    {
        return new StripeGateway(
            secretKey: 'sk_test_platform',
            currency: 'usd',
            webhookSecret: self::SECRET,
            accountId: 'acct_connected',
            successUrl: 'https://host.test/pay/callback',
            cancelUrl: 'https://host.test/pay/cancel',
        );
    }

    #[\Override]
    protected function paidPayload(string $eventId, string $providerRef, string $amount): array
    {
        if (! is_numeric($amount)) {
            self::fail('amount must be a decimal string');
        }

        return self::signedPayload('checkout.session.completed', $eventId, [
            'object' => 'checkout.session',
            'payment_intent' => $providerRef,
            'amount_total' => (int) bcmul($amount, '100'),
            'metadata' => [
                'entrant_uid' => '7',
                'entry_ids' => '101-102',
            ],
        ]);
    }

    /** Ledger #6: a failed charge event must never read as success. */
    #[\Override]
    protected function failedPayload(string $eventId): array
    {
        return self::signedPayload('charge.failed', $eventId, [
            'object' => 'charge',
        ]);
    }

    #[\Override]
    protected function refundPayload(string $paymentRef, string $eventId): array
    {
        return self::signedPayload('charge.refunded', $eventId, [
            'object' => 'charge',
            'payment_intent' => $paymentRef,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, string>
     */
    private static function signedPayload(string $type, string $eventId, array $object): array
    {
        $raw = StripeTestClient::event($type, $eventId, $object);

        return ['raw' => $raw, 'signature' => StripeTestClient::sign(self::SECRET, $raw)];
    }

    public function test_checkout_is_created_on_the_connected_account_with_metadata(): void
    {
        $this->adapter()->createCheckout([102, 101], 7, self::FEE_TOTAL);

        $req = $this->transport->requests[0];
        self::assertSame('post', $req['method']);
        // Passthrough: session lives on the host's connected account.
        self::assertStringContainsString('Stripe-Account: acct_connected', implode("\n", $req['headers']));
        self::assertSame('payment', $req['params']['mode']);
        self::assertSame(2500, $req['params']['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('usd', $req['params']['line_items'][0]['price_data']['currency']);
        self::assertSame('7', $req['params']['metadata']['entrant_uid']);
        self::assertSame('101-102', $req['params']['metadata']['entry_ids']);
        self::assertSame('https://host.test/pay/callback', $req['params']['success_url']);
        self::assertSame('https://host.test/pay/cancel', $req['params']['cancel_url']);
    }

    public function test_invalid_signature_fails_closed(): void
    {
        $raw = StripeTestClient::event('checkout.session.completed', 'evt_bad', ['object' => 'checkout.session']);

        $result = $this->adapter()->handleCallback([
            'raw' => $raw,
            'signature' => StripeTestClient::sign('whsec_wrong', $raw),
        ]);

        self::assertSame(PaymentEvent::Failed, $result->event);
        self::assertSame('', $result->eventId);
    }

    public function test_replayed_delivery_with_stale_timestamp_fails_closed(): void
    {
        $raw = StripeTestClient::event('checkout.session.completed', 'evt_replay', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_test_1',
            'amount_total' => 2500,
        ]);

        $result = $this->adapter()->handleCallback([
            'raw' => $raw,
            // Signed an hour ago — outside Stripe's 5-minute tolerance.
            'signature' => StripeTestClient::sign(self::SECRET, $raw, time() - 3600),
        ]);

        self::assertSame(PaymentEvent::Failed, $result->event);
        self::assertSame('', $result->eventId);
    }

    public function test_unhandled_but_verified_event_never_reports_paid(): void
    {
        $payload = self::signedPayload('invoice.paid', 'evt_other', ['object' => 'invoice']);

        $result = $this->adapter()->handleCallback($payload);

        self::assertNotSame(PaymentEvent::Paid, $result->event);
    }

    public function test_refund_call_targets_the_payment_intent_on_the_account(): void
    {
        $this->transport->responses['/v1/refunds'] = StripeTestClient::json([
            'id' => 're_test_1', 'object' => 'refund', 'amount' => 2500, 'payment_intent' => 'pi_test_1',
        ]);

        $result = $this->adapter()->refund('pi_test_1');

        self::assertSame(PaymentEvent::Refunded, $result->event);
        self::assertSame('re_test_1', $result->eventId);
        self::assertSame('pi_test_1', $result->providerRef);
        self::assertSame('25.00', $result->amount);
        $req = $this->transport->requests[count($this->transport->requests) - 1];
        self::assertSame(['payment_intent' => 'pi_test_1'], $req['params']);
    }
}

/**
 * In-memory stand-in for Stripe's HTTP transport. Records every request
 * and serves canned JSON by URL fragment; signs webhooks with Stripe's
 * actual scheme (t=...,v1=hmac_sha256(t.payload)) so the SDK's own
 * verifier runs for real.
 */
