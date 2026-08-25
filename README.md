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
supported 2026 stack.

---

## Why a rewrite?

Legacy BCOE&M served homebrew competitions faithfully for over a decade, but it
carries weight that has become hard to maintain:

- **EOL dependencies.** PayPal/IPN transport reaches end-of-life (Jan 2027);
  the codebase vendors its own copies of FPDF, MysqliDb, and phass-era auth.
- **Unsupported PHP idioms.** No strict types, no static analysis headroom,
  no modern framework tooling for contributors.
- **Migration debt.** A long in-place migration history makes fresh installs
  fragile and upgrades risky.

bcoem-next ports the *behavior*, not the baggage.

## Benefits over legacy

| | Legacy | bcoem-next |
|---|---|---|
| Payments | PayPal / IPN (EOL) | Stripe Connect |
| PDFs | Vendored FPDF | dompdf behind one `StreamPdf` helper |
| Types / analysis | — | `declare(strict_types=1)`, PHPStan max with permanently empty baseline |
| Testing | Manual | 563 automated tests + formal parity harness vs. the legacy oracle |
| Fresh installs | Migration archaeology | Legacy schema as-is; no migrations for tenant data |

## Crediting legacy

This project exists because [BCOE&M](https://www.brewingcompetitions.com/)
([`brewcompetitiononlineentry`](https://github.com/geoffhumphrey/brewcompetitiononlineentry),
modernization branch) was excellent software. The database schema, every screen,
every output document, and every judging rule here is a faithful port of the
original's design and decades of community refinement. All credit for the
domain model and feature set belongs to the legacy project and its maintainers;
bcoem-next only re-hosts that work on modern foundations, under GPL like its
ancestor.

## Status

**Phase 5 complete — graduation gate (spec §8) in progress.**

| Slice | Scope | State |
|---|---|---|
| A | Public surface | ✅ zero content-diff vs legacy |
| B | Accounts + registration + payments (Stripe) | ✅ DB-convergence proven |
| C | Judging: flights, scoring, BOS, eval sub-app, check-in | ✅ 6/6 parity ×3 dumps |
| D | Outputs (21 PDFs), CSV export (byte-identical), admin back-office, archive/purge | ✅ |
| §8 | Graduation: dual-app simulated season, security review, perf smoke | 🚧 |

## Verification model

The port is kept honest against the legacy oracle:

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

HOSTED/SINGLE mode remnants, deprecated themes, PayPal/IPN transport (replaced
by Stripe), vendored FPDF/MysqliDb/phpass-era libraries, legacy migration
history. See spec §9.

## License

Licensed under the **[GNU General Public License](LICENSE)**, honoring the
licensing tradition of the original BCOE&M project.
