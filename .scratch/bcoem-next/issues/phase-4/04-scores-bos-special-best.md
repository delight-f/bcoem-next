# P4.4 — Score entry + BOS + special best

> Part of spec §6 P4.4. Legacy surface: `admin/judging_scores.php`,
> `admin/judging_scores_bos.php`, `admin/special_best.php` (+data). Depends on
> P1.6/P1.7 ledgers (scoring.md, winners-display.md).

Status: done
Phase: 4 (Slice C)
Depends on: P4.2, P4.3
Consumes: ledger/scoring.md, ledger/winners-display.md

## Goal

Score entry per flight, BOS round logic, special-best data management —
producing the placement strings and winner rows the public winners pages
(Slice A) read.

## Scope

- Score placement string generation identical to legacy (rounding/format).
- BOS round advancement logic behind characterization tests.
- special_best data CRUD.
- Winner-delay timestamps respected (winners-display ledger) so public pages
  stay correct.

## Deliverables

1. Controllers/views for scores, scores_bos, special_best.
2. Feature tests: full mini-season (assign → score → BOS) asserting DB rows
   equal legacy on a fixture dump.

## Acceptance

- Public `winners`/`bos`/`bestbrewer` URLs still pass parity after real score
  data is written by the new app.
- Test suite green; PHPStan 0; Pint clean.
