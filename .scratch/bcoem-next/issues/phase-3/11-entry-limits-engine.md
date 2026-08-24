# P3.4 — Entry limits engine

> Part of spec §5 P3.4. Implements the P1.2 rules with unit tests. The
> behavior table is already pinned in ledger/registration-rules.md (#1–#10)
> with characterization tests — this ticket ports the decision logic into a
> typed service and wires it into create/edit (P3.3a/b replace their inline
> checks with this engine).

Status: done
Phase: 3 (Slice B)
Depends on: P3.3a (wiring target), P1.2 (registration-rules ledger done)
Consumes: ledger/registration-rules.md, ledger/contest-info.md (window states)

## Goal

One typed service answers "can this brewer add/edit this entry right now?"
and returns the exact legacy failure mode (msg code, redirect target), so
create/edit/count displays share a single source of truth.

## Scope

- Service `EntryLimits` (or similar) implementing the decision table:
  per-user total cap (ledger #1 — counts ALL brewing rows incl. unconfirmed
  drafts), subcategory cap (exact sort+sub equality, #3; BA style set drops
  the category filter, #4; on-edit check only when window open AND style
  changed, #5), style/exception limits (#8 — empty exception = unlimited),
  comp-level caps disabled flags (#9), admin bypass + non-owner rejection
  (#10).
- Category normalization (#6, #7) — %02d pad for numeric ≤9, class chars
  kept whole, literal-comma class preserved (do NOT "fix").
- Wire into P3.3a create and P3.3b edit, replacing any inline checks;
  expose count queries for the at-a-glance "Total/Paid" cards and the Add
  Entry nav link gating (remaining-entries math).
- Keep the characterization tests green and ADD table-driven unit tests for
  the service covering all 7 decision-table cases + the two cap flavors
  (user vs subcat) + admin bypass.

## Deliverables

1. Typed limits service + unit tests (no DB required for the decision table;
  DB-backed counts stay in the characterization suite).
2. Wiring into create/edit + at-a-glance counts + nav gating.

## Acceptance

- `php artisan test` green (existing RegistrationRulesLimitTest +
  RegistrationRulesDbTest untouched and green); PHPStan 0; Pint clean.
- The service's decision table matches the ledger rows 1:1 (add a mapping
  comment in code).
