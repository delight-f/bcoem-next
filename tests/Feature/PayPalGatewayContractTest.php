<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PayPalGateway;
use Psr\Http\Message\RequestInterface;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Srmklive\PayPal\Testing\MockPayPalClient;

/**
 * The issue #24 PayPal adapter against the SAME contract tests as the Stripe
 * and fake adapters (transport-blindness gate). No live calls: the SDK's
 * PSR-18 client is swapped for Srmklive\PayPal\Testing\MockPayPalClient, and
 * the signature check is stubbed via PayPalGateway::signatureValid().
 */
final class PayPalGatewayContractTest extends PaymentGatewayContractTestCase
{
    private MockPayPalClient $mock;

    #[\Override]
    protected function adapter(): GatewayAdapter
    {
        return $this->gatewayWith([self::orderResponse()]);
    }

    #[\Override]
    protected function paidPayload(string $eventId, string $providerRef, string $amount): array
    {
        return self::payload('PAYMENT.CAPTURE.COMPLETED', $eventId, [
            'id' => $providerRef,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => $amount],
            'custom_id' => '7|101-102',
        ]);
    }

    /** Ledger #6: a denied capture must never read as success. */
    #[\Override]
    protected function failedPayload(string $eventId): array
    {
        return self::payload('PAYMENT.CAPTURE.DENIED', $eventId, ['id' => 'pay_c_f']);
    }

    #[\Override]
    protected function refundPayload(string $paymentRef, string $eventId): array
    {
        // PayPal's refund resource links `up` to the reversed capture.
        return self::payload('PAYMENT.CAPTURE.REFUNDED', $eventId, [
            'links' => [[
                'rel' => 'up',
                'href' => 'https://api-m.paypal.com/v2/payments/captures/'.$paymentRef,
            ]],
        ]);
    }

    public function test_checkout_sends_attributed_order_and_returns_approval_link(): void
    {
        $checkout = $this->gatewayWith([self::orderResponse()])->createCheckout([102, 101], 7, '25.00');

        self::assertSame('ORDER-1', $checkout->checkoutId);
        self::assertStringContainsString('checkoutnow', (string) $checkout->redirectUrl);

        // Request 0 is the OAuth token, request 1 the order create.
        $body = self::requestBody($this->mock->requests()[1]);

        self::assertSame('CAPTURE', $body['intent']);
        self::assertSame('USD', $body['purchase_units'][0]['amount']['currency_code']);
        self::assertSame('25.00', $body['purchase_units'][0]['amount']['value']);
        self::assertSame('7|101-102', $body['purchase_units'][0]['custom_id']);
        self::assertSame('https://host.test/pay/callback?provider=paypal', $body['application_context']['return_url']);
        self::assertSame('https://host.test/pay/cancel', $body['application_context']['cancel_url']);
    }

    public function test_capture_on_return_settles_when_paypal_reports_completed(): void
    {
        self::assertTrue($this->gatewayWith([['id' => 'CAP-1', 'status' => 'COMPLETED']])->captureOnReturn('ORDER-1'));
    }

    public function test_capture_on_return_treats_already_captured_order_as_settled(): void
    {
        // Repeat/refresh: capture 422s, order status already COMPLETED.
        $gateway = $this->gatewayWith([
            ['error' => 'ORDER_ALREADY_CAPTURED'],
            422,
            ['id' => 'ORDER-1', 'status' => 'COMPLETED'],
        ]);

        self::assertTrue($gateway->captureOnReturn('ORDER-1'));
    }

    public function test_capture_on_return_fails_closed_when_nothing_settles(): void
    {
        $gateway = $this->gatewayWith([
            ['error' => 'INSTRUMENT_DECLINED'],
            422,
            ['error' => 'RESOURCE_NOT_FOUND'],
            404,
        ]);

        self::assertFalse($gateway->captureOnReturn('ORDER-1'));
    }

    public function test_invalid_signature_fails_closed(): void
    {
        $result = $this->gatewayWith([], valid: false)->handleCallback($this->paidPayload('evt_bad', 'pay_1', '25.00'));

        self::assertSame(PaymentEvent::Failed, $result->event);
        self::assertSame('', $result->eventId);
    }

    public function test_unhandled_but_verified_event_never_reports_paid(): void
    {
        $result = $this->gatewayWith([])->handleCallback(
            self::payload('BILLING.SUBSCRIPTION.CREATED', 'evt_other', ['id' => 'I-1']),
        );

        self::assertNotSame(PaymentEvent::Paid, $result->event);
    }

    public function test_attribution_round_trips(): void
    {
        self::assertSame(
            ['uid' => 7, 'entries' => [101, 102]],
            PayPalGateway::parseAttribution(PayPalGateway::attribution(7, [101, 102])),
        );
        self::assertNull(PayPalGateway::parseAttribution('no-separator'));
    }

    /**
     * Build a gateway whose SDK transport is the in-memory mock. A token
     * response is always queued first so authenticate() succeeds offline.
     *
     * @param  list<array<string, mixed>|int>  $responses  alternating bodies and status codes
     */
    private function gatewayWith(array $responses, bool $valid = true): PayPalGateway
    {
        $this->mock = new MockPayPalClient;
        $this->mock->addResponse(['access_token' => 'mock-token', 'token_type' => 'Bearer']);

        for ($i = 0; $i < count($responses); $i += 2) {
            /** @var array<string, mixed> $body */
            $body = $responses[$i];
            $status = is_int($responses[$i + 1] ?? null) ? (int) $responses[$i + 1] : 200;
            $this->mock->addResponse($body, $status);
        }

        return $this->makeGateway($this->mock->mockProvider(), $valid);
    }

    private function makeGateway(PayPalClient $provider, bool $valid): PayPalGateway
    {
        return new class($provider, $valid) extends PayPalGateway
        {
            public function __construct(PayPalClient $provider, private readonly bool $valid)
            {
                parent::__construct(
                    $provider,
                    'whsec_contract',
                    'USD',
                    'https://host.test/pay/callback?provider=paypal',
                    'https://host.test/pay/cancel',
                );
            }

            protected function signatureValid(array $headers, string $raw): bool
            {
                return $this->valid;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private static function payload(string $type, string $eventId, array $resource): array
    {
        return [
            'raw' => (string) json_encode([
                'id' => $eventId,
                'event_type' => $type,
                'resource' => $resource,
            ]),
            'headers' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderResponse(): array
    {
        return [
            'id' => 'ORDER-1',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api-m.paypal.com/v2/checkout/orders/ORDER-1'],
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestBody(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
