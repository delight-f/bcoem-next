<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\PaymentService;
use App\Support\Payments\PayPalGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * PayPal webhook endpoint (issue #24 P5): POST /webhooks/paypal. No auth
 * middleware — authenticity comes from the locally verified signature inside
 * PayPalGateway::handleCallback() (fail closed). Dedup on event id, ledger
 * write and flag flips all happen in PaymentService::apply() (ledger #6/#7).
 */
final class PayPalWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $raw = $request->getContent();

        // Container-bound in tests (signature verifier stubbed); production
        // builds the tenant gateway from config.
        $gateway = app()->bound(PayPalGateway::class) ? app(PayPalGateway::class) : PayPalGateway::forTenant();

        $result = $gateway->handleCallback([
            'raw' => $raw,
            'headers' => self::headers($request),
        ]);

        // Unverifiable payload: 4xx so PayPal retries and the misconfiguration
        // surfaces. Nothing is applied.
        if ($result->eventId === '') {
            return response('invalid signature', Response::HTTP_BAD_REQUEST);
        }

        // Attribution lives on the purchase unit's custom_id, written by
        // createCheckout(). A verified Paid event without it (a foreign capture
        // on the same account) must not mark anything — fail closed.
        $attribution = PayPalGateway::parseAttribution(self::customId($raw));

        if ($result->isPaid() && $attribution === null) {
            return response('unattributed paid event', Response::HTTP_BAD_REQUEST);
        }

        $applied = $this->payments->apply(
            $result,
            $attribution['entries'] ?? [],
            $attribution['uid'] ?? 0,
            PaymentService::METHOD_PAYPAL,
            (string) ($result->amount ?? '0'),
        );

        // 2xx either way: verified-but-unapplied (duplicate, unhandled type) is
        // a successful delivery from PayPal's point of view.
        return response($applied ? 'applied' : 'no-op');
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    private static function customId(string $raw): ?string
    {
        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return null;
        }

        $resource = $event['resource'] ?? null;

        if (! is_array($resource)) {
            return null;
        }

        $custom = $resource['custom_id'] ?? null;

        return is_string($custom) ? $custom : null;
    }
}
