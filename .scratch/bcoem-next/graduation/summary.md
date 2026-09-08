# Graduation gate — spec §8 summary (2026-08-25)

Verdict per leg. Artifacts in this directory; scripts in `tools/graduation/`.

## §8.1 Full URL inventory — PASS

`tools/graduation/url_inventory.sh` boots the port exactly like the parity
harness (DB_TABLE_PREFIX honored) and requests every non-parameterized GET
route: **81/81 respond (2xx/3xx, zero 404/500)** on anon-base.sql AND
synth-500.sql. Parameterized routes are covered by the Feature suites.

## §8.2 All tests pass — PASS

563/563 (`php artisan test`), PHPStan max level 0 errors (baseline empty),
Pint clean. Includes 19 characterization tests pinning legacy behaviors.

## §8.3 Simulated season, both apps — PASS (with documented exceptions)

`tools/graduation/season_sim.sh` (545 lines) boots legacy + port each on an
identical anon-base copy and drives over HTTP:
register → 3 entries → manual pay → check-in → location/table → flight
assignment → scores (incl. HM-as-'5') → BOS round → results/outputs → CSV.

- DB-state convergence: `brewing` (paid/confirmed), `judging_scores`,
  `judging_scores_bos` row-identical across apps.
- CSV export: byte-parity modulo per-app random judging numbers and
  timezone-rendered timestamps (both randomized by design; export itself
  byte-identical on fixed data per tools/parity/fetch_export.sh).
- EXPECTED-DIVERGENCE (spec §8.3/D2): `payments` ledger rows exist only
  port-side — legacy's sole writer is the PayPal IPN handler (ppv.php:253);
  brewing-flag convergence proven instead.
- EXPECTED-DIVERGENCE: pullsheets/table_cards exist as PDFs only on the
  port; legacy upstream renders them as HTML print views (no PDF renderer).
  Both artifact classes saved to `season-pdfs/` for human visual approval.
- Found+fixed during the leg: BrewController style-version fallback
  (BJCP2025 set: non-C groups read BJCP2021 rows, process_brewing.inc.php:335).

## §8.4 Security review — PASS (after one hardening fix)

- SQL: no sprintf-SQL; every raw fragment constant/allow-list derived;
  bindings everywhere (`security-sql-auth.md`).
- Auth: dual-layer (middleware + controller gate) on every non-public
  route; legacy-exact special gates on ajax endpoints; documented
  divergence: 17 screens legacy restricted to userLevel==0 accept level-1
  admins (uniform-admin convention).
- Uploads (`security-uploads.md`): one FAIL found and FIXED this gate —
  scoresheet PDFs moved from public/user_docs (proven unauthenticated
  download) to non-public storage/user_docs behind the authorized stream
  (`App\Support\Entries\UserDocs`).

## §8.5 Performance smoke — PASS (one documented exemption)

`tools/graduation/perf_smoke.sh`, synth-500 dump, 25 samples/page
(`perf-smoke.md`): every render page p95 < 300 ms (worst: past-winners
272 ms). Exemption: login POST p95 ~836 ms is a single bcrypt
password_verify at cost 12 — deliberate KDF cost, CPU-bound by design,
identical magnitude on legacy when it hashes. Residual watch-item:
ResultsRepository::winners() double join in past-winners (~150 ms).

## Remaining human step

Visual sign-off on the generated PDFs (`season-pdfs/`): results.legacy.pdf
vs results.new.pdf byte-class comparison, plus port pullsheets/table_cards
vs legacy HTML print views. Cutover pilot proposal follows owner approval.
