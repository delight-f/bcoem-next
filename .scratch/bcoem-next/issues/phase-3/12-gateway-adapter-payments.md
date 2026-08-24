# P3.5a — Gateway adapter + payment state machine (money flow: entrant → host)

> Part of spec §5 P3.5a. **Money-flow contract (owner, 2026-08-24):** the
> whole point of payments is entrants paying the competition HOST for their
> beer-entry fees — a passthrough. The platform never holds funds. Stripe
> Connect Standard accounts make the host the merchant of record (funds
> settle directly to the host); manual marking records the host confirming
> off-line collection (check/cash/dropoff/bank transfer). Both paths MUST
> converge to identical `payments` rows + `brewing.brewPaid`/`brewConfirmed`
> flag state (Parity Gate §8 exception: DB state, not page diffs).

> **Schema fact (payments ledger #1):** the `payments` table does NOT exist
> in the baseline or corpus schemas; legacy had NO working payment ledger
> (IPN inserts silently failed). The port DESIGNS the real ledger table —
> this is an approved deviation from D2 (verbatim schema). DDL ships in a
> migration that only creates `payments` (+ nothing else); document the
> deviation in ledger/payments.md.

Status: done
Phase: 3 (Slice B)
Depends on: P3.3a (entries exist to pay for), P1.4 (payments ledger done)
Consumes: ledger/payments.md (#1–#9), ledger/entry-lifecycle.md (#12)

## Goal

A transport-blind payment core: one `GatewayAdapter` interface
(create-checkout for an entry batch / verify+receive state callback /
refund-cancel hooks) and one state machine that converts gateway events into
`payments` rows + entry flag flips, idempotently, usable by BOTH the Stripe
adapter (P3.5b) and manual marking (P3.5c).

## Scope

- **GatewayAdapter interface** (single contract per spec P3.5a):
  - `createCheckout(entries, entrant, feeTotal): Checkout` — fee total from
    the entry-creation snapshot (`total_fees`, payments ledger #9); the
    port should reconcile posted amounts vs owed fees (ledger #9 deviation
    note — legacy under-charging was possible).
  - `handleCallback(payload): PaymentResult` (signature-verified webhook)
  - `refund(callback/paymentId)`, `cancel(checkoutId)` hooks.
- **State machine** (the "webhook/manual-driven state changes" from D7):
  - Success ⇒ per entry: `UPDATE brewing SET brewPaid=1, brewConfirmed=1,
    brewUpdated=NOW()` — converge with legacy IPN shape (#5: legacy wrote
    brewPaid+brewUpdated only; **confirm** flag addition is a port decision
    — pin it here) and insert a `payments` row (entrant uid, entry ids,
    amount, method, reference, timestamps).
  - Idempotency: dedupe on gateway event id / Stripe event sequence (#7);
    re-delivery must not double-insert or double-flip.
  - Failure/cancel: reverse or no-op per explicit success-event semantics
    (#6 — never key off `payment_status` text; only verified success events
    mark paid).
  - Refund path (#8): legacy had none; design `payments.status=refunded` +
    flag reversal ONLY on verified refund events — document as new behavior.
- **`payments` table DDL** (new): columns for entrant, entries (JSON or
    normalized child rows — decide and pin), amount, currency, method,
    provider ref, event id (unique), status, timestamps, audit fields.
- DB-gated + unit tests: adapter contract tests with a fake adapter;
  state-machine tests covering success, duplicate event, refund, failed
  callback, amount mismatch.

## Deliverables

1. `GatewayAdapter` interface + `PaymentService` state machine + `payments`
   migration.
2. Contract + state-machine tests (fake adapter proves transport-blindness).

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Both adapters (P3.5b Stripe, P3.5c manual) pass the SAME contract tests —
   that is the acceptance for "transport-blind".
- Ledger/payments.md updated: table DDL decision, confirm-flag decision,
   refund semantics, amount-reconciliation behavior.
