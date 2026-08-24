# P3.3a — Entry creation (brew form)

> Part of spec §5 P3.3. Legacy surface: `pub/brew.pub.php` (add mode),
> `includes/process_brewing.inc.php` (add branch), `includes/process.lib.php`
> (judging-number allocation). Consumes the styles-system normalization
> (ledger/styles-system.md + StylesCategoryNormalizationTest) for the
> category/subcategory pickers.

Status: not started
Phase: 3 (Slice B)
Depends on: P3.1d (auth), P3.2d (list shell), P1.8 styles ledger
Consumes: ledger/entry-lifecycle.md (#1–#2, #5), ledger/styles-system.md, ledger/registration-rules.md (#1–#10 — caps gate creation)

## Goal

A logged-in entrant creates an entry: `brewing` row with
`brewName`, `brewStyle`, `brewCategory`/`brewCategorySort`/`brewSubCategory`
(via style pickers), `brewBottleDate`/`brewDate`/`brewYield`, `brewABV`,
`brewInfo`, optional-fields (`brewInfoOptional`, `brewPossAllergens`,
`brewSweetnessLevel`, `brewCoBrewer`, `brewJuiceSource`, `brewPouring`,
`brewPackaging`, mead/cider variant fields `brewMead1–3`), and a
`brewJudgingNumber`.

## Scope

- Style pickers: category list from `styles` rows for the active style set
  (`prefsStyleSet` + `styles_active()` semantics — styles ledger), custom
  categories via `mods` (styles ticket #8), mead/cider variants per
  `brewStyleType`. BA-set category normalization from
  StylesCategoryNormalizationTest.
- Creation defaults pinned in entry-lifecycle ledger #1: `brewConfirmed='1'`,
  `brewPaid=0`, `brewReceived=0`, blank_to_null on text fields; #2:
  `contestEntryFee==0` forces `brewPaid=1`.
- Judging number: default random method (ledger #5 — six digits 1–9, no
  zero, uniqueness loop vs `brewing.brewJudgingNumber` AND the
  `$USER_DOCS/<num>.pdf` scoresheet file check — the file check has no port
  equivalent yet; document the deviation or scan `public/user_docs` if
  present on the tenant).
- Fee snapshot: `total_fees` at creation (payments ledger #9) — store in
  session/order context for the pay page (P3.5d).
- Caps: per-user total, subcat, style limits + comp caps enforced at add
  (registration-rules ledger #1–#10) with the legacy redirect + msg codes
  (`section=list&msg=8` user cap, `msg=9` subcat).
- Recipe import (BeerXML) is NOT in this ticket (spec P3.3 does not require
  it; `process_beerxml.inc.php` is P5-range — note as out of scope).

## Deliverables

1. BrewController create action + view + judging-number service.
2. Feature tests: row defaults, style picker correctness for the active set,
   fee-free forces paid, judging-number format + uniqueness, cap rejection
   with legacy msg codes.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Creating an entry on a corpus dump yields a `brewing` row that passes the
  existing `EntryLifecycleDbTest` creation-defaults assertions.
