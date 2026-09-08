<div align="center">

# bcoem-next

**A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/) —
Brew Competition Online Entry & Management**

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-latest-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/tests-563%2B%20passing-brightgreen)](.github/workflows/)
[![PHPStan](https://img.shields.io/badge/PHPStan-max%2C%20empty%20baseline-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-Pint-F2C55C)](https://github.com/laravel/pint)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

</div>

A behavior-matched modernization of the classic BCOE&M homebrew competition
platform: same database schema, same URLs, same outputs — on a fully
supported stack (PHP 8.4, Laravel, Bootstrap 5, Vite, Stripe). The port is generally complete, but is in **alpha** stage at present - bugs are likely to be present.

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

The legacy app works but carries structural risks that patching cannot fix:
SQL assembled by string interpolation, a payment transport (PayPal IPN)
reaching end-of-life with no migration path, unmaintained vendored libraries,
and no static analysis or test suite. The port re-hosts the exact behavior
where each risk class has a structural fix:

| | Legacy | bcoem-next |
|---|---|---|
| Payments | PayPal/IPN (EOL Jan 2027), no working ledger | Stripe Connect + manual marking, idempotent webhooks, auditable `payments` rows |
| Security | sprintf-SQL throughout, public scoresheet files | bound parameters everywhere, validated uploads, authorized file streams |
| PDFs | vendored FPDF | dompdf behind one stream helper, deterministic Blade templates |
| Frontend | Bootstrap 3, jQuery-era chrome | single Bootstrap 5 dialect, Vite build |
| Types / analysis | none | `declare(strict_types=1)` everywhere; PHPStan max level, permanently empty baseline |
| Testing | manual | 563+ automated tests (characterization, feature, integration, Dusk) run in CI |
| Upgrades | long in-place migration history | legacy schema loaded as-is; real tenant dumps load directly; zero-migration cutover |

The general appearance of the app has been maintained with Bootstrap 5.

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

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan test                 # MySQL with the baseline_-prefixed legacy schema required
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --dirty
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
