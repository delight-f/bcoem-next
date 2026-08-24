# P3.1a — Auth bootstrap on the legacy users table (login/logout)

> Part of spec §5 P3.1. Owner decision D5: "Auth = Laravel starter kit;
> phpass-era sessions not ported." No starter kit is installed yet
> (composer.json has none) — this ticket installs/adapts one. **No schema
> changes anywhere** (D2): the legacy `users` table is reused verbatim.
> PayPal/IPN is out of scope (D7) — ignore legacy `ppv.php`/`includes/process.inc.php`
> PayPal branches entirely.

Status: not started
Phase: 3 (Slice B — accounts + registration)
Depends on: P2.6 (parity gate), Phase 1 ledgers (contest-info)
Consumes: ledger/contest-info.md (session bootstrap), ledger/registration-rules.md (userLevel)

## Goal

Anonymous users can log in and out against the legacy `users` table
(`user_name` = email address, `password` = phpass portable hash, `userLevel`
= '1' admin / '2' entrant / '3' participant, `userQuestion`/`userQuestionAnswer`
security questions, `userFailedLogins`/`userFailedLoginTime` lockout fields).

## Scope

- **Starter kit (locked, D5)**: use a Laravel starter kit — install **Breeze**
  (Blade + classic auth). Do NOT hand-roll login. The kit is ADAPTED to the
  verbatim legacy `users` table (D2) via a custom `User` provider + a custom
  `Hasher`/password broker; no migrations, no new tables:
  - `users` login is by `user_name` (email address) — map the credential
    field; the kit's `email`/`remember_token` columns are NOT added (the
    legacy table lacks them; omit remember-me or store via the legacy
    `userToken` column — decide + document).
  - Guard: `web` guard with a provider pointed at `App\Models\User`
    (remapped to legacy columns, `userLevel` exposed).
  - Breeze's auth scaffolding (login/logout views + controllers) is kept and
    styled to the legacy chrome; the session/tenant composition below still
    applies.
- **Password hashing**: legacy hashes are phpass portable (`$P$…`) — verify
  them on login, then rehash to bcrypt on success (D5 "rehash-on-login").
  Vendor a phpass *verifier* under `legacy/` (verification-only; phpass is
  explicitly NOT ported per spec §9) or use a maintained verify-only package.
  New/changed passwords are bcrypt only. Lockout semantics
  (userFailedLogins/userFailedLoginTime) from `includes/logincheck.inc.php`
  (source oracle) — 5 failed → lock, whatever the legacy window is.
- **Auth guard**: `web` guard backed by `App\Models\User` (exists, stock
  Laravel — must be remapped to `users` columns: `user_name` as the credential
  field, `userLevel` exposed). Eloquent model mapping only — no schema changes.
- **Session/tenant composition**: `TenantContext::load()` currently runs per
  request; the logged-in session must ALSO load the brewer row
  (`brewer WHERE uid = users.id`) and `userLevel` into the auth user,
  mirroring what legacy copies into `$_SESSION` (contest-info ledger).
- Routes: `GET/POST /login`, `POST /logout`; legacy also serves
  `?section=login` (keep the clean URL as canonical, legacy query shape may
  redirect).

## Deliverables

1. `App\Models\User` remapped to legacy columns; guard + provider wiring.
2. Breeze login/logout (kit scaffolding) wired to a custom `Hasher` that
   phpass-verifies legacy rows then rehashes to bcrypt; lockout.
3. Feature tests (DB-gated, `tests/Feature`, registered testsuite — already
   in phpunit.xml): valid login, wrong password, phpass-hash login rehashes,
   lockout, logout, `userLevel` surfaced.

## Acceptance

- `php artisan test` green (202 tests currently + new); PHPStan 0; Pint clean.
- A phpass-hash user from a corpus dump can log in; row's `password` is
  bcrypt after first success.
- No migrations, no schema diffs vs the dump.

## Parity note

Login page itself is parity-diffable (chrome-level) against legacy
`?section=login` on anon-base; session behavior is not (array sessions in the
harness). Use `tools/parity/parity.sh` with the harness skill conventions
(`PARITY_DB_PREFIX=` for corpus dumps).
