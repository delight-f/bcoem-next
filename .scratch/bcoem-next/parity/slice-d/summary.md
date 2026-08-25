# Slice D parity summary (Phase 5)

Gate: ticket 08-equivalent (P5.1–P5.6 acceptance). Date: 2026-08-25.
Harness: `tools/parity/parity.sh` (bcoem-parity-harness skill) +
`tools/parity/fetch_export.sh` (new in P5.3).

## Verdict

**Public surfaces: 6/6 URLs zero-diff on all three dumps** (no regressions
from Phase 5 work):

| Dump | Result |
|------|--------|
| anon-base.sql | 6 pass / 0 diff |
| synth-100-winners-shown.sql | 6 pass / 0 diff |
| sql/bcoem_baseline_3.0.X.sql (CI baseline) | 6 pass / 0 diff |

Reports: `tools/parity/reports/run-20260825-1700*`.

**Export byte-parity (spec §8.3): `cmp` IDENTICAL — 1339 bytes** (BOM +
header + rows incl. JSON splits, quoting/doubling, space-padded score quirk,
variable-length unjudged rows) on anon-base via fetch_export.sh, which boots
both apps on one physical schema copy.

## Auth-gated surface validation (Slice B/C precedent)

Admin/outputs/archive pages are not page-diffed; validated by DB-state
convergence + PDF content tests:

- Output suites A–D: gate matrices + %PDF + payload assertions
  (placard counts, master-list received counts, results ×4 modes,
  bos_mat ×5 modes, dropoff default/check).
- Pullsheets: entry counts vs received entries; judging-number order.
- Export: header order verbatim; CRLF/quoting pinned.
- Admin screens: CRUD round-trips per table; preference writes asserted;
  styles normalization pins 1–4 through the UI path; make_admin/password
  change verified at DB level.
- Back-office: payment-marking convergence with the Stripe path (identical
  payments+brewing state), by_style/by_substyle counts on fixtures.
- Archive/purge: rename+recreate on a cloned schema (history preserved,
  live tables empty+identical, AUTO_INCREMENT reset, admin survives),
  keep-flag matrix, purge children cascades, SINGLE-mode variant.

## PDF pipeline decision (P5.1)

dompdf 3.1 replaces vendored FPDF. Outputs are Blade views rendered via
`App\Support\Outputs\StreamPdf`. Recorded in `ledger/outputs.md` with the
per-output quirks/divergences from all pairs.

## Suite state at gate

- `php artisan test`: **563 tests / 563 passed** (the pre-existing env skip
  is gone — MySQL present).
- PHPStan max level: **0 errors**, baseline still empty.
- Pint clean.

## Integration fixes beyond tickets

1. `ResultsRepository`: brewer join fixed to `br.uid` (brewBrewerID stores
   users.id); public results partial's dead best-brewer gate
   (`=== 'Y'` vs int storage) + missing `hasWinners()` replaced with
   winners-nonempty check.
2. `BrewController::styleSort()`: ltrim before padding so '01' canonicalizes
   instead of re-padding to unresolvable '001'.
3. `TableCardsController::tableRows()`: dedupe style codes before counting
   (same code exists under multiple set versions → double count).
4. `JudgeNotesController::withPlacement()`: placement merge restored (lost
   during PHPStan fixes; caught by full suite).
5. Test-infra hardening: shared-config snapshots in AdminScreensTestCase
   setUp (kills the prefsSelectedStyles leak class), orphan-fixture sweeps,
   cell-scoped HTML assertions (bare-number CDN collisions), phpunit.xml
   memory headroom for dompdf's per-process accumulation.

## Deliberate divergences (documented per controller + ledger)

See `ledger/outputs.md` and each controller's docblock; highlights: maps =
verbatim Google-redirect quirk (incl. rtrim letter-eating); entry output
restores legacy dead-code intent; scoresheets = authorized single-file
stream (traversal hole closed); bottle_label/barcodes render bracketed text
(dompdf remote images off); archive keeps original ids when re-inserting the
performing admin; SINGLE-mode purge behind config flags (default off).
