# P4.6 — Eval sub-app under `/eval`

> Part of spec §6 P4.6. Legacy surface: `eval/` (24 pages). Consumes
> ledger/eval-app.md (P1.9).

Status: done
Phase: 4 (Slice C)
Depends on: Slice B auth; P4.4 for score storage interplay
Consumes: ledger/eval-app.md

## Goal

Port the evaluation scoresheet sub-application under a dedicated `/eval`
route prefix with its own middleware/layout: dashboard, full and structured
scoresheet variants incl. NW cider, checklist, import_scores, my_account,
warnings, process, practice mode.

## Scope

- Route group `/eval` with eval-specific auth.
- Scoresheet variants selected by style/category per ledger semantics.
- import_scores format pinned by characterization tests from P1.9.
- Practice sessions don't write real scoring rows (verify in ledger).

## Deliverables

1. `/eval` route group, controllers, Blade views for all 24 surfaces.
2. Feature tests per variant: render + submit round-trip; import parser
   fixtures; warnings/process flows.

## Acceptance

- Parity on eval URLs across corpus dumps where state allows; diffs
  explained otherwise.
- Test suite green; PHPStan 0; Pint clean.
