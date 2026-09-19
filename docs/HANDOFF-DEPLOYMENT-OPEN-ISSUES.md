# Handoff — deployment system (issue #27): open issues

State as of 2026-09-12, after `1c41c24` (`feat(deploy): add release, install and upgrade
system`) shipped and pushed to `main`. Written for a **fresh agent session** picking up the
items that were deliberately flagged rather than fixed.

Read this with `docs/HANDOFF-OPEN-ISSUES.md` (still accurate for the non-deployment queue);
that document's suite caveats and conventions apply here too.

## What shipped, and where the code lives

Two service classes hold all install/upgrade logic exactly once; three thin interfaces sit
on them.

| Path | What it is |
|---|---|
| `app/Services/Installation/InstallationService.php` | the only place that stands up a fresh install |
| `app/Services/Installation/UpgradeService.php` | the only place that backs up + migrates |
| `app/Services/Installation/ReleaseUpdater.php` | the file-side updater (issue #42): download, unpack, swap the app tree — no DB logic |
| `app/Services/Installation/UpdateService.php` | the step list the browser walks: manual steps, or the file steps + upgrade steps |
| `app/Console/Commands/{Install,Upgrade,Version,Health}Command.php` | the CLI interface |
| `app/Http/Controllers/{Install,Upgrade}WizardController.php`, `routes/wizard.php`, `resources/views/wizard/` | the web interface |
| `app/Http/Middleware/EnsureInstalled.php` | install/upgrade detection and routing |
| `app/Support/Wizard/{ProgressTracker,RemoteVersionChecker}.php` | progress marker, release notice |
| `build/release.sh`, `.github/workflows/release.yml` | the release artifact |
| `scripts/install.sh`, `scripts/php-check.php` | the SSH interface |

**The invariant that must not be broken:** no caller may re-implement backup, migration,
precondition or validation logic. If a fix below appears to need it, call the existing
service method instead. The file-side updater follows the same rule: `ReleaseUpdater` moves
files only, then hands off to `UpgradeService` for the backup/migration/marker work — it
contains no database logic, and `UpdateService` only prepends the file steps to the existing
upgrade steps.

## Open items at a glance

| # | Item | Severity | Effort |
|---|---|---|---|
| 1 | Remote version notice points at the wrong GitHub repo — feature is silently dead | **High** | trivial |
| 2 | A large database cannot finish an install/upgrade in one sync request | **High** | large |
| 3 | `.env.example` drivers point at tables a fresh install does not have | Medium | small |
| 4 | `install()` probes the ambient connection, not the credentials it was given | Medium | small |
| 5 | The admin password sits in the session between wizard screens | Medium | small |
| 6 | `install.sh` passes secrets as command-line flags | Medium | small |
| 7 | `EnsureInstalled` runs on every web request with no cache | Low | small |
| 8 | `build/output/` is not gitignored | Low | trivial |
| 9 | Dirty-tree zips leak `.scratch/` and `.slop-scan.cache.json` | Low | trivial |
| 10 | `UpgradeFixups` registry is empty — mechanism unexercised | Low | later |
| 11 | The real release-download path was never exercised; no `shellcheck` in CI | Low | blocked |

---

## 1. The release notice queries the wrong repository

**Symptom.** The Top-Level-Administrator "new version available" notice (Task 2.2) never
appears on any install.

**Cause.** `config/services.php` defaults `services.github.repository` to `bcoem/bcoem-next`,
and `.env.example` ships the same value. This project's remote is
`delight-f/bcoem-next`. `RemoteVersionChecker` therefore calls
`api.github.com/repos/bcoem/bcoem-next/releases/latest`, gets a 404, swallows it into `null`
(by design), and the notice is silently suppressed — the failure mode is invisible.

**Constraint.** The checker must keep swallowing every failure; a host with no outbound access
must never see an error. Do not "fix" this by surfacing the failure.

**Suggested fix.** Change the default in `config/services.php` and the value in
`.env.example` to `delight-f/bcoem-next`. Add a test that the configured repository appears in
the requested URL, so a future default cannot drift again. Verify against the live API once a
release exists (item 11).

## 2. Large databases exceed a single sync request

**Symptom.** On a database big enough that install or upgrade runs longer than the host's
`max_execution_time` (30–60s on shared hosting), the web wizard's run is killed mid-flight.

**Constraint.** This is an explicitly deferred limitation in the issue: chunked, resumable
execution is materially more complex and was to be a separate task, not a hidden requirement.
The queue must stay `sync` — a freshly uploaded, not-yet-configured install cannot be assumed
to have a queue worker, and requiring one defeats the wizard's purpose. The progress marker
plus polling is what keeps the UI responsive; it does not extend the request's time budget.

**Suggested fix.** Do not add a queue worker. Instead make the work resumable: expose the
existing steps of `install()`/`upgrade()` as individually addressable units with a persisted
cursor in `ProgressTracker`, and have the polling endpoint run the next unit per request. That
keeps the services as the single source of logic — add a step-runner seam, do not fork the
sequence. Note that `UpgradeService::upgrade()`'s ordering (backup → verify → maintenance →
migrate → fixups → caches → marker → exit maintenance) must survive that refactor unchanged.
As a stopgap only, consider `set_time_limit` plus a Screen 2 warning when the database exceeds
a size threshold.

