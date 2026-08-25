# P5.4 — Remaining admin screens

> Part of spec §7 P5.4. Legacy surface: competition_info, site_preferences,
> all_dates, hero_images, sponsors, contacts, mods, style_types, styles,
> make_admin, change_user_password, send_test_email (.admin.php each).

Status: pending
Phase: 5 (Slice D)
Depends on: Slice B admin shell
Consumes: ledger/styles.md (styles/style_types/mods semantics — REQUIRED reading)

## Goal

Port the remaining standalone admin CRUD/settings screens.

## Scope

- Simple CRUD: hero_images, sponsors, contacts, all_dates.
- Settings forms writing preferences rows: competition_info,
  site_preferences, send_test_email.
- Styles stack per ledger: styles, style_types, mods — respect set-version
  predicates (BJCP2025/AABC2025 dual-version), custom-row semantics
  (`brewStyleOwn='custom'` bypasses filters everywhere), category
  normalization pins 1–4.
- Account ops: make_admin, change_user_password (self-service password
  change may already exist from Slice B auth — reconcile, don't duplicate).

## Deliverables

1. Controllers + views per screen, routes grouped in `routes/admin.php`.
2. Feature tests: CRUD round-trip per screen; preference writes asserted in
   DB; styles normalization tests extended to the UI path.

## Acceptance

- Admin URL inventory complete vs legacy admin/*.admin.php list.
- Suite green, PHPStan 0, Pint clean; anonymous-parity where pages are
  public-visible (none expected — DB-state convergence per Slice B precedent).
