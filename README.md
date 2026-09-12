<div align="center">

# bcoem-next

**A ground-up Laravel rewrite of [BCOE&M](https://www.brewingcompetitions.com/) —
Brew Competition Online Entry & Management**

Same database schema, same URLs, same output documents — rebuilt on a supported
stack: PHP 8.4+, Laravel 13, Bootstrap 5, Vite and Stripe.

[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/tests-900%2B%20passing-brightgreen)](.github/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208%2C%20empty%20baseline-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-Pint-F2C55C)](https://github.com/laravel/pint)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

</div>

> **Status: alpha.** Feature-complete and verified against the legacy
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
- **Central clubs list** — the maintained homebrew-clubs list the legacy app
  fetched from a remote corpus is mirrored into the local database on a
  schedule and feeds the entrant picker. A bad upstream fetch is a no-op, and
  clubs are never removed silently.

## Why a rewrite, and what improved

The legacy application works, but carries structural risks that patching cannot
remove: SQL assembled by string interpolation, a payment transport (PayPal IPN)
at end-of-life with no migration path, unmaintained vendored libraries, and no
static analysis or automated tests. Each is a *class* of defect rather than a
bug, so the port re-hosts the exact behavior with a structural fix for every
class.

**At a glance**

- **Behaviour is pinned by tests, not assumed** — characterization tests lock
  the legacy semantics users depend on (fee math, window states, judging
  ordering, BOS eligibility), including the quirks that must be preserved.
- **900+ automated tests** (characterization, feature, integration, browser)
  run in CI against MySQL 8 with the real baseline schema.
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
| Frontend | Bootstrap 3, jQuery-era chrome | Single Bootstrap 5 dialect, Vite build, modernised public UI |
| Types & analysis | None | `declare(strict_types=1)` throughout; PHPStan level 8, empty baseline |
| Testing | Manual | 900+ automated tests in CI (characterization, feature, integration, Dusk) |
| Upgrades | Long in-place migration history | Legacy schema loaded as-is; zero-migration cutover from any tenant dump |
| Supported runtime | Unsupported PHP and extensions | PHP 8.4 and 8.5, MySQL 8 — both enforced in CI |

## How correctness is proven

Rewrites fail by silently changing behaviour users depend on, so the legacy
application is treated as the executable specification and every slice lands
against proof that survives in the repository:

1. **Characterization tests** pin legacy semantics — fee math, the window-state
   machine, judging-number ordering, BOS eligibility — including the quirks
   that must be preserved. They run in CI on every push.
2. **The legacy checkout is kept in `legacy/`** as a read-only behavioural
   reference, so any behaviour can still be traced to its original
   implementation rather than reconstructed from memory.
3. **Output artifacts are compared, not eyeballed** — the entries CSV export is
   byte-for-byte compatible with the legacy export (same BOM, header, column
   order and quoting), and the PDFs are generated from deterministic Blade
   templates.

## Central clubs list

Entrants pick their club from a dropdown. Those names come from three places:
what the organizer has added, what entrants have already typed, and a
maintained central list of homebrew clubs that the site mirrors locally and
refreshes on a schedule.

Mirroring is deliberately two-stage, so a bad day upstream can never break a
running competition:

1. **Upstream JavaScript is converted to JSON.** `tools/clubs-sync/convert.js`
   evaluates
   [`geoffhumphrey/homebrew-clubs-list`](https://github.com/geoffhumphrey/homebrew-clubs-list)'s
   `clubs.js` in an isolated VM, trims and de-duplicates the entries, and
   writes a versioned `clubs.json`. A scheduled workflow
   (`.github/workflows/sync-clubs-list.yml`) publishes it to the public
   [`delight-f/clubs-list`](https://github.com/delight-f/clubs-list) repo and
   commits only when the content-derived version changes.
2. **The app syncs that JSON into its local `clubs` table.** The Laravel side
   never parses JavaScript — it depends on the published JSON shape alone, so
   every upstream edit is contained behind a pipeline that is tested on its own.

Failures are contained by design: a timeout, non-2xx response or malformed
payload is a **no-op** — nothing is written, a warning is logged, and the site
keeps using the last good list. Clubs that drop off the central list are
**never deleted**, because historical entries reference them; they are surfaced
in an admin review list instead. Where a synced club collides with a local name,
the local spelling wins.

### Setup

The sync is inert until it is pointed at a published `clubs.json`, and the
default already targets the upstream-published file. To enable it:

1. Apply the migration that adds the clubs tables:
   ```bash
   php artisan migrate --force
   ```
2. Override the source only if you mirror the artifact yourself:
   ```dotenv
   CLUBS_LIST_URL=https://raw.githubusercontent.com/delight-f/clubs-list/main/dist/clubs.json
   ```
3. Run the first sync and check the counts:
   ```bash
   php artisan clubs:sync
   ```

`clubs:sync` is scheduled daily and needs no queue worker. The same actions are
available under **Admin → Clubs List**, which shows the last-synced version, a
**Sync now** button, and any clubs that have dropped off the central list.

Running your own publishing pipeline (the optional half) needs a public repo for
the artifact plus a **write deploy key** stored as the `CLUBS_LIST_DEPLOY_KEY`
Actions secret — the workflow checks out the publishing repo over SSH with it. A
deploy key is used rather than a personal access token because it can be created
non-interactively and grants write access to that one repository only.

## Repository layout

```
app/Http/Controllers/   Admin, Archive, Eval, Judging, Output, Auth + public controllers
app/Support/            Ported engines: Payments, Entries, Judging, Eval, Results,
                        Outputs, Awards, Brewer, Tenant
routes/                 web, admin, archive, backoffice, eval, judging*, outputs
resources/              Blade views, Bootstrap 5 CSS/JS (Vite)
database/, sql/         Legacy-compatible baseline schema (bcoem_baseline_3.0.X.sql)
tests/                  Characterization, Feature, Unit, Integration, Browser (Dusk)
tests/fixtures/         Legacy URL inventory backing the redirect contract
docs/                   BS5 migration handoff and planning notes
lang/                   Language packs with runtime toggle
legacy/                 Frozen legacy checkout — read-only behavioural reference
```

## Development

Requires PHP 8.4+, Composer, Node 22+, and MySQL 8.

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

The suite runs against MySQL with the `baseline_`-prefixed legacy schema, built
from the baseline dump in `sql/`:

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

## Credits

This project exists because the original
[BCOE&M](https://www.brewingcompetitions.com/)
([`brewcompetitiononlineentry`](https://github.com/geoffhumphrey/brewcompetitiononlineentry))
was excellent software. The database schema, every screen, every output
document, and every judging rule here is a faithful port of the original's
design and its many years of community refinement. All credit for the domain
model and feature set belongs to the legacy project and its maintainers —
bcoem-next only re-hosts that work on modern foundations, and keeps a frozen
copy of the legacy app in `legacy/` as the reference that made the rewrite safe.

## License

Licensed under the [GNU General Public License](LICENSE), honoring the
licensing tradition of the original BCOE&M project.
