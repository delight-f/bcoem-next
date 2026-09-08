# P3.5b — Stripe Connect adapter (host collects directly — passthrough)

> Part of spec §5 P3.5b. Money flow: entrant pays the HOST; the host
> self-onboards as a **Stripe Standard connected account** and Checkout funds
> settle directly to that account — the platform is never the merchant of
> record (passthrough per owner decision 2026-08-24). Tested with Stripe
> test mode + mocked events — NOT parity-diffed (external side effects).

Status: done
Phase: 3 (Slice B)
Depends on: P3.5a (adapter contract + state machine)
Consumes: ledger/payments.md

## Goal

A working `GatewayAdapter` implementation: tenant OAuth onboarding (Connect
Standard), Checkout session per entry batch, signature-verified webhook
endpoint feeding `PaymentService`, per-tenant webhook secret, idempotent
event handling.

## Scope

- **Tenant onboarding**: Connect Standard OAuth flow — tenant clicks
  "Connect Stripe" in (a minimal) admin settings route, platform receives
  `stripe_user_id` (connected account), stored per tenant (where? — a
  `tenant_settings`-style row or preferences JSON; decide + pin; no new
  tables beyond `payments` if avoidable — check preferences has a free
  column or document the deviation).
- **Checkout session**: per entry batch from the pay page (P3.5d), amount =
  owed fees (reconcile vs `total_fees` snapshot, ledger #9), metadata =
  tenant id + entrant uid + entry ids, success/cancel URLs → pay page
  states.
- **Webhook endpoint** (`POST /webhooks/stripe`): signature verification
  with per-tenant secret; map `checkout.session.completed` (success) and
  `charge.refunded`/`checkout.session.expired` (cancel/refund) into
  `PaymentService` calls; dedupe on Stripe event id (ledger #7).
- **Test mode**: `.env` keys, test-mode webhook signing, and a mocked-event
  test harness (Laravel Http fake / Stripe SDK mock) — no live calls in
  tests.
- Decision: use `stripe/stripe-php` (add dependency — first new runtime dep
  of the slice; justified by provider SDK) or raw HTTP + signature parsing.
  Recommend the SDK; pin the choice + version in the ticket close.

## Deliverables

1. StripeAdapter implementing `GatewayAdapter`; onboarding controller;
   webhook route+controller.
2. Tests: mocked Checkout creation, signature verification (valid/invalid/
   replay), completed → paid+confirmed, refund → reversal, duplicate event
   deduped.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Contract tests from P3.5a pass against the Stripe adapter (the
   transport-blindness gate).
- Manual smoke: a test-mode Checkout created against the tenant's connected
   account; webhook replay converges to `payments` + `brewPaid=1`.
