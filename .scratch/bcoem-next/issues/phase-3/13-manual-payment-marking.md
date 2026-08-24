# P3.5c — Manual payment marking (admin confirms host collection)

> Part of spec §5 P3.5c. Money flow: entrant pays the HOST off-line
> (check-by-mail, cash/check at dropoff, bank transfer); the admin marks the
> entry paid, recording method + reference + audit trail. Same `payments`
> rows + flag lifecycle as the Stripe path (P3.5b) so downstream code is
> transport-blind (adapter contract, P3.5a).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.5a (adapter + state machine)
Consumes: ledger/payments.md, ledger/entry-lifecycle.md (#3, #12)

## Goal

Admin UI on the entries admin surface: per unpaid entry (or batch), "Mark
paid" with method (check/cash/dropoff/bank-transfer), reference/note, and an
audit trail — all through the same `PaymentService` so the resulting rows are
identical to a Stripe success.

## Scope

- Admin surface (P5.5 owns the full admin entries view — this ticket only
  needs a minimal authenticated admin route + form; keep it small, the full
  admin page comes later).
- Fields: entry ids (single or batch), method enum, reference (check number/
  note), optional note; audit fields (admin uid, timestamp) on the
  `payments` row.
- Gating: admin `userLevel<=1` only; cannot mark paid an entry that is
  already paid (idempotent no-op or explicit error — pin behavior).
- Convergence test: run manual marking and a fake Stripe success through the
  same service; assert byte-identical `payments` + `brewing` rows (modulo
  provider-specific columns).
- The pay-flag UI on the public side (`brewPaid` badge in P3.2d) must light
  up with no code changes — that is the transport-blindness proof.

## Deliverables

1. ManualPaymentController (admin-only route) + minimal form + audit
   columns in the `payments` DDL (P3.5a migration).
2. Feature tests: mark single + batch; duplicate marking; method/reference
   persisted; audit trail; non-admin rejected.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Convergence test passes: manual marking and Stripe success (fake) produce
  identical `payments`/`brewing` state.
