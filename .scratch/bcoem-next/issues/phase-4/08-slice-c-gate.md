# P4.8 — Slice C parity gate + scripted season simulation

> Part of spec §6 P4.8. Gate procedure per bcoem-parity-harness skill and
> spec §8.

Status: done
Phase: 4 (Slice C)
Depends on: P4.1–P4.7
Consumes: parity/slice-c/summary.md (to be written)

## Goal

Prove Slice C end-to-end: on identical DB copies of corpus dumps, run a
scripted season — configure judging → assign → score → BOS → check public
results — and diff both apps.

## Scope

- Extend harness URL list with all Slice C admin + eval URLs.
- Scripted simulation executed identically on both apps; compare resulting
  DB rows (flights, assignments, scores, winners), not just HTML.
- Triage ladder: same-DB check → URL shape → env plumbing → normalization →
  only then content fixes.

## Deliverables

1. Harness run artifacts + parity/slice-c/summary.md verdict.
2. All diffs fixed or explained in ledgers; CI gate updated to include the
   new URL set.

## Acceptance

- 0 unexplained diffs across the corpus for Slice C URLs.
- Simulated-season DB states identical between apps (modulo documented D7
  payment exception, which doesn't apply here).
- Full test suite green; PHPStan 0; Pint clean; CI passing.
