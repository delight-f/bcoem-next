# bcoem-next

A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/)
(Brew Competition Online Entry & Management) — behavior-matched to the legacy
PHP app through a formal parity program, modernized onto a supported stack.

## Status

**Phase 5 complete — graduation gate (spec §8) in progress.**

| Slice | Scope | State |
|---|---|---|
| A | Public surface | ✅ zero content-diff vs legacy |
| B | Accounts + registration + payments (Stripe) | ✅ DB-convergence proven |
| C | Judging: flights, scoring, BOS, eval sub-app, check-in | ✅ 6/6 parity ×3 dumps |
| D | Outputs (21 PDFs), CSV export (byte-identical), full admin back-office, archive/purge | ✅ gate summary in `.scratch/bcoem-next/parity/slice-d/summary.md` |
| §8 | Graduation: dual-app simulated season, security review, perf smoke | 🚧 |

## Stack

- PHP 8.5, `declare(strict_types=1)` everywhere; latest Laravel
- MySQL against the legacy schema (all 24 tables; no migrations for tenant data)
- dompdf for the output pipeline; Stripe Connect for payments
- Max-level PHPStan with a **permanently empty baseline**; Pint

## Verification model

The port is kept honest against the legacy oracle
(`brewcompetitiononlineentry`, modernization branch):

1. **Characterization tests** pin exact legacy behaviors before reimplementation.
   Behavior ledgers live in `.scratch/bcoem-next/ledger/`.
2. **Parity harness** — `tools/parity/parity.sh` boots both apps side-by-side
   on identical corpus dumps and diffs every public URL:
   ```bash
   LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry \
   DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql \
   PARITY_DB_PREFIX= PARITY_DB_PASS=root tools/parity/parity.sh
   ```
3. **Byte-compare legs** — CSV exports must be byte-identical
   (`tools/parity/fetch_export.sh`).
4. Auth-gated surfaces are validated by DB-state convergence tests instead of
   page diffs.

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

## Layout

- `app/Http/Controllers/{Admin,Archive,Eval,Judging,Output}` — slice-scoped controllers
- `app/Support/{Judging,Eval,Results,Outputs,Tenant}` — ported engines (flight assignment,
  eval consensus, winners rollups, PDF pipeline, tenant context)
- `routes/{admin,archive,backoffice,eval,judging*,outputs}.php` — per-slice route files
- `tools/parity/`, `tools/graduation/` — dual-app verification tooling
- `.scratch/bcoem-next/` — spec, per-phase tickets, behavior ledgers, gate summaries

## Explicitly not ported

HOSTED/SINGLE mode remnants, deprecated themes, PayPal/IPN transport
(EOL Jan 2027; replaced by Stripe), vendored FPDF/MysqliDb/phpass-era libraries,
legacy migration history. See spec §9.
