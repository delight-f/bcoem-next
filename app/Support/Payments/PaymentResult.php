<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * One verified gateway callback, transport-blind. $amount is a decimal
 * string ('25.00'); $eventId is the gateway's unique event identifier and
 * feeds PaymentService's dedup surface (payments ledger #7).
 */
final readonly class PaymentResult
{
    public function __construct(
        public PaymentEvent $event,
        public string $eventId,
        public ?string $providerRef = null,
        public ?string $amount = null,
        public string $note = '',
        public ?string $currency = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->event === PaymentEvent::Paid;
    }
}
