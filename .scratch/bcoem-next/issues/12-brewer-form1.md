# P3.2b — Brewer profile: form 1 (demographics, clubs, AHA/BJCP)

> Part of spec §5 P3.2. Legacy surface: `pub/brewer_form_1.pub.php`,
> `includes/process_brewer.inc.php` (form-1 branch).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.2a (form 0)
Consumes: ledger/styles-system.md (style-set context for BJCP fields)

## Goal

Collect/edit the brewer-detail fields: `brewerClubs` (multi-club list, legacy
comma/array semantics from `$_SESSION['club_array']` — pin exact storage
format), `brewerAHA` (AHA number), `brewerJudgeID`, `brewerJudgeRank`
(BJCP rank list), `brewerProAm` (pro/am flag), `brewerBreweryName`,
`brewerBreweryInfo`, `brewerDiscount`, `brewerDropOff` (drop-off location
choice — values from `drop_off` table), `brewerMHP`.

## Scope

- Club handling: legacy stores `brewerClubs` as a delimited string; the form
  offers existing clubs (from `brewer.brewerClubs` across users + the
  `contestClubs` session list — contest-info ledger) plus free entry. Port
  the storage format byte-for-byte; the picker UI may be modernized but the
  stored value must be legacy-compatible.
- BJCP rank/judge-id validation and the mead/cider judge flags
  (`brewerJudgeMead`/`brewerJudgeCider`) if shown on this step (else form 2).
- Drop-off options from `drop_off` rows, gated by `dropoff_window_open`
  (ledger W2) — store the chosen `id` in `brewerDropOff`.

## Deliverables

1. BrewerForm1 controller+view (create/edit).
2. Feature tests: club storage round-trip (single + multiple), drop-off
   selection, AHA/BJCP validation, pro/am + discount flags.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- A brewer saved through form 1 produces a `brewerClubs` value identical to
  what legacy would store for the same input (golden strings from a corpus
  brewer row).
