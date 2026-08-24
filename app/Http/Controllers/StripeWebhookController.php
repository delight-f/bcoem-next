<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\PaymentService;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Stripe webhook endpoint (P3.5b): POST /webhooks/stripe. No auth
 * middleware — authenticity comes from the signature check inside
 * StripeGateway::handleCallback() (fail closed, ledger #4/#6). Dedup on
 * event id happens in PaymentService::apply() (ledger #7).
 */
final class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $raw = $request->getContent();
        $gateway = StripeGateway::forTenant();

        $result = $gateway->handleCallback([
            'raw' => $raw,
            'signature' => (string) $request->headers->get('Stripe-Signature', ''),
        ]);

        // Unverifiable payload: reject with 4xx so Stripe keeps retrying /
        // the dashboard surfaces the misconfiguration. Nothing is applied.
        if ($result->eventId === '') {
            return response('invalid signature', Response::HTTP_BAD_REQUEST);
        }

        // Attribution lives in the Checkout Session metadata written by
        // createCheckout(); refund events key off provider_ref and need none.
        $meta = self::metadata($raw);

        $applied = $this->payments->apply(
            $result,
            array_values(array_filter(array_map('intval', explode('-', (string) ($meta['entry_ids'] ?? ''))))),
            (int) ($meta['entrant_uid'] ?? 0),
            $gateway->method(),
            (string) ($result->amount ?? '0'),
        );

        // 2xx either way: verified-but-unapplied (duplicate, unhandled type)
        // is a successful delivery from Stripe's point of view.
        return response($applied ? 'applied' : 'no-op');
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(string $raw): array
    {
        $event = json_decode($raw, true);

        return is_array($event) ? ($event['data']['object']['metadata'] ?? []) : [];
    }
}
