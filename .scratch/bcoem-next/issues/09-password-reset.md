# P3.1c — Password reset flow (forgot / token / reset)

> Part of spec §5 P3.1. Legacy surface: `pub/login.pub.php` (forgot/verify/
> reset-token modes), `includes/process_forgot_password.inc.php`,
> `users.userToken`/`userTokenTime` columns. No new schema.

Status: not started
Phase: 3 (Slice B)
Depends on: P3.1a (auth bootstrap), P3.6 (email delivery — or mailer stub
first; the reset email must send, so wire the mailer here if P3.6 is not done)
Consumes: ledger/contest-info.md

## Goal

Users reset forgotten passwords through the legacy four-mode flow:
forgot-form → token generated + emailed → token entry/verification →
new-password form. Token storage reuses `users.userToken`/`userTokenTime`
verbatim (no `password_reset_tokens` table — D2).

## Scope

- Port `process_forgot_password.inc.php` semantics: token generation format
  and expiry window (`userTokenTime`) pinned from source; the legacy reset
  email content is a template to port (P3.6 owns the mailer).
- The token-entry page (`?action=reset-password` with token) + verify +
  re-set. Legacy also has security-question verification — check
  `login.pub.php` modes and port what is actually reachable; document any
  dead branch in the ledger.
- On reset: bcrypt hash (phpass only for legacy rows, rehash on next login —
  P3.1a owns that).
- Auth-safety: token compare should be constant-time; token single-use if
  legacy semantics allow (document deviation if legacy reuses tokens).

## Deliverables

1. ForgotPasswordController (+ token verify/reset) + views.
2. Feature tests: token issued and emailed; valid token resets (bcrypt);
   expired/invalid token rejected; post-reset login works.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Reset mail reaches an array/mailpit test inbox; the reset path converges
  to a working login with the new password.

## Parity note

The forgot/reset pages are chrome-diffable; token issuance/reset is
DB-state validated (assert `users.password` changed + token cleared).