## 3. `.env.example` still points session/cache/queue at `database`

**Symptom.** `.env.example` ships `SESSION_DRIVER=database`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`, but a freshly installed app has **no** `sessions`, `cache` or
`jobs` tables: `install()` deliberately skips the framework `0001_01_01_*` migrations because
they collide with the `baseline_`-prefixed legacy schema.

**Why the shipped artifact currently survives.** The release zip ships its own placeholder
`.env` (`SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`), and `install()`
rewrites `SESSION_DRIVER` and `CACHE_STORE` to `file`. The wizard path is therefore safe.

**Constraint.** `.env.example` is also the developer path (`composer setup` copies it) and the
documented reference for the keys a real deployment needs. It must stay a *complete* list of
project-defined keys — an automated check fails if a project `env()` key is missing from it.

**Suggested fix.** Point `SESSION_DRIVER` and `CACHE_STORE` at `file` and `QUEUE_CONNECTION`
at `sync` in `.env.example`, with a comment saying why (the `0001_*` tables are skipped after a
legacy install). Separately, have `install()` write `QUEUE_CONNECTION=sync` explicitly instead
of relying on the zip's placeholder surviving — today it is the only one of the three drivers
not written by the service.

## 4. `install()` checks "already installed" against the wrong database

**Symptom.** `InstallationService::install()` calls `isAlreadyInstalled()` **before** applying
the `InstallInput` credentials, so that probe reads whatever the ambient configuration points
at. On a shell with `DB_*` exported (or a stale `.env`), it can find an unrelated, already
installed database and throw `AlreadyInstalledException` against a perfectly valid input. The
Part 3 worker hit exactly this and had to run with `env -u DB_*`.

**Constraint.** The double-install guard must stay — never overwrite a working install
silently. Do not weaken it to "assume not installed when in doubt".

**Suggested fix.** Apply the input's credentials to config first, then probe the schema and the
`bcoem_sys` marker on **that** connection, and only then proceed. The guard becomes a check of
the database the caller described, which is what it was always meant to mean. Keep the existing
`catch (\Throwable)` tolerance so a missing database reads as "not installed".

## 5. The admin password waits in the session between screens 4 and 6

**Symptom.** The wizard carries the admin password from the site-details screen to the run in
the session, so it is written to the session store (a file after install, or the database).
It is encrypted only when `SESSION_ENCRYPT` is on, which is not the default.

**Constraint.** The multi-screen flow needs those values across requests, and CSRF needs a
session. The password must never be persisted anywhere except the hashed value in the database,
and must never be logged or redisplayed.

**Suggested fix.** Encrypt it explicitly (`Crypt::encryptString`) on the way into the session
and `forget()` it immediately after `install()` returns, on both the success and failure paths.
Alternatively hold the whole `InstallInput` in a short-lived cache entry keyed by the progress
token rather than in the session. Whichever is chosen, keep it the same mechanism for both
wizards.

## 6. `install.sh` puts secrets on the command line

**Symptom.** `scripts/install.sh` passes `--db-password=…` and `--admin-password=…` to
`php artisan app:install`, so both are visible in the process list for the duration of the call.

**Constraint.** The script is a thin wrapper over the CLI and must not grow its own install
logic. Its non-interactive flag interface is what Part 3 exists to drive, so the flags
themselves stay.

**Suggested fix.** Add an environment/stdin input path to `InstallCommand` (for example
`BCOEM_INSTALL_DB_PASSWORD` and `--db-password-stdin`, and the same for the admin password),
then have `install.sh` supply secrets that way and keep flags for the non-secret values. This
touches the command's input collection only — no service change. Update the flag table in the
README's install section at the same time.

## 7. `EnsureInstalled` runs on every web request, uncached

**Symptom.** The middleware calls `isAlreadyInstalled()` on every request in the `web` group,
and `needsUpgrade()` too when installed — one or two indexed single-row reads per request, on
every page of the site. Since issue #42 it also reads the cached remote version (a single cache
read, never an HTTP call) so the update wizard is reachable while a newer release is only
published.

**Constraint.** It has to run before routing to force the install redirect everywhere, and the
answer legitimately changes *during* an install or upgrade, so a naive long-lived cache would
strand the site in the wrong mode. Note also that because it is in the `web` group, every
existing feature test now depends on the install marker being present in its database — Part 2
had to seed it into `ArchiveFlowsTest`'s clone for this reason.

**Suggested fix.** Measure before optimising; a primary-key lookup is cheap. If it does show up,
resolve the answer once per request (a container singleton the middleware and other consumers
share) rather than caching across requests, and flush any cross-request cache at the end of a
successful `install()`/`upgrade()`. On the test-hygiene side, consider a base test case that
seeds the marker so new feature tests cannot silently depend on an installed database.

## 8. `build/output/` is not gitignored — trivial

A local `bash build/release.sh <version>` leaves an untracked ~19 MB zip and build tree in
`git status`. `release.sh` already deletes stale output at the top of each run. Add
`/build/output/` to `.gitignore`.

## 9. Dirty-tree zips leak local artifacts — trivial

`rsync` does not honour `.gitignore`, so building from a dirty working tree copies `.scratch/`
(which contains issue text) and `.slop-scan.cache.json` into the zip. A clean checkout contains
neither, so the acceptance criterion ("no `.git`, `node_modules` or `tests`") still holds for
the documented case. Either add `--exclude=.scratch` and `--exclude=.slop-scan.cache.json`, or
stage from tracked files only (`git archive`), which removes the whole class of leak.

## 10. `UpgradeFixups` is a registry with nothing in it

The interface and the version-keyed registry exist as specified, but no concrete fixup is
registered yet, so the mechanism has never actually run. The obvious first entry is the legacy
3.1.0 timestamp backfill. Register it with
`UpgradeFixups::register('3.0', '3.1', <Class>::class)` **only** when a real version jump needs
it — do not invent fixups — and add a test proving a fixup runs for its exact jump and not for
others.

## 11. The real download path is unexercised, and CI has no shellcheck

`scripts/install.sh`'s download-and-extract-from-GitHub-Releases path could not be run end to
end because the repository has no published release (`releases/latest` returns 404). The
file-swap, install and upgrade logic were proved with locally built and stubbed zips via
`--zip-file`, and the network branch was shown to fail with a clear message. `shellcheck` is not
installed on this machine, so only `bash -n` and review covered the two shell scripts.

Both unblock after the first tagged release: run `bash build/release.sh 4.0.0`, tag `v4.0.0`,
let `release.yml` publish, then run `scripts/install.sh` against a clean sandbox **without**
`--zip-file`. Add `shellcheck` for `scripts/install.sh` and `build/release.sh` to `ci.yml` at
the same time.

**Update (issue #42).** The in-browser download-and-swap path now exists: `ReleaseUpdater`
fetches the `…-webroot.zip` release, verifies the `VERSION` inside it, and swaps the `app-data`
tree. It is covered by `tests/Unit/Installation/ReleaseUpdaterTest.php` (layout detection,
swap, and rollback on a half-completed swap, against a temporary fixture) and
`tests/Feature/Installation/UpdateWizardTest.php` (reachability plus a full `mode=auto` run
against a real zip and a faked download). The live GitHub *network* branch is still only
proven through that stub — exercise it against a published release when one exists.

---

## Do not chase — pre-existing, not from this work

- **8 PHPStan level-8 errors**, all in `tests/Feature/SitePreferencesParityTest.php`
  (`offsetAccess.notFound`). The file is untouched by this work and last changed in `3a8d5a6`.
  Because CI's PHPStan step has a permanently empty baseline policy, `main` is red on this step
  independent of the deployment system. Worth its own `fix(test):` commit — guard the array
  offsets properly, never add a baseline entry.
- **PHPUnit's 59 skips** are the same environment-gated set as before this work.

## Verification recipes

The local shell exports `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_DATABASE=bcoem_test_fix`
and `CACHE_STORE=file`, and those values **override** `.env` and `<env>` entries in
`phpunit.xml`. Always scrub them:

```bash
env -u APP_ENV -u CACHE_STORE -u DB_CONNECTION -u DB_DATABASE vendor/bin/phpunit
```

Bare-upload acceptance (this is the check that gates any change to the middleware group,
`build/release.sh`, or `InstallationService`'s `.env` write — run it, do not reason about it):

```bash
bash build/release.sh 4.0.0
mkdir -p /tmp/e2e && (cd /tmp/e2e && unzip -q <repo>/build/output/bcoem-4.0.0.zip -d app)
cd /tmp/e2e/app/bcoem-4.0.0
env -i PATH=/usr/bin:/bin HOME="$HOME" php -S 127.0.0.1:8391 -t public public/index.php &
curl -s -o /dev/null -w 'install=%{http_code}\n' http://127.0.0.1:8391/install   # 200
curl -s -o /dev/null -w 'root=%{http_code} -> %{redirect_url}\n' http://127.0.0.1:8391/  # 302 -> /install
```

A `200` on `/install` with no database configured is the contract: the zip ships a
credential-free placeholder `.env`, and `ApplySessionTimeout`, `SetLocale` and
`ApplyMailSettings` each tolerate a not-yet-installed database (they branch **only** inside
`catch`, so real failures on an installed site still surface).

## Conventions worth not rediscovering

- **PHPStan level 8, permanently empty baseline** — no suppressions, ever.
  `vendor/bin/pint --test` for style; both must be clean before committing.
- **Command tests** use `Artisan::call()` + `Artisan::output()`, not `->artisan()` +
  `expectsOutputToContain()` — the latter trips PHPStan level 8. Part 1 also found that under
  `PendingCommand`'s mocked output an install produced no schema in a scratch database; the
  direct-call path exercises the same command. Consistent with the house convention — leave it.
- **`legacy/` is a frozen, read-only behavioural reference.** `legacy/common.lib.php` carries an
  uncommitted local modification that predates this work; leave it alone.
- **Fresh-install detail that surprises everyone:** `install()` imports
  `sql/bcoem_baseline_3.0.X.sql` and then runs every non-`0001_*` migration by explicit path,
  because the app runs on the legacy schema and the framework migrations collide with it. Any
  fix that "just runs migrations" will break a fresh install.
- Never add a bot or AI attribution trailer to commit messages.
