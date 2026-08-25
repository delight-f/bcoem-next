# P5.5 — Participants / payments / entries admin (+by_style reports)

> Part of spec §7 P5.5. Legacy surface: participants.admin.php,
> payments.admin.php, entries.admin.php, entries_by_style.admin.php,
> entries_by_substyle.admin.php, plus upload/upload_scoresheets admin if not
> already covered by Slice B.

Status: pending
Phase: 5 (Slice D)
Depends on: Slice B registration/payments data model
Consumes: —

## Goal

The operational back-office: browse/edit entrants, mark payments manually,
manage entries, and the two style-aggregation report views.

## Scope

- participants: list/search/edit brewers; delete cascades per legacy
  (document what it destroys).
- payments: manual payment marking converging to the same payments +
  brewPaid/brewConfirmed rows as Stripe path (spec §8.3 exception applies).
- entries: admin edit of brewing rows incl. style re-assignment using
  ledger/styles.md normalization rules; dropoff/received state changes.
- by_style / by_substyle: aggregated counts view — pure read, good parity
  candidate.

## Deliverables

1. Controllers + views for the five screens; routes in routes/admin.php.
2. Feature tests: payment marking convergence test; entry style-change
   normalization; by_style counts against seeded fixture.

## Acceptance

- Suite green; payments convergence test proves DB-state identity with the
  Stripe-path result for the same input.
