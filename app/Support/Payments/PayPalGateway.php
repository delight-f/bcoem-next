<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

/**
 * PayPal Orders v2 adapter (issue #24), wrapping the maintained
 * srmklive/paypal SDK rather than hand-rolling the REST calls.
 *
 * Money flow: the entrant pays the competition HOST's PayPal account; there is
 * no platform intermediation and the port never holds funds.
 *
 * Hosted approval: createCheckout() returns PayPal's `approve` HATEOAS link and
 * the browser leaves the site for PayPal's own page. PayPal requires an explicit
 * capture afterwards, so captureOnReturn() performs it when the browser lands
 * back on /pay/callback. The signature-verified webhook remains the ONLY writer
 * of local payment state (payments ledger #6) — the return leg merely selects
 * the UX message (msg=13 vs msg=14).
 *
 * Constructed with a provider instance so tests can inject
 * Srmklive\PayPal\Testing\MockPayPalClient via setClient() — no live calls.
 * Deliberately not final: tests subclass to stub signatureValid().
 */
class PayPalGateway implements CapturableOnReturn, GatewayAdapter
{
    /**
     * PayPal-supported settlement currencies; the SDK throws on anything else
     * (see PayPalRequest::setCurrency()). INR is PayPal India domestic-only.
     */
    private const CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'ILS', 'INR', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN',
        'SEK', 'SGD', 'THB', 'TWD', 'USD',
    ];

    /** Access token fetched lazily once per instance (valid ~9h at PayPal). */
    private bool $authenticated = false;

    public function __construct(
        private readonly PayPalClient $provider,
        private readonly string $webhookId = '',
        private readonly string $currency = 'USD',
        private readonly string $successUrl = '',
        private readonly string $cancelUrl = '',
    ) {
        // Normalise API failures into thrown exceptions so callers fail closed
        // instead of inspecting an ['error' => ...] array.
        $this->provider->withExceptions();
    }

    /**
     * Build from this install's env config (D3). Currency resolves from
     * PAYPAL_CURRENCY first (an explicit ISO code), then the tenant's own
     * currency; an unsupported code leaves $currency empty and createCheckout()
     * refuses rather than charging in the wrong currency.
     */
    public static function forTenant(string $successUrl = '', string $cancelUrl = ''): self
    {
        $settings = PayPalSettings::get();
        $mode = $settings['mode'];
        $currency = self::resolveCurrency();

        return new self(
            new PayPalClient([
                'mode' => $mode,
                $mode => [
                    'client_id' => $settings['client_id'],
                    'client_secret' => $settings['client_secret'],
                    'app_id' => self::appId($mode),
                ],
                'payment_action' => 'Sale',
                // SDK constructor validates currency; the real code is enforced
                // in createCheckout() via $this->currency.
                'currency' => $currency !== '' ? $currency : 'USD',
                'notify_url' => '',
                'locale' => 'en_US',
                'validate_ssl' => true,
            ]),
            $settings['webhook_id'],
            $currency,
            $successUrl,
            $cancelUrl,
        );
    }

    /**
     * True when PayPal has everything it needs — credentials saved through the
     * admin setup screen (PayPalSettings) or supplied via env.
     */
    public static function configured(): bool
    {
        return PayPalSettings::configured();
    }

    /**
     * @param  list<int>  $entries
     */
    public static function attribution(int $entrantUid, array $entries): string
    {
        return $entrantUid.'|'.implode('-', array_map(intval(...), $entries));
    }

    /**
     * Inverse of attribution(); null when the value is absent or unusable.
     *
     * @return array{uid: int, entries: list<int>}|null
     */
    public static function parseAttribution(?string $customId): ?array
    {
        if ($customId === null || ! str_contains($customId, '|')) {
            return null;
        }

        [$uid, $ids] = explode('|', $customId, 2);
        $entries = array_values(array_filter(array_map(intval(...), explode('-', $ids))));

        return $entries === [] ? null : ['uid' => (int) $uid, 'entries' => $entries];
    }

    #[\Override]
    public function method(): string
    {
        return PaymentService::METHOD_PAYPAL;
    }

    #[\Override]
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
    {
        if ($this->currency === '') {
            throw new \InvalidArgumentException('PayPal is not configured for this competition currency.');
        }

        if (! is_numeric($feeTotal)) {
            throw new \InvalidArgumentException('Fee snapshot must be a decimal string.');
        }

        sort($entries);
        $this->authenticate();

        $response = $this->provider->withIdempotencyKey()->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $this->currency,
                    // Fee snapshot is a decimal string; keep BCMath, no floats.
                    'value' => bcadd($feeTotal, '0', 2),
                ],
                'description' => 'Competition entry fees',
                // webhook attribution: entrant uid + brewing.id batch.
                'custom_id' => self::attribution($entrantUid, $entries),
            ]],
            'application_context' => [
                'return_url' => $this->successUrl,
                'cancel_url' => $this->cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ]);

        $orderId = is_array($response) ? (string) ($response['id'] ?? '') : '';
        $approve = self::link($response, 'approve') ?? self::link($response, 'payer-action');

        if ($orderId === '' || $approve === null) {
            throw new \RuntimeException('PayPal order creation did not return an approval link.');
        }

        return new Checkout($orderId, $approve);
    }

    /**
     * Explicit capture on the browser return (issue #24 Task 4, pattern b).
     * A repeat/refresh can raise ORDER_ALREADY_CAPTURED, so a failed capture
     * falls back to reading the order status — already COMPLETED counts as
     * settled. Never writes local state.
     */
    #[\Override]
    public function captureOnReturn(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        try {
            $this->authenticate();
            $response = $this->provider->withIdempotencyKey()->capturePaymentOrder($reference);

            if (is_array($response) && (string) ($response['status'] ?? '') === 'COMPLETED') {
                return true;
            }
        } catch (\Throwable) {
            // fall through to the order-status check below
        }

        try {
            $order = $this->provider->showOrderDetails($reference);

            return is_array($order) && (string) ($order['status'] ?? '') === 'COMPLETED';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify one webhook delivery and map it to a PaymentResult. Signature
     * verification is local RSA-SHA256 against PayPal's cert (SSRF-guarded,
     * offline after the first fetch) — no API round-trip, no soft-false.
     *
     * @param  array<string, mixed>  $payload  ['raw' => body, 'headers' => [...]]
     */
    #[\Override]
    public function handleCallback(array $payload): PaymentResult
    {
        $raw = (string) ($payload['raw'] ?? '');
        /** @var array<string, string> $headers */
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        if (! $this->signatureValid($headers, $raw)) {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'signature verification failed');
        }

        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'malformed webhook payload');
        }

        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['event_type'] ?? '');
        /** @var array<string, mixed> $resource */
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => new PaymentResult(
                PaymentEvent::Paid,
                $eventId,
                providerRef: (string) ($resource['id'] ?? ''),
                amount: self::amount($resource),
                note: $type,
                currency: self::currency($resource),
            ),
            // A reversed capture is PayPal clawing funds back — treat as refund.
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => new PaymentResult(
                PaymentEvent::Refunded,
                $eventId,
                providerRef: self::refundedCaptureRef($resource),
                amount: self::amount($resource),
                note: $type,
                currency: self::currency($resource),
            ),
            default => new PaymentResult(PaymentEvent::Failed, $eventId, note: 'unhandled '.$type),
        };
    }

    /**
     * Refund a settled capture. The SDK requires an amount, which comes from
     * our own ledger snapshot (payments.amount) — not from the caller.
     */
    #[\Override]
    public function refund(string $paymentRef): PaymentResult
    {
        if ($paymentRef === '') {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'missing provider reference');
        }

        $amount = (string) (DB::table('payments')->where('provider_ref', $paymentRef)->value('amount') ?? '');

        if (! is_numeric($amount)) {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'unknown payment reference');
        }

        try {
            $this->authenticate();
            $response = $this->provider->refundCapturedPayment(
                $paymentRef,
                'refund-'.$paymentRef,
                (float) $amount,
                'refunded via admin payments screen',
            );
        } catch (\Throwable) {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'paypal refund failed');
        }

        if (! is_array($response) || (string) ($response['status'] ?? '') !== 'COMPLETED') {
            return new PaymentResult(PaymentEvent::Failed, '', note: 'paypal refund not completed');
        }

        return new PaymentResult(
            PaymentEvent::Refunded,
            (string) ($response['id'] ?? ''),
            providerRef: $paymentRef,
            amount: bcadd($amount, '0', 2),
            note: 'paypal refund issued',
        );
    }

    /**
     * Production verification seam. Overridden in tests so no cert fetch or
     * signer keypair is needed.
     *
     * @param  array<string, string>  $headers
     */
    protected function signatureValid(array $headers, string $raw): bool
    {
        if ($this->webhookId === '' || $raw === '') {
            return false;
        }

        try {
            return $this->provider->verifyWebHookLocally($headers, $this->webhookId, $raw);
        } catch (\Throwable) {
            return false;
        }
    }

    private function authenticate(): void
    {
        if ($this->authenticated) {
            return;
        }

        $token = $this->provider->getAccessToken();

        if (! is_array($token) || ! isset($token['access_token'])) {
            throw new \RuntimeException('PayPal access token request failed.');
        }

        $this->authenticated = true;
    }

    /**
     * @param  mixed  $response  SDK response (array on success; stream/string unions)
     */
    private static function link(mixed $response, string $rel): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        foreach ((array) ($response['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === $rel && is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private static function amount(array $resource): ?string
    {
        $value = $resource['amount']['value'] ?? null;

        return is_numeric($value) ? bcadd((string) $value, '0', 2) : null;
    }

    /** @param array<string, mixed> $resource */
    private static function currency(array $resource): ?string
    {
        $amount = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
        $code = (string) ($amount['currency_code'] ?? $resource['currency_code'] ?? '');

        return $code !== '' ? strtoupper($code) : null;
    }

    /**
     * PayPal's refund resource links `up` to the capture it reverses; that
     * capture id is what payments.provider_ref stores.
     *
     * @param  array<string, mixed>  $resource
     */
    private static function refundedCaptureRef(array $resource): string
    {
        foreach ((array) ($resource['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'up' && is_string($link['href'] ?? null)) {
                $path = parse_url($link['href'], PHP_URL_PATH);
                $id = is_string($path) ? basename($path) : '';

                if ($id !== '') {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * PAYPAL_CURRENCY wins when set (an explicit ISO code); otherwise the
     * tenant's own currency. An unsupported code yields '' and createCheckout()
     * refuses rather than charging in the wrong currency.
     */
    private static function resolveCurrency(): string
    {
        $code = strtoupper((string) (Config::get('services.paypal.currency') ?: self::tenantCurrency()));

        return in_array($code, self::CURRENCIES, true) ? $code : '';
    }

    /** Sandbox keeps PayPal's fixed app id unless one is configured. */
    private static function appId(string $mode): string
    {
        $configured = (string) (Config::get('services.paypal.app_id') ?? '');

        if ($configured !== '') {
            return $configured;
        }

        return $mode === 'sandbox' ? 'APP-80W284485P519543T' : '';
    }

    private static function tenantCurrency(): string
    {
        try {
            /** @var array<string, mixed> $prefs */
            $prefs = (array) DB::table('preferences')->where('id', 1)->first();
        } catch (\Throwable) {
            return 'usd';
        }

        $stripe = json_decode((string) ($prefs['prefsStripe'] ?? ''), true);
        $code = (string) (is_array($stripe) && ($stripe['currency'] ?? null) ? $stripe['currency'] : ($prefs['prefsCurrency'] ?? ''));

        return strtoupper($code !== '' ? $code : 'usd');
    }
}
