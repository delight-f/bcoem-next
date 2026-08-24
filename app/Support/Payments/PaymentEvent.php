<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Gateway outcome of a single verified callback event (payments ledger #6:
 * only explicit success events may mark entries paid — never a status-text
 * ladder). Adapters translate transport payloads into these; PaymentService
 * applies them.
 */
enum PaymentEvent: string
{
    case Paid = 'paid';

    case Refunded = 'refunded';

    case Failed = 'failed';

    case Cancelled = 'cancelled';
}
