# P3.1d — Auth-aware nav, session, and account-gated routes

> Part of spec §5 P3.1/P3.2. Legacy surface: `pub/nav.pub.php` (logged-in
> dropdown: My Account / Entries / Add Entry / Pay / judging dashboard /
> Change Email / Change Password / Log Out), `includes/headers.inc.php`
> (per-section header + alert output), `pub/list.pub.php` (account page
> shell). Completes the P2.1 layout promise: the same Blade chrome now has a
> logged-in branch.

Status: done
Phase: 3 (Slice B)
Depends on: P3.1a (auth), P2.1 (layout shell)
Consumes: ledger/contest-info.md (session bootstrap parity)

## Goal

The public layout renders the legacy logged-in nav (user dropdown + role-
appropriate links) and anonymous nav (Log In modal trigger — modal chrome is
normalized in parity, but the trigger link must exist for the logged-out
state), and every account-gated route enforces auth.

## Scope

- Nav logged-in branch from `nav.pub.php`: My Account (`/list`), Entries,
  Add Entry (link gated by remaining-entry count + window), Pay,
  Judging Dashboard (only when `prefsEval==1` + assigned judge +
  `jPrefsJudgingOpen` — P4.6 owns the dashboard itself, link only here),
  Change Email / Change Password (P3.2 forms), Log Out.
- Anonymous branch: Log In link opening the login modal (modal markup itself
  can be minimal — parity normalizes it; the visible "Log In" text must match
  legacy nav).
- Route protection: `list`, `brew`/`brewer`/`pay` route groups behind the
  `auth` middleware; anonymous access reproduces the legacy redirects
  (`/list` → `/?msg=99` — already implemented in P2; verify `brew`/`pay`
  behave per legacy `index.php` guards).
- `userLevel`-aware rendering: admin (level 1) sees the Admin link; entrant
  (2) vs participant (3) see the same public nav (participant = registered
  without entries — check legacy nav conditions).
- Session bootstrap parity: the logged-in session loads brewer row + prefs +
  contest_info exactly as the contest-info ledger pins; expose via
  `Auth::user()->brewer` and the existing `TenantContext`.

## Deliverables

1. Layout nav logged-in branch + Log In trigger; `auth` middleware on the
   slice-B route group.
2. Feature tests: logged-in nav renders role links; anonymous hits each
   gated route and gets the legacy redirect/status; admin link visibility by
   `userLevel`.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Parity: `/` still PASSes logged-out on all three dumps (nav text must not
  regress the P2.6 gate).
