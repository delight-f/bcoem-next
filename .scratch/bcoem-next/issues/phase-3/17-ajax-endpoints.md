# P3.7 — AJAX endpoints (username, valid_email, account_checks, save, count_records)

> Part of spec §5 P3.7. Legacy surface: `ajax/username.ajax.php`,
> `ajax/valid_email.ajax.php`, `ajax/account_checks.ajax.php`,
> `ajax/save.ajax.php`, `ajax/count_records.ajax.php` — port each as a
> Laravel JSON route with the same request/response contract (status codes,
> message strings are parity surface).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.1b (registration consumes username/valid_email),
P3.1a (account_checks needs auth), P3.2a–c (save feeds brewer forms),
P3.3a (count_records feeds brew page)
Consumes: ledger/registration-rules.md (count semantics)

## Goal

The five endpoints the slice's forms call, with identical observable
behavior to the legacy `.ajax.php` files.

## Scope

| Endpoint | Legacy source | Behavior to port |
|---|---|---|
| `username` | username.ajax.php | availability check for `users.user_name` (email) — exact response shape + message |
| `valid_email` | valid_email.ajax.php | email format/domain validation as legacy defines it (the vendored `is_email` lib is NOT ported per §9 — replicate its decisions server-side or swap to Laravel validation + document deviation) |
| `account_checks` | account_checks.ajax.php | per-field checks on the brewer/entry forms (read the file — likely email/username/duplicate guards) |
| `save` | save.ajax.php | the wizard's AJAX save (draft persistence) — port the write shape |
| `count_records` | count_records.ajax.php | count queries behind the entry-status cards (received/paid/etc. — registration-rules ledger count semantics) |

- All endpoints: CSRF-protected POSTs (legacy had none — this is a port
  hardening; document), JSON responses, auth required where legacy required
  a session.
- The registration/brew forms must consume the ported endpoints (swap the
  JS), keeping legacy client-side validation messages.

## Deliverables

1. Five JSON routes + controllers; JS wiring in the slice's forms.
2. Feature tests per endpoint: success, failure, auth, CSRF.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Each endpoint's response body matches legacy for the same input on a
  corpus dump (content parity via curl + normalize, documented in the
  ticket's parity note).
