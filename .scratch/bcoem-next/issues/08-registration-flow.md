# P3.1b — Registration flow (entrant / judge / steward / staff)

> Part of spec §5 P3.1. Legacy surface: `pub/register.pub.php`,
> `pub/brewer_form_0.pub.php`, `pub/brewer_form_1.pub.php`,
> `pub/brewer_form_2.pub.php`, `includes/process_brewer.inc.php`,
> `includes/process.inc.php` (register branch). Window gating is the
> `reg_open`/`judge_window_open` contract from ledger/contest-info.md.
> PayPal/IPN out of scope (D7).

Status: not started
Phase: 3 (Slice B)
Depends on: P3.1a (auth bootstrap), P2.5 (window states)
Consumes: ledger/contest-info.md (window machine), ledger/registration-rules.md

## Goal

Anonymous visitors can register as entrant, judge, steward, or staff through
the same wizard legacy uses: `register` page → `brewer_form_0` (account +
contact) → `brewer_form_1` (brewer demographics) → `brewer_form_2`
(judge/steward/staff preferences), creating a `users` row + a `brewer` row
(`uid` = users.id).

## Scope

- Register entry page with role tabs (entrant/judge/steward/staff) matching
  legacy `register.pub.php`; role determines which form steps appear and
  which `brewer*` flags get set (`brewerJudge`/`brewerSteward`/`brewerStaff`).
- Wizard state persisted server-side (session) per legacy behavior — a
  half-finished registration must survive navigation (legacy keeps `brewer`
  rows in progress; check `process_brewer.inc.php` for the draft semantics —
  registration-rules ledger #1 note: unconfirmed drafts count against caps).
- Window gating: entrant registration only while `registration_open == 1`
  (or the legacy bypasses for judge/staff); render the closed state per
  ledger W1/W3.
- `user_name` = email; duplicates rejected with the legacy message
  (charset/length checks from `register.pub.php` + `username.ajax.php`).
- Security question/answer fields (users table) — legacy register collects
  them; port verbatim.
- On success: login the new user (legacy auto-logs-in after register —
  verify in `process_brewer.inc.php`), redirect to brewer info (P3.2).

## Deliverables

1. RegisterController + wizard views (3 steps) + session-backed draft.
2. Feature tests: full wizard for each role; duplicate email; closed-window
   rejection; draft row created and counted against caps (ledger #1).

## Acceptance

- A fake entrant registers end-to-end with zero console errors on a corpus
  dump (staging copy), producing `users` + `brewer` rows with correct flags.
- `php artisan test` green; PHPStan 0; Pint clean.

## Parity note

`?section=register` renders the same chrome pre/post registration on both
apps; the wizard's POST paths are DB-state validated, not page-diffed
(parity harness skill conventions).
