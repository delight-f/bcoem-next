<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * A started checkout. $redirectUrl is the gateway hosted page (null for
 * adapters that have no hosted flow, e.g. manual marking).
 */
final readonly class Checkout
{
    public function __construct(
        public string $checkoutId,
        public ?string $redirectUrl,
    ) {}
}
