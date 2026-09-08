<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use PHPUnit\Framework\TestCase;

/**
 * Transport-blindness gate (spec P3.5a): EVERY GatewayAdapter — the ticket
 * 12 fake, P3.5b Stripe, P3.5c manual — must pass these SAME contract
 * tests. Adapters only translate payloads to PaymentResult; all state and
 * idempotency live in PaymentService.
 *
 * Subclasses supply the adapter plus payload builders in its native shape;
 * assertions here are transport-agnostic.
 */
abstract class PaymentGatewayContractTestCase extends TestCase
{
    /** @var list<int> */
    protected const ENTRIES = [101, 102];

    protected const ENTRANT_UID = 7;

    protected const FEE_TOTAL = '25.00';

    abstract protected function adapter(): GatewayAdapter;

    /** @return array<string, mixed> */
    abstract protected function paidPayload(string $eventId, string $providerRef, string $amount): array;

    /** @return array<string, mixed> */
    abstract protected function failedPayload(string $eventId): array;

    /** @return array<string, mixed> */
    abstract protected function refundPayload(string $paymentRef, string $eventId): array;

    public function test_adapter_self_describes_its_transport(): void
    {
        self::assertNotSame('', $this->adapter()->method());
    }

    public function test_create_checkout_returns_id_and_destination(): void
    {
        $checkout = $this->adapter()->createCheckout(self::ENTRIES, self::ENTRANT_UID, self::FEE_TOTAL);

        self::assertNotSame('', $checkout->checkoutId);
        // Hosted-flow adapters give a redirect target; manual marking may
        // return null (nothing remote to visit).
        if ($checkout->redirectUrl !== null) {
            self::assertNotSame('', $checkout->redirectUrl);
        }
    }

    public function test_success_callback_yields_paid_result(): void
    {
        $result = $this->adapter()->handleCallback(
            $this->paidPayload('evt_c_1', 'pay_c_1', self::FEE_TOTAL),
        );

        self::assertSame(PaymentEvent::Paid, $result->event);
        self::assertSame('evt_c_1', $result->eventId);
        self::assertNotNull($result->providerRef);
        self::assertNotNull($result->amount);
    }

    public function test_failure_callback_never_reports_paid(): void
    {
        // Ledger #6: failed/pending notifications must NOT mark paid.
        $result = $this->adapter()->handleCallback($this->failedPayload('evt_c_f'));

        self::assertNotSame(PaymentEvent::Paid, $result->event);
    }

    public function test_refund_callback_reports_refunded_with_original_ref(): void
    {
        $result = $this->adapter()->handleCallback(
            $this->refundPayload('pay_c_1', 'evt_c_r'),
        );

        self::assertSame(PaymentEvent::Refunded, $result->event);
        self::assertSame('pay_c_1', $result->providerRef);
        self::assertSame('evt_c_r', $result->eventId);
    }
}
