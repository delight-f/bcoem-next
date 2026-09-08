# P4.1 — Admin judging config: locations, dropoff, tables, flights prefs

> Part of spec §6 P4.1. Legacy surface: `admin/judging_locations.php`,
> `admin/non_judging_locations.php`, `admin/dropoff.php`,
> `admin/judging_tables.php`, `admin/judging_preferences.php`.

Status: done
Phase: 4 (Slice C)
Depends on: Slice B (auth/admin shell)
Consumes: ledger/flight-assignment.md (table/flight semantics)

## Goal

Admin CRUD for judging locations, non-judging locations, dropoff locations,
judging tables, and judging preferences — the config the assignment engine
(P4.2) consumes.

## Scope

- Port each admin screen with its exact form fields, validation, and
  preference-row writes (same `preferences` rows the public nav reads).
- Table creation/mode behavior (see `ajax/tables_mode` interplay, P4.7).
- blank_to_null and default-row parity per ledger conventions.

## Deliverables

1. Controllers + Blade views for the five config screens.
2. Feature tests: CRUD round-trips per table; preference writes; validation.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- On a corpus dump, each screen's rendered HTML passes parity or diff is
  explained in the ledger.
