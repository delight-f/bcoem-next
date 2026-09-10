<div align="center">

# bcoem-next

**A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/) —
Brew Competition Online Entry & Management**

Behavior-matched to the original: same database schema, same URLs, same
outputs. Re-hosted on PHP 8.4+, Laravel 13, Bootstrap 5, Vite and Stripe.

[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/tests-925%20passing-brightgreen)](.github/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208%2C%20empty%20baseline-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-Pint-F2C55C)](https://github.com/laravel/pint)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

</div>

> **Status: alpha.** Feature-complete and parity-verified against the legacy
> application, but not yet proven across a full production season. Treat it as
> production-capable with caution, and keep backups.

## What has been ported

- **Entrants & entries** — registration wizard, brewer profile forms, entry
  creation and editing, entry-limit engine, lifecycle gates, drop-off.
- **Payments** — Stripe Connect (tenant-owned funds, tenant-connected
  accounts), idempotent webhooks, refunds, admin manual marking, and the
  tiered fee model ported from legacy into a `FeeCalculator`. PayPal/IPN is
  retired.
- **Judging** — table and flight management, scoring, best-of-show, special
  best awards, judging-number regeneration, participant pool assignment, and
  the eval sub-app for scoresheet import.
- **Outputs** — 21 PDF artifacts (pull sheets, bottle/box/judge labels with
  scannable QR codes, table cards, scoresheets, results) rendered via dompdf,
  CSV exports, and the awards presentation with Best Brewer / Best Club
  standings.
- **Admin back-office** — dashboard rebuilt to the legacy link contract,
  participants, entries, payments ledger, site preferences, competition info,
  competition dates, style types, archive/purge, QR check-in.
- **Public site** — landing with live competition status, volunteers,
  contact, sponsors, results, anonymous sidebar, authenticated user chrome —
  plus a redirect map so every legacy `.php` URL still resolves.

## Why a rewrite, and what improved

The legacy application works, but carries structural risks that patching cannot
remove: SQL assembled by string interpolation, a payment transport (PayPal IPN)
at end-of-life with no migration path, unmaintained vendored libraries, and no
static analysis or automated tests. Each is a *class* of defect rather than a
bug, so the port re-hosts the exact behavior with a structural fix for every
class.

**At a glance**

- **Parity is proven, not asserted** — the legacy app runs side-by-side as an
  executable oracle over the same tenant dump, and every fetched page is diffed.
- **925 automated tests** (characterization, feature, integration, browser) run
  in CI against MySQL 8 with the real baseline schema.
- **PHPStan level 8 with a permanently empty baseline** — zero suppressions, so
  a regression cannot be hidden.
- **Zero-migration cutover** — an existing BCOE&M tenant dump loads as-is. No
  upgrade scripts, no data transformation.
- **Security is structural** — bound parameters everywhere, validated uploads,
  scoresheets behind authorized streams.
- **Payments are auditable** — Stripe Connect with idempotent webhooks and a
  ledger you can reconcile, replacing a transport with no working ledger.

| Dimension | Legacy | bcoem-next |
|---|---|---|
| Payments | PayPal/IPN (EOL Jan 2027), no working ledger | Stripe Connect (tenant-owned funds) + manual marking, idempotent webhooks, auditable `payments` ledger |
| Security | `sprintf`-interpolated SQL throughout, publicly reachable scoresheet files | Bound parameters everywhere, validated uploads, authorized file streams |
| PDFs | Vendored FPDF | dompdf behind a single stream helper, deterministic Blade templates |
| Frontend | Bootstrap 3, jQuery-era chrome | Single Bootstrap 5 dialect, Vite build; appearance preserved |
| Types & analysis | None | `declare(strict_types=1)` throughout; PHPStan level 8, empty baseline |
| Testing | Manual | 925 automated tests in CI (characterization, feature, integration, Dusk) |
| Upgrades | Long in-place migration history | Legacy schema loaded as-is; zero-migration cutover from any tenant dump |
| Supported runtime | Unsupported PHP and extensions | PHP 8.4 and 8.5, MySQL 8 — both enforced in CI |

## How parity is proven

Rewrites fail by silently changing behavior users depend on, so the legacy
app is treated as the executable spec and every slice landed against proof:

1. **Characterization tests** pin legacy semantics — fee math, window-state
   machine, judging-number ordering, BOS eligibility — in CI, including the
   quirks that must be preserved.
2. **Parity harness** (`tools/parity/parity.sh`) boots the legacy oracle and
   the port side-by-side on copies of the same tenant dump and diffs every
   fetched page; the CSV export has a byte-parity leg (`cmp`-identical).
3. **Graduation gate** (`tools/graduation/`) passed all five legs: 81/81
   routes respond, full test suite, a simulated season executed identically
   on both apps with DB-row diffing, a security review (whose one finding was
   fixed in-gate: scoresheets moved behind an authorized stream), and a
   performance smoke with p95 under 300 ms per page.

## Repository layout

```
app/Http/Controllers/   Admin, Archive, Eval, Judging, Output, Auth + public controllers
app/Support/            Ported engines: Payments, Entries, Judging, Eval, Results,
                        Outputs, Awards, Brewer, Tenant
routes/                 web, admin, archive, backoffice, eval, judging*, outputs
resources/              Blade views, Bootstrap 5 CSS/JS (Vite)
database/, sql/         Legacy-compatible baseline schema (bcoem_baseline_3.0.X.sql)
tests/                  Characterization, Feature, Unit, Integration, Browser (Dusk)
tools/                  parity/, graduation/ — dual-app verification tooling
docs/                   parity ledgers and the BS5 migration handoff
lang/                   Language packs with runtime toggle
legacy/                 Frozen legacy checkout — read-only verification oracle
```

## Development

Requires PHP 8.4+, Composer, Node 22+, and MySQL 8.

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

The suite runs against MySQL with the `baseline_`-prefixed legacy schema, built
from the parity oracle in `sql/`:

```bash
mysql -h 127.0.0.1 -uroot -proot -e "CREATE DATABASE IF NOT EXISTS bcoem_test"
mysql -h 127.0.0.1 -uroot -proot bcoem_test < sql/bcoem_baseline_3.0.X.sql

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=bcoem_test \
  DB_USERNAME=root DB_PASSWORD=root DB_TABLE_PREFIX=baseline_ \
  BCOEM_TEST_DB_HOST=127.0.0.1 BCOEM_TEST_DB_USER=root BCOEM_TEST_DB_PASS=root \
  BCOEM_TEST_DB_NAME=bcoem_test

# Port-added migrations only — the framework defaults collide with baseline_ tables.
for f in $(ls database/migrations/*.php | grep -v '0001_'); do
  php artisan migrate --force --path="$f"
done

vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

The dual-app verification tools take `LEGACY_DIR` (a legacy oracle checkout)
and `DUMP_SQL` (a tenant dump) and require a local MySQL server:

```bash
LEGACY_DIR=../brewcompetitiononlineentry DUMP_SQL=../corpus/anon-base.sql \
PARITY_DB_PASS=... tools/parity/parity.sh
```

## Credits

This project exists because the original
[BCOE&M](https://www.brewingcompetitions.com/)
([`brewcompetitiononlineentry`](https://github.com/geoffhumphrey/brewcompetitiononlineentry))
was excellent software. The database schema, every screen, every output
document, and every judging rule here is a faithful port of the original's
design and its many years of community refinement. All credit for the domain
model and feature set belongs to the legacy project and its maintainers —
bcoem-next only re-hosts that work on modern foundations, and keeps a frozen
copy of the legacy app in `legacy/` as the oracle that made the rewrite safe.

## License

Licensed under the [GNU General Public License](LICENSE), honoring the
licensing tradition of the original BCOE&M project.
