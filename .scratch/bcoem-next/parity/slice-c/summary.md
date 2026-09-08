# Slice C parity summary (Phase 4)

Gate: ticket 08 (`.scratch/bcoem-next/issues/phase-4/08-slice-c-gate.md`)
Date: 2026-08-25
Harness: `tools/parity/parity.sh` (bcoem-parity-harness skill)

## Verdict

**6/6 URLs PASS, zero content diffs** on all three dumps (Slice C regression
run — Slice A/B public surfaces unaffected by the judging work):

| Dump | Result |
|------|--------|
| anon-base.sql | 6 pass / 0 diff |
| synth-100-winners-shown.sql | 6 pass / 0 diff |
| sql/bcoem_baseline_3.0.X.sql (CI baseline) | 6 pass / 0 diff |

Reports: `tools/parity/reports/run-20260825-085947` (anon-base),
`run-20260825-090021` (synth), `run-20260825-090049` (CI baseline).

## Scripted season simulation (spec §6 P4.8 leg 2)

All Slice C surfaces are auth-gated (admin userLevel<=1, `/eval`, `/judge`),
so per the Slice B gate precedent they are validated by DB-state convergence,
not anonymous page diffs:

- `tests/Feature/SliceCSeasonTest.php` — full season leg over HTTP:
  location + table config → flight proposal + manual radio assignment →
  barcode check-in (received flag, paid untouched) → score entry
  (1st + HM-as-'5', mini-BOS zero-write) → BOS round (method-1 eligibility,
  HM excluded) → winners-filter visibility of every placed entry.
  26 assertions.
- Per-slice suites: JudgingConfigTest (13), JudgingFlightsTest +
  JudgingAssignTest + JudgeSignupTest (12), JudgingScoresBosTest (4),
  BarcodeCheckinTest (6), EvalSubAppTest (8) + EvalConsensus unit pins (18),
  JudgingAjaxTest (13), FlightAssignment characterization (19).

## Suite state at gate

- `php artisan test`: **492 tests, 491 passed, 1 skipped** (pre-existing
  environmental guard, not a regression).
- PHPStan: **0 errors** (baseline stays empty; one inline
  `@phpstan-ignore argument.type` on the FlightController raw ORDER BY
  fragment — internal sanitized aliases, documented in place).
- Pint: clean.

## Deliberate divergences (documented in code comments)

1. Dropoff delete was a silent no-op in legacy `process_delete.inc.php`;
   the port performs the plain row delete.
2. Table-delete cascade skips legacy's latent judging_assignments-by-score-id
   deletion (id-collision data loss).
3. Legacy BOS-place trim-down compared backwards vs its own intent; port
   fixes direction to old>new.
4. `sbi_display_places` is posted by legacy's form but never stored;
   mirrored (not fixed).
5. `sbd_place` numeric column: legacy relied on silent MySQL truncation;
   port writes NULL for non-numeric input under strict PDO.
6. Eval drops per ledger verdicts: nw_structured_cider*, checklist_*,
   install_eval_db; "regenerate flights" naming trap NOT repeated.

## Ledger addenda

- `ledger/eval-app.md` — open question resolved: `evaluation` table IS in
  baseline SQL (24 tables).
