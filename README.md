<div align="center">

# 🍺 bcoem-next

**A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/) —
Brew Competition Online Entry & Management**

Same database schema, same URLs, same output documents. Rebuilt on PHP 8.4+,
Laravel 13, Bootstrap 5, Vite and Stripe.

[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/tests-1%2C100%2B%20passing-brightgreen)](.github/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208%2C%20empty%20baseline-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-Pint-F2C55C)](https://github.com/laravel/pint)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

</div>

> [!WARNING]
> **Alpha.** Feature-complete and verified against the legacy application, but
> not yet proven across a full production season. Treat it as production-capable
> with caution, and keep backups.

## Why this rewrite

The legacy BCOE&M app works, but its risks are structural — not fixable by
patching: SQL assembled by string interpolation, a payment transport (PayPal
IPN) at end-of-life with no migration path and no working ledger, unmaintained
vendored libraries, and no static analysis or automated tests. bcoem-next
re-hosts the exact behaviour with a structural fix for every one of those
classes of defect.

- **Zero-migration cutover** — an existing BCOE&M tenant dump loads as-is. No
  conversion scripts, no data transformation.
- **Behaviour pinned by tests, not assumed** — characterization tests lock the
  legacy semantics users depend on (fee math, window states, judging order, BOS
  eligibility), including the quirks that must be preserved.
- **1,100+ automated tests** run in CI against MySQL 8 and the real baseline
  schema.
- **PHPStan level 8, permanently empty baseline** — zero suppressions, so a
  regression cannot hide.
- **Payments you can reconcile** — Stripe Connect with idempotent webhooks and
  an auditable `payments` ledger.

| Dimension | Legacy | bcoem-next |
|---|---|---|
| Payments | PayPal/IPN (EOL Jan 2027) | Stripe Connect, manual marking, idempotent webhooks, `payments` ledger |
| SQL | `sprintf`-interpolated throughout | Bound parameters everywhere |
| PDFs | Vendored FPDF | dompdf behind one stream helper, deterministic Blade templates |
| Frontend | Bootstrap 3 + jQuery | Bootstrap 5 on Vite |
| Types & analysis | None | `declare(strict_types=1)`, PHPStan level 8 |
| Testing | Manual | 1,100+ automated tests in CI |
| Upgrades | Long in-place migration history | Legacy schema as-is; wizard or CLI |
| Runtime | Unsupported PHP/extensions | PHP 8.4 & 8.5, MySQL 8 — enforced in CI |

## Features

- **Entrants & entries** — registration wizard, brewer profiles, entry
  creation and editing, entry-limit engine, lifecycle gates, drop-off.
- **Payments** — Stripe Connect (tenant-owned funds), refunds, admin manual
  marking, tiered fees via `FeeCalculator`.
- **Judging** — tables and flights, scoring, best-of-show, special awards,
  judging-number regeneration, pool assignment, eval scoresheet import.
- **Outputs** — 21 PDFs (pull sheets, QR-coded labels, table cards,
  scoresheets, results), CSV exports, awards presentation with Best Brewer /
  Best Club.
- **Admin back-office** — dashboard, participants, entries, payments ledger,
  site preferences, competition info and dates, style types, archive/purge,
  QR check-in.
- **Public site** — live competition status, volunteers, contact, sponsors,
  results, plus a redirect map so every legacy `.php` URL still resolves.
- **Central clubs list** — a maintained homebrew-club list mirrored into the
  database and feeding the entrant picker. See
  [Central clubs list](#central-clubs-list).

## Requirements

- PHP 8.4+ with `gd`, `intl`, `mbstring` and `mysqli`
- MySQL 8
- Composer and Node 22+ — build only; release zips ship `vendor/` and compiled
  assets

## Getting started

A release zip needs only PHP and a web server. Three interfaces drive the same
install code:

| Interface | For | Command / URL |
|---|---|---|
| Web wizard | hosts with only FTP | `/install` |
| CLI | developers, unattended installs | `php artisan app:install` |
| SSH script | VPS operators | `scripts/install.sh` |

**CLI** prompts for the database and site details, or takes every value as a
flag for unattended use:

```bash
php artisan app:install \
  --db-host=127.0.0.1 --db-port=3306 --db-name=bcoem \
  --db-username=bcoem --db-password=secret \
  --app-url=https://beer.example.com \
  --admin-name="Club Admin" --admin-email=admin@example.com --admin-password=secret
```

Both secrets can stay off the process list via `--db-password-stdin` /
`--admin-password-stdin`, or `BCOEM_INSTALL_DB_PASSWORD` /
`BCOEM_INSTALL_ADMIN_PASSWORD`.

**Web wizard** — `/install` runs six screens (welcome, system check, database,
site details, confirm, progress) with no terminal. The install runs inline on
the sync queue, so a fresh upload needs no queue worker, and a resubmitted
confirmation cannot install twice.

### Upgrading

- **Web** — a Top-Level Administrator sees a dismissable **Upgrade** banner;
  `/upgrade` is reachable by FTP alone.
- **CLI** — `php artisan app:upgrade` (add `--force` for unattended use).
- **SSH** — `scripts/install.sh` stages the new version beside the live one,
  carries `.env` and `storage/` across, swaps while keeping a
  `.bak-<timestamp>`, then runs `app:upgrade`.

Every path runs the same order: back up → verify → maintenance mode → migrate →
version fixups → clear caches → version marker. The backup uses `mysqldump`,
falling back to a pure-PHP export when the binary is blocked, so a backup always
exists before anything changes.

> [!IMPORTANT]
> There is **no automatic database rollback**. If a step fails after the backup,
> the backup path travels with the error so it can be surfaced, and a person
> decides whether to restore.

### Diagnostics

```bash
php artisan app:version          # the installed version
php artisan app:version --check  # ...and whether a newer release exists
php artisan app:health           # database, storage, extensions, queue
```

## Central clubs list

Entrants pick their club from names the organizer added, names entrants have
already typed, and a maintained central list of homebrew clubs mirrored locally.

Mirroring is two-stage, so a bad day upstream can never break a running
competition:

1. `tools/clubs-sync/convert.js` evaluates the upstream
   [`homebrew-clubs-list`](https://github.com/geoffhumphrey/homebrew-clubs-list)
   JavaScript in an isolated VM and publishes a versioned `clubs.json` to the
   public [`delight-f/clubs-list`](https://github.com/delight-f/clubs-list) repo,
   committing only when the content changes.
2. The app mirrors that JSON into its local `clubs` table. It never parses
   JavaScript, so every upstream edit stays behind a pipeline tested on its own.

A timeout, non-2xx response or malformed payload is a **no-op**: nothing is
written, a warning is logged, and the last good list stays in use. Dropped clubs
are **never deleted** (historical entries reference them) and surface for admin
review; on a name collision, the local spelling wins.

**No setup needed.** The tables and first sync arrive with a normal update — run
the upgrade wizard and the migration creates the `clubs` tables while the wizard
primes the list. The club picker also repairs the list on first use, so a host
without cron self-heals. On SSH, `php artisan app:upgrade` followed by
`php artisan clubs:sync` does the same; **Admin → Clubs List** shows the
last-synced version, a **Sync now** button, and any dropped clubs.

## Development

Requires PHP 8.4+, Composer, Node 22+ and MySQL 8.

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

The suite runs against MySQL with the `baseline_`-prefixed legacy schema, built
from the baseline dump in `sql/`:

```bash
mysql -h 127.0.0.1 -uroot -proot -e "CREATE DATABASE IF NOT EXISTS bcoem_test"
mysql -h 127.0.0.1 -uroot -proot bcoem_test < sql/bcoem_baseline_3.0.X.sql

# Port-added migrations only — the framework defaults collide with the baseline_ tables.
for f in $(ls database/migrations/*.php | grep -v '0001_'); do
  php artisan migrate --force --path="$f"
done

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=bcoem_test \
  DB_USERNAME=root DB_PASSWORD=root DB_TABLE_PREFIX=baseline_ \
  BCOEM_TEST_DB_HOST=127.0.0.1 BCOEM_TEST_DB_NAME=bcoem_test \
  BCOEM_TEST_DB_USER=root BCOEM_TEST_DB_PASS=root

vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

## Repository layout

```
app/Http/Controllers/   Admin, Archive, Eval, Judging, Output, Auth and public controllers
app/Support/            Ported engines: Payments, Entries, Judging, Eval, Results,
                        Outputs, Awards, Brewer, Styles, Security, Wizard, Tenant
routes/                 web, admin, archive, backoffice, eval, judging*, outputs, wizard
resources/              Blade views, Bootstrap 5 CSS/JS (Vite)
database/, sql/         Legacy-compatible baseline schema (sql/bcoem_baseline_3.0.X.sql)
tests/                  Unit, Integration, Characterization, Feature, Browser (Dusk)
legacy/                 Frozen legacy checkout — read-only behavioural reference
```

## Credits

bcoem-next exists because the original
[BCOE&M](https://www.brewingcompetitions.com/)
([`brewcompetitiononlineentry`](https://github.com/geoffhumphrey/brewcompetitiononlineentry))
was excellent software. The database schema, every screen, every output document
and every judging rule here is a faithful port of its design and years of
community refinement. All domain credit belongs to the legacy project and its
maintainers; a frozen copy lives in `legacy/` as the reference that made the
rewrite safe.
