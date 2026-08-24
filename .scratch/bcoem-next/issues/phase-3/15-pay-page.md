# P3.5d — Public pay page (entrant pays for their entries)

> Part of spec §5 P3.5 (pay surface). Legacy surface: `pub/pay.pub.php`,
> `includes/stripe_payment.inc.php` (the live fork ALREADY has a Stripe
> payment include — read it as the executable spec for the page shape and
> the paid-state transitions; IPN/PayPal branches in process.inc.php are
> D7-out-of-scope). Money flow: entrant → host (passthrough).

Status: done
Phase: 3 (Slice B)
Depends on: P3.5a (adapter), P3.2d (entries list), P3.5c or P3.5b (at least
one concrete adapter for the E2E — manual first for deterministic tests)
Consumes: ledger/payments.md (#9 fee snapshot), ledger/contest-info.md (pay window)

## Goal

The logged-in entrant sees their unpaid entries with the owed fee total
(per-entry `contestEntryFee`, batch `total_fees` snapshot) and a Pay button
that routes through the active gateway (`GatewayAdapter`), then lands on
success/cancel states that reflect the resulting `brewPaid` flags.

## Scope

- Page shape from `pay.pub.php`: unpaid entries list (exclude paid), fee
  total, method selection if multiple adapters enabled (Stripe vs manual —
  manual is admin-side; the public page shows online payment only; if no
  gateway is configured show the legacy "payments not available" state —
  pin the exact legacy condition).
- Pay window gating: `pay_window_open` from contest-info ledger (entry-open
  → last judging date; disable_pay = all windows closed).
- Checkout flow: `GatewayAdapter::createCheckout` for the batch → redirect;
  success/cancel URLs render the post-payment state (paid badges lit) and
  the legacy confirmation text (parity surface).
- Idempotent re-entry: a user who already paid everything sees "no unpaid
  entries" — no duplicate checkout sessions.
- Free competition (`contestEntryFee==0`): entries auto-paid at creation
  (ledger #2) — the page should be unreachable/empty; verify legacy.

## Deliverables

1. PayController (list + checkout redirect + success/cancel) + view.
2. Feature tests: unpaid list + totals; checkout redirect with correct
   batch + amount; success flips flags via service; cancel leaves unpaid;
   already-paid → empty state; window gating.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Full loop on a corpus dump: create entry → pay (manual adapter or mocked
   Stripe) → `brewPaid=1` → list badge lit, pay page empty.
