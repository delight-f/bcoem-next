<div align="center">

# bcoem-next

**A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/) —
Brew Competition Online Entry & Management**

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-latest-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/tests-563%20passing-brightgreen)](.github/workflows/)
[![PHPStan](https://img.shields.io/badge/PHPStan-max%2C%20empty%20baseline-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-Pint-F2C55C)](https://github.com/laravel/pint)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

</div>

A behavior-matched modernization of the classic BCOE&M homebrew competition
platform — same schema, same public surface, byte-identical outputs, on a fully
supported 2026 stack. Every claim below traces to an artifact in this repo:
the porting spec and behavior ledgers under `.scratch/bcoem-next/`, the parity
and graduation reports they reference, and the verification tooling in
`tools/`.

---

## What BCOE&M is

BCOE&M is the open-source platform behind hundreds of homebrew competitions:
entrant registration and entry management, entry fees, flight/table/judge
assignment, BJCP-style scoring and best-of-show rounds, an evaluation
sub-app for scoresheets, check-in, and every printed artifact a competition
physically needs (pull sheets, bottle labels, table cards, results, staff
points, maps). It has run real competitions for over a decade, and its
database schema and judging rules encode that accumulated domain knowledge.
This project exists to keep that capability alive on foundations that will
still be maintained in 2030.

## Why a ground-up rewrite

The legacy codebase (`brewcompetitiononlineentry`, modernization branch) works,
but carries specific, evidenced risks:

**SQL built by string interpolation.** Queries are assembled with `sprintf`
from request and session data across `includes/db/*.db.php` — e.g.
`entries_by_style.db.php:74` interpolates `$_SESSION['comp_id']` and category
values straight into SQL text. There is no parameter binding layer to audit;
every query is a potential injection surface.

**End-of-life payment transport.** Payment confirmation rides PayPal IPN
(`ppv.php`). IPN reaches end-of-life January 2027 and new IPN credentials
stopped being issued at the end of 2025 — there is no migration path for new
tenants. The IPN handler also has correctness problems documented in
`.scratch/bcoem-next/ledger/payments.md`: `payment_status` is never gated, so
Pending/Failed/Refunded notifications still mark entries paid (`ppv.php:139-160`);
fee amounts are not validated against what is owed (`ppv.php:38,:46`); there is
no idempotency, so duplicate notifications re-write state. The `payments` table
the handler tries to log into does not even exist in the schema — the insert
silently fails, meaning legacy has **no working payment ledger at all**.

**Vendored, unmaintained libraries.** FPDF, MysqliDb/dbObject, phpass-era auth,
`tiny_but_strong`, markdownify, `is_email`, and a QR generator are all vendored
copies nobody upstream maintains. PDF generation depends on one of them.

**Data-loss-prone archive semantics.** Competition close-out renames live
tables to `_<suffix>` siblings, recursively deletes all uploaded scoresheet
PDFs (irrecoverable), deletes custom style types, and destroys every entrant
account except the performing admin's. Purge flows destroy unpaid/stale entries
and their child rows with no archive copy. Nothing is recoverable from within
the application — hosting-layer backups are the only safety net. The full
retention statement is pinned in `.scratch/bcoem-next/ledger/archive-purge.md`.
Latent bugs compound this: table-delete cascades once deleted
judging-assignments by score-id collision (id-collision data loss, fixed in the
port).

**No static-analysis or testing headroom.** No strict types, no automated test
suite, no framework tooling; a long in-place `update/*.php` migration history
makes fresh installs fragile and upgrades risky.

The port does not attempt to patch these in place. It re-hosts the exact
behavior on Laravel 12 / PHP 8.5, where each risk class has a structural fix:
Eloquent with bound parameters everywhere, Stripe Connect plus manual marking
behind a gateway adapter, dompdf instead of vendored FPDF, Laravel's auth and
mailers, and the legacy schema loaded verbatim so real tenant dumps work with
zero migrations.

## How the port de-risks a rewrite

Rewrites fail by silently changing behavior users depend on. This project
treats the legacy app as the executable spec (spec decision D3) and builds
proof before code:

1. **Behavior ledgers** (`.scratch/bcoem-next/ledger/*.md`) pin each module's
   quirks with source citations *before* it is ported — including the weirdness
   that must be preserved (score place `'5'` is the storage code for Honorable
   Mention; BOS trim-down compared backwards in legacy and works only by
   string-comparison accident) and deliberate divergences where legacy was
   broken (silent truncation, dead gates).
2. **Characterization tests** turn those ledger entries into CI-enforced pins —
   19 characterization tests among the suite, plus DB-state convergence tests
   for flows that cannot be page-diffed.
3. **Parity harness** (`tools/parity/parity.sh`) boots the legacy oracle and
   the port side-by-side on copies of the *same* tenant dump, fetches every
   public URL from both, normalizes away csrf/tokens/session chrome, and diffs
   HTML per URL. Built in Phase 0, before any feature code, so every later
   slice landed against a working safety net. CSV export has its own byte-parity
   leg (`tools/parity/fetch_export.sh`) — raw response bodies must `cmp`
   identical.
4. **Graduation gate** (spec §8) is the only path to "live": full URL
   inventory, full test suite, a complete simulated season executed identically
   on both apps with DB-row diffing, a security review, and a performance
   smoke. Pass means proposing a cutover pilot for one willing tenant; fail
   leaves this stream sandboxed while production continues unaffected.

The methodology works because it separates *what the software does* (pinned by
ledgers and tests, diffed against the oracle) from *how it is implemented*
(free to change). Undocumented domain logic — rounding rules, window states,
timezone epochs, entity cleanup — cannot be lost silently when it is asserted
in CI and byte-compared against a running oracle on every gate.

## Status: slices A–D complete, graduation gate PASS (2026-08-25)

| Slice | Scope | Gate result |
|---|---|---|
| A | Read-only public surface | zero content diffs vs legacy on 3 dumps |
| B | Accounts, registration, payments (Stripe Connect + manual marking) | 6/6 URLs zero-diff ×3 dumps; register→enter→pay DB-convergence proven |
| C | Judging: flights, scoring, BOS, eval sub-app, barcode check-in | 6/6 zero-diff regression; season-leg DB convergence |
| D | Outputs (21 PDFs), CSV export, admin back-office, archive/purge | 6/6 zero-diff; CSV `cmp` IDENTICAL (1,339 bytes) |

Graduation gate legs (`.scratch/bcoem-next/graduation/summary.md`):

- **URL inventory**: 81/81 non-parameterized GET routes respond 2xx/3xx, zero
  404/500, on two corpus dumps (`tools/graduation/url_inventory.sh`).
- **Tests**: 563/563 via `php artisan test`; PHPStan max level 0 errors with a
  permanently empty baseline; Pint clean.
- **Simulated season** (`tools/graduation/season_sim.sh`): register → pay →
  check-in → assign → score → BOS → results/outputs on both apps;
  `brewing` (paid/confirmed), `judging_scores`, and `judging_scores_bos` rows
  row-identical; CSV byte-parity modulo randomized judging numbers/timestamps.
- **Security review**: no sprintf-SQL anywhere (bindings everywhere, every raw
  fragment constant or allow-list derived); dual-layer auth on every
  non-public route; upload validation reviewed per-surface. One finding found
  **and fixed during the gate**: scoresheet PDFs moved from webroot-public
  storage (proven unauthenticated download) behind an authorized stream.
- **Performance smoke** (`tools/graduation/perf_smoke.sh`, 500-entry dump,
  25 samples/page): every render page p95 < 300 ms (worst 272 ms). Documented
  exemption: login POST ~836 ms p95 is bcrypt cost 12 — deliberate KDF cost,
  CPU-bound by design.

## Development

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan test                 # 563 tests, MySQL bcoem_test required
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --dirty
```

Feature tests share a local MySQL database `bcoem_test` with the
`baseline_`-prefixed legacy schema (see CI workflow).

## Verification tooling

All dual-app tools take `LEGACY_DIR` (path to a legacy oracle checkout) and
`DUMP_SQL` (tenant dump). Requires mysql client, PHP >= 8.3, local MySQL.

```bash
# Parity harness — boots both apps on dump copies, diffs public URLs
LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
PARITY_DB_PREFIX= PARITY_DB_PASS=root tools/parity/parity.sh

# Byte-parity leg for the CSV export (admin login on both apps, cmp bodies)
LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
PARITY_DB_PASS=root tools/parity/fetch_export.sh

# Graduation legs
DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql tools/graduation/url_inventory.sh
LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
PARITY_DB_PASS=root tools/graduation/season_sim.sh
./tools/graduation/perf_smoke.sh          # add LEGACY_CONTEXT=1 for reference numbers
```

Reports land in `tools/parity/reports/run-*` (gitignored) and
`.scratch/bcoem-next/graduation/`. Auth-gated surfaces are validated by
DB-state convergence tests rather than anonymous page diffs — external side
effects (Stripe, email) are tested in test/mock modes by design.

## Layout

- `app/Http/Controllers/{Admin,Archive,Eval,Judging,Output}` — slice-scoped controllers
- `app/Support/{Judging,Eval,Results,Outputs,Tenant}` — ported engines (flight assignment,
  eval consensus, winners rollups, PDF pipeline, tenant context)
- `routes/{admin,archive,backoffice,eval,judging*,outputs}.php` — per-slice route files
- `tools/parity/`, `tools/graduation/` — dual-app verification tooling
- `.scratch/bcoem-next/` — spec, per-phase tickets, behavior ledgers, gate summaries

## Benefits over legacy

| | Legacy | bcoem-next |
|---|---|---|
| Payments | PayPal/IPN (EOL Jan 2027); no working ledger | Stripe Connect (tenant-owned funds) + admin manual marking; idempotent webhooks; auditable `payments` rows |
| Security posture | sprintf-SQL interpolation throughout; SVG uploads allowed; public scoresheet files | Eloquent bindings everywhere; content-sniffed uploads, extension allowlist; authorized streams for entrant documents |
| PDFs | Vendored FPDF | dompdf behind one `StreamPdf` helper; deterministic Blade-rendered outputs |
| Types / analysis | none | `declare(strict_types=1)` everywhere; PHPStan max, permanently empty baseline |
| Testing | manual | 563 automated tests + formal parity harness vs the running legacy oracle |
| Fresh installs / upgrades | migration archaeology | Legacy schema as-is; real tenant dumps load directly; zero-migration cutover path |
| Multi-tenant path | HOSTED/SINGLE mode tangles | Tenant context isolated in `app/Support/Tenant`; SINGLE-mode remnants dropped |

## Honest limitations

- **Documented divergences exist**, each recorded in the relevant ledger and
  controller docblock. Most fix latent legacy bugs (BOS trim-down direction,
  silent MySQL truncation, id-collision cascade deletes, dropoff-delete
  no-op); one is a compatibility relaxation: 17 screens legacy restricted to
  `userLevel==0` accept level-1 admins in the port (uniform-admin convention).
- **Payments rows are port-only in simulations**: legacy's sole writer was the
  IPN handler writing to a nonexistent table, so season-sim convergence is
  proven on brewing flags; the `payments` ledger is a designed D2 deviation,
  not a ported artifact.
- **PDF visual sign-off pending.** Generated artifacts are saved in
  `.scratch/bcoem-next/graduation/season-pdfs/`; deterministic outputs are
  content-tested, but a human still needs to approve the rendered documents
  (port PDFs vs legacy HTML print views) before cutover.
- **No cutover yet.** The gate passing proposes — does not execute — a pilot
  with one willing tenant. Until then this stream stays sandboxed.
- Some corpus-dependent behaviors remain unpinned because no dump exercises
  them (winner-delay announcement windows, displayable-archive past-winners);
  they are listed as residual-diff caveats in the Slice A gate summary.

## Explicitly not ported

HOSTED/SINGLE mode remnants, sso/ dir, NHC flag; deprecated themes
(claussenii, naardenensis); PayPal/IPN transport (replaced per D7; modern-PayPal
Checkout and Square may return as future gateway adapters if tenant demand
proves it); vendored phpass, MysqliDb, dbObject, tiny_but_strong, markdownify,
is_email, qr_code libraries (Laravel/native equivalents); `update/*.php`
migration history (fresh install baseline only). See spec §9.

## Crediting legacy

This project exists because [BCOE&M](https://www.brewingcompetitions.com/)
([`brewcompetitiononlineentry`](https://github.com/geoffhumphrey/brewcompetitiononlineentry),
modernization branch) was excellent software. The database schema, every screen,
every output document, and every judging rule here is a faithful port of the
original's design and decades of community refinement. All credit for the
domain model and feature set belongs to the legacy project and its maintainers;
bcoem-next only re-hosts that work on modern foundations.

## License

Licensed under the **[GNU General Public License](LICENSE)**, honoring the
licensing tradition of the original BCOE&M project.
