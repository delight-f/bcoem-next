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

Entrants pick their club from a dropdown fed by three sources: names the
organizer added, names entrants have already typed, and a maintained central
list of homebrew clubs mirrored locally.

Mirroring is two-stage, so a bad day upstream can never break a running
competition:

1. `tools/clubs-sync/convert.js` evaluates the upstream
   [`homebrew-clubs-list`](https://github.com/geoffhumphrey/homebrew-clubs-list)
   JavaScript in an isolated VM and publishes a versioned `clubs.json` to the
   public [`delight-f/clubs-list`](https://github.com/delight-f/clubs-list) repo
   via `.github/workflows/sync-clubs-list.yml`, committing only when the content
   changes.
2. The app mirrors that JSON into its local `clubs` table. It never parses
   JavaScript — it depends on the published JSON shape alone, so every upstream
   edit stays behind a pipeline tested on its own.

A timeout, non-2xx response or malformed payload is a **no-op**: nothing is
written, a warning is logged, and the site keeps the last good list. Clubs that
drop off the central list are **never deleted** — historical entries reference
them — and surface in an admin review list instead. Where a synced club collides
with a local name, the local spelling wins.

### Setup

Nothing to enable by hand: the default already points at the published file, and
**the tables and first sync arrive with a normal update.** Copy a new release
over the site and run the upgrade wizard (the **Upgrade** banner, or `/upgrade`);
the migration creates the `clubs` tables and the wizard primes the list — no
shell, `artisan` or cron required. The club picker also repairs the list on first
use, so a host that skips the version jump self-heals.

SSH operators can run the same steps directly. Prefer `app:upgrade` over a bare
`migrate`, which would also try the framework baseline migrations that collide
with the legacy schema:

```bash
php artisan app:upgrade   # migrate + first sync, backup first
php artisan clubs:sync    # re-run the sync on demand
```

Override the source only if you mirror the artifact yourself:

```dotenv
CLUBS_LIST_URL=https://raw.githubusercontent.com/delight-f/clubs-list/main/dist/clubs.json
```

`clubs:sync` is scheduled daily and needs no queue worker; where the host has no
cron, the picker's on-demand refresh covers it. **Admin → Clubs List** shows the
last-synced version, a **Sync now** button, and clubs that have dropped off.

Running your own publishing pipeline (the optional half) needs a public repo for
the artifact plus a **write deploy key** stored as the `CLUBS_LIST_DEPLOY_KEY`
Actions secret — the workflow checks out the publishing repo over SSH with it. A
deploy key is used rather than a personal access token because it can be created
non-interactively and grants write access to that one repository only.

## Installing and upgrading

The old routine was: download a source zip, FTP it up, hand-edit the config, and
hope. bcoem-next replaces it with one coherent system — a properly built release
artifact, and a single pair of service classes, `InstallationService` and
`UpgradeService`, that hold all the install, backup, migration and validation
logic exactly once. Three thin interfaces sit on top of those services, so none
of them re-implements any of it:

| Interface | Who it is for | How it runs |
|---|---|---|
| CLI | developers, and the SSH script | `php artisan app:install` / `app:upgrade` |
| Web wizard | organisers with only FTP access | `/install` and `/upgrade` |
| SSH script | VPS operators who want one command | `scripts/install.sh` |

Whatever the interface, it is the same code path: there is exactly one place
that knows how to stand up a fresh install, and exactly one place that knows how
to back up a database and migrate an existing one.

### The release artifact

A release is a zip built from a tag. `.github/workflows/release.yml` runs the
test suite first — a failing matrix leg means no zip and no release — then
packages with `build/release.sh <version>`, asserts that the `VERSION` file
inside the zip equals the tag with the leading `v` stripped, and attaches the
zip to a GitHub Release whose notes come from the matching `CHANGELOG.md`
section. Re-running the workflow on the same tag updates the release in place.

`build/release.sh` copies the tree without `.git`, `.github`, `node_modules`,
`tests`, `build/` or local artifacts, runs `composer install --no-dev` and
`npm ci && npm run build` **inside the package**, deletes `node_modules`, and
writes a `VERSION` file. Because `vendor/` and the compiled assets are already
in the zip, the target host needs only PHP and a web server — no Composer, no
Node.

The zip also carries a credential-free placeholder `.env`: a build-time
`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stack`, and
file-backed session and cache with a sync queue. It contains no database, mail
or other credentials. It is what lets a freshly uploaded copy boot far enough to
serve the installer with no database configured at all, which is what makes the
wizard below reachable by FTP alone. `InstallationService::install()` rewrites it
with the real settings on the first run.

### Three ways to install

**CLI.** `php artisan app:install` prompts for the database and site details, or
takes every value as a flag (with `--no-interaction`) for unattended use:

```bash
php artisan app:install \
  --db-host=127.0.0.1 --db-port=3306 --db-name=bcoem \
  --db-username=bcoem --db-password=secret \
  --app-url=https://beer.example.com \
  --admin-name="Club Admin" --admin-email=admin@example.com --admin-password=secret
```

The two secrets can stay out of the process list entirely. The explicit
`--db-password` / `--admin-password` flags remain (that is the documented
non-interactive interface), but you can instead pipe one line each to
`--db-password-stdin` / `--admin-password-stdin`, or set
`BCOEM_INSTALL_DB_PASSWORD` / `BCOEM_INSTALL_ADMIN_PASSWORD`. Precedence is
explicit flag, then stdin, then environment, then the interactive prompt:

```bash
printf '%s\n%s\n' "$DB_PASSWORD" "$ADMIN_PASSWORD" |
  php artisan app:install --no-interaction \
    --db-host=127.0.0.1 --db-port=3306 --db-name=bcoem --db-username=bcoem \
    --db-password-stdin --app-url=https://beer.example.com \
    --admin-name="Club Admin" --admin-email=admin@example.com --admin-password-stdin
```

It checks the preconditions, tests the database connection, and only then
installs — a bad password stops before anything is written.

**Web wizard.** `/install` walks through six screens — welcome, system check,
database connection, site details, confirm, progress — with no terminal use at
all. The connection test and the progress poll are small `fetch` calls, and the
install itself runs inline on the sync queue, so a fresh upload needs no queue
worker. A resubmitted confirmation screen cannot install twice.

**SSH script.** On a VPS you already have SSH on:

```bash
curl -sSL https://get.yourapp.com/install.sh | bash
```

`scripts/install.sh` checks PHP, the required extensions and `unzip`, downloads
the release zip (or takes a local `--zip-file`), and then drives `php artisan
app:install` with the collected values. The database and admin passwords are
piped to that command on stdin rather than passed as flags, so they never
appear in the process list. It adds the Laravel scheduler cron entry if one is
not already present. The hosting of that short URL is separate infrastructure,
decided elsewhere.

### Upgrading

- **CLI** — `php artisan app:upgrade` takes a backup and applies pending
  updates. It confirms first, or accepts `--force` for unattended use.
- **Web wizard** — `/upgrade` is reachable by Top-Level Administrators only, and
  a dismissable banner appears when newer files are already on the server.
  Non-admins neither see the banner nor can reach the wizard.
- **SSH script** — the same `scripts/install.sh` detects an existing install and
  runs the upgrade path: it stages the new version beside the live one, copies
  the live `.env` and `storage/` across (the release zip never contains
  credentials or uploaded data), swaps directories keeping the old one at
  `<target>.bak-<timestamp>`, and runs `app:upgrade`.

Every path runs the same order, and that order is deliberate:

1. **Back up first**, before anything touches the database. This shells out to
   `mysqldump`; if that binary cannot be executed, it falls back to a PHP export
   of every table, so a backup always exists.
2. **Verify the backup** (the file exists and is non-empty). If verification
   fails the run stops before any change, and says so.
3. Enter maintenance mode.
4. Run pending migrations.
5. Run any version-specific fixups registered for that version jump.
6. Clear caches.
7. Update the stored version marker.
8. Leave maintenance mode.

**There is no automatic database rollback.** If a step after the backup fails,
the backup file's path travels with the error so every interface can surface it,
and a person decides whether to restore. The SSH script likewise never swaps the
old files back on its own. Upgrading an already-current install is a safe no-op
that still takes the backup, so a re-run after a failed attempt always has its
safety net.

### Safety guarantees

- **Preconditions fail before anything is written.** The PHP version is checked
  against `composer.json`'s constraint with `composer/semver` — not a
  hand-parsed version — and the required extensions are read from the same
  `composer.json` entries CI and the SSH check script use. Storage writability,
  and free disk space for the backup, are checked too.
- **A wrong database password changes nothing.** The connection is tested before
  install writes a file, and the failure carries a plain-language message
  ("That database password looks wrong…") rather than a raw driver error.
- **Installing twice is refused**, and upgrading a site that is not installed is
  refused — both with typed exceptions, never a silent overwrite.
- **The release zip carries no secrets and no data** — no real `.env`, no
  `storage/` contents; the SSH upgrade copies those from the live install.
- **Wizards disappear when they have nothing to do.** Once installed and
  current, `/install` and `/upgrade` both return 404.

### Checks and diagnostics

```bash
php artisan app:version          # the installed version
php artisan app:version --check  # …and whether a newer release exists
php artisan app:health           # database, storage, extensions, queue
```

`app:version --check` asks GitHub for the latest release; on a host with no
outbound network access it simply omits the "update available" line instead of
erroring. `app:health` reuses the installer's own precondition checks, so it
cannot disagree with what an install would require.

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
