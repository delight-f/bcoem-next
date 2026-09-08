# P3.2c — Brewer profile: form 2 (judge/steward/staff preferences) + wizard completion

> Part of spec §5 P3.2. Legacy surface: `pub/brewer_form_2.pub.php`,
> `includes/process_brewer.inc.php` (form-2 branch + registration finish),
> `pub/brewer_info.pub.php` (post-registration landing).

Status: done
Phase: 3 (Slice B)
Depends on: P3.2b (form 1)
Consumes: ledger/registration-rules.md, ledger/contest-info.md (judge window)

## Goal

Collect the volunteer-preference fields (`brewerStaff`, `brewerSteward`,
`brewerJudge`, `brewerJudgeWaiver`, `brewerJudgeLikes`/`Dislikes`/`Location`,
`brewerStewardLocation`, `brewerJudgeExp`, `brewerJudgeNotes`,
`brewerAssignment`) and complete registration: write the final row state,
mark the wizard done, and land on the legacy post-registration page
(`brewer_info.pub.php` — "thank you / next steps").

## Scope

- Role flags: only settable for the role the user registered as (or editable
  later per legacy rules — verify `process_brewer.inc.php`); judge fields
  gated by `judge_window_open` + judge caps (registration-rules ledger #10:
  caps force `judge_window_open=2`).
- Judge waiver checkbox semantics (`brewerJudgeWaiver`) — port the consent
  requirement; stores 'Y'/'N'.
- Assignment/location fields are free-text here; they feed P4.x assignment —
  store verbatim, no parsing yet.
- Wizard completion: transition the in-progress draft (P3.1b) to a normal
  row; confirm `brewConfirmed`-style flags do NOT apply to brewer rows
  (brewer has no confirmed flag — verify).
- `brewer_info.pub.php` parity: the "what happens next" content is a port
  of the legacy template (text + links), diffable in parity.

## Deliverables

1. BrewerForm2 controller+view + wizard-completion service.
2. Feature tests: per-role flag write, judge-window gating, waiver required,
   completion lands on brewer_info with correct nav state.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Full registration wizard (P3.1b → 3.2a → 3.2b → 3.2c) leaves `users` +
  `brewer` rows matching a legacy registration's shape on the same dump.
