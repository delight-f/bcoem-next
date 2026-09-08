<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Checkout-session introspection for gateways with a hosted page
 * (payments plan W2/review): the success return confirms payment state
 * server-side rather than trusting the redirect. Stripe implements it;
 * gateways without hosted sessions (manual) do not.
 */
interface SessionCheckout
{
    /**
     * Fetch one checkout session from the gateway. Returns null when the
     * session cannot be retrieved (unknown id, API error).
     *
     * @return object|null shape is gateway-specific; Stripe exposes
     *                     payment_status on Checkout Sessions
     */
    public function retrieveCheckoutSession(string $sessionId): ?object;
}
