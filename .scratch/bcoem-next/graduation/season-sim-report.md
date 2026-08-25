# Season Simulation Report — spec §8.3 graduation gate

- Run: 20260825-192358 · dump: `anon-base.sql` · legacy db `season_sim_legacy_418231` (dropped) · port db `season_sim_port_418231` (dropped)
- Apps: legacy :8393 (/home/faraaz/dev/bcoe/brewcompetitiononlineentry) · port :8394 (this repo)
- Entrant: `season.sim@example.invalid` · entries: 4 5 6 (styles 15-A, 21-B, 01-A) · table `Season Sim Table` id 2

## Per-step results

```
PASS admin-login 
PASS register-entrant — users.id=7 + brewer row on both
PASS add-entries — ids 4 5 6 on both
PASS pay-manual-marking — paid+confirmed all 3 on both; port ledger rows=1 (legacy has no manual-payment code path — expected divergence, see report)
PASS judging-location-table — location=2 table=2 styles=[551,594,611]
PASS checkin-received — entries [4,5,6] brewReceived=1 on both
PASS assign-flights — entries [4,5,6] in flight 1 on both (legacy also auto-assigned matching received seed entries)
PASS enter-scores — rows [4:38:1:1,5:41:2:1,6:33:5:0] (place 5 = HM) identical
PASS bos-round — rows [4:38:1:1] identical
PASS csv-export-bytes — identical modulo judging numbers + timezone-rendered timestamps (raw sizes: lg=2287 pt=2287)
EXPECTED-DIVERGENCE outputs-pdfs — results PDF on both; pull sheets/table cards PDF on port vs legacy HTML print view (no legacy PDF renderer exists) — artifacts in /home/faraaz/dev/bcoe/bcoem-next/.scratch/bcoem-next/graduation/season-pdfs
PASS db-diff-brewing — 6 rows identical (excluded: brewUpdated, brewJudgingNumber)
PASS db-diff-judging_scores — 3 rows identical
PASS db-diff-judging_scores_bos — 1 rows identical
EXPECTED-DIVERGENCE payments-ledger — port rows=1 (dumped to /home/faraaz/dev/bcoe/bcoem-next/.scratch/bcoem-next/graduation/season-sim-20260825-192358/db.payments.port); legacy rows=0 — legacy cannot write this table outside PayPal IPN (ppv.php:253); brewing-flag convergence proven above
```

## Pay-leg divergence (spec §8.3 exception)

- Both apps marked the three entries paid manually over their own admin flows:
  legacy `includes/process.inc.php?action=update&dbTable=brewing&filter=admin` with `brewPaid<id>=1`; port `POST /admin/payments/mark-paid`.
- `brewing.brewPaid`/`brewConfirmed`: CONVERGED for all three entries (see db-diff-brewing PASS above).
- `payments` ledger: port wrote 1 manual row(s) (`/home/faraaz/dev/bcoe/bcoem-next/.scratch/bcoem-next/graduation/season-sim-20260825-192358/db.payments.port`). Legacy wrote none and can never write any:
  its only insert into `payments` is the PayPal IPN handler (`ppv.php:252-267`, gated on a live PayPal POST), and the legacy schema does not even create the table in baseline dumps. This is the approved D2 deviation documented in the port's create-payments migration.

## Output-artifact class divergence

- `results`: PDF from both apps (`results.legacy.pdf` = legacy FPDF winners export `section=export-results&view=pdf`; `results.new.pdf` = port dompdf stream).
- `pullsheets` / `table_cards`: port streams real PDFs; legacy only renders these as HTML print views (`output/print.output.php` dispatch — no PDF renderer exists upstream). Legacy sides saved as `.legacy.html` for visual comparison.

## Legacy flow archaeology

- CSRF: every legacy POST needs field `user_session_token` (`process.inc.php:103-118`) and a same-host Referer (`process.inc.php:89-92`).
- Registration regenerates the token (`process_users_register.inc.php:423`); handled by scraping per-form.
- Manual payment marking on legacy is the entries-list update loop, NOT `admin/payments.admin.php` (read-only IPN listing): `process_brewing.inc.php:938-974`.
- Legacy score save wipes all table scores first, then inserts non-empty rows; HM is stored as scorePlace '5' (`process_judging_scores.inc.php:13-70`, option list `admin/judging_scores.admin.php:529-535`). Port mirrors both behaviors (`ScoreController::update`).
- Legacy BOS enter branches on `scorePrevious` Y/N for update/insert/delete (`process_judging_scores_bos.inc.php:15-71`).
- Style resolution under prefsStyleSet=BJCP2025: C-groups → BJCP2025, everything else → BJCP2021 (`process_brewing.inc.php:335-341`; mirrored in `BrewController::styleVersion`).

## Artifacts

- Run dir: `/home/faraaz/dev/bcoe/bcoem-next/.scratch/bcoem-next/graduation/season-sim-20260825-192358` (headers, raw exports, per-table DB dumps + diffs, server logs, cookie jars)
- Human-review artifacts: `/home/faraaz/dev/bcoe/bcoem-next/.scratch/bcoem-next/graduation/season-pdfs`
