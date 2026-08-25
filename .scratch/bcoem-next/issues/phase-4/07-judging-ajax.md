# P4.7 — Judging AJAX endpoints

> Part of spec §6 P4.7. Legacy surface: `ajax/tables_mode`,
> `ajax/import_scores`, `ajax/practice_session`, `ajax/custom_style`.

Status: done
Phase: 4 (Slice C)
Depends on: P4.1, P4.6
Consumes: ledger/eval-app.md, ledger/styles.md (custom_style)

## Goal

Port the four judging-related AJAX endpoints with identical request/response
shapes (Slice B endpoint conventions apply).

## Scope

- tables_mode: toggles table mode used by P4.1 screens.
- import_scores: file upload → parsed scores (trust boundary: validate).
- practice_session: eval practice mode state.
- custom_style: custom category lookup/write (mods interaction).

## Deliverables

1. Four endpoints + HTTP tests asserting response body shape matches legacy
   captured fixtures.

## Acceptance

- Fixture-based tests prove byte-identical responses for representative calls.
- Test suite green; PHPStan 0; Pint clean.
