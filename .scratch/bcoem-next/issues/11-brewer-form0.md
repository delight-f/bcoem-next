# P3.2a — Brewer profile: form 0 (account & contact)

> Part of spec §5 P3.2. Legacy surface: `pub/brewer_form_0.pub.php`,
> `includes/process_brewer.inc.php` (form-0 branch). First wizard step for
> both registration (P3.1b) and the edit path (`/list` → Edit Account).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.1a (auth), P3.1b (registration — reuse of the same step)
Consumes: ledger/registration-rules.md (brewer row semantics)

## Goal

Collect/edit the account-level fields that map to `brewer`:
`brewerFirstName`, `brewerLastName`, `brewerAddress`, `brewerCity`,
`brewerState`, `brewerZip`, `brewerCountry`, `brewerPhone1`, `brewerPhone2`,
`brewerEmail` (syncs to `users.user_name` on change — pin the legacy sync
rule from `process_brewer.inc.php`), plus the `users` security question/answer
when created.

## Scope

- Edit mode (`action=edit`) loads the brewer row; save validates + writes
  `brewer` (blank_to_null parity per entry-lifecycle ledger style) and
  re-syncs `users.user_name` if email changed (or documents the legacy
  behavior if it does NOT sync).
- Address/phone length and required-field rules from the legacy form
  attributes — port the client rules server-side (they're the trust boundary).
- Country list source: legacy dropdown from `includes/` — port as a
  config/lang list, not a DB table.

## Deliverables

1. BrewerForm0 controller+view (create + edit modes), shared partial used by
   registration and account edit.
2. Feature tests: save/edit round-trip for every column; email-change sync;
   invalid/oversized inputs rejected; blank_to_null semantics.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Editing a brewer from a corpus dump preserves all other `brewer` columns
  untouched (diff row before/after).
