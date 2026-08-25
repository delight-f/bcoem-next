# P4.3 — Judging assignments UI (judge/steward assignment, availability)

> Part of spec §6 P4.3. Legacy surface: `admin/judging_assign.php`,
> `admin/judging_flights.php`, `pub/judge.php`, `pub/judge_info.php`,
> `pub/judge_closed.php`.

Status: done
Phase: 4 (Slice C)
Depends on: P4.2 (engine), Slice B auth/session
Consumes: ledger/flight-assignment.md (judge preference semantics)

## Goal

Admin screens for assigning judges/stewards to tables/flights, honoring
judge availability and preference rows written by the judge signup pages.

## Scope

- Judge signup public pages (`judge`, `judge_info`, `judge_closed`) with
  preference capture identical to legacy.
- Admin assign UI driving the P4.2 engine; conflict/preference violations
  surfaced as legacy does.

## Deliverables

1. Public judge pages + admin assignment screens.
2. Feature tests: preference capture; assignment round-trip; closed-state
   gating matches reg_open/closed conventions.

## Acceptance

- Parity on judge public URLs across corpus dumps.
- Test suite green; PHPStan 0; Pint clean.
