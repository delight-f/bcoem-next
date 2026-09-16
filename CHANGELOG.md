# Changelog

All notable changes to bcoem-next are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Release notes for a tag are taken from the matching `## [version]` section below.

## [Unreleased]

## [4.1.0-alpha.10] - 2026-09-16

### Fixed

- **Every control on the site-preferences screens now does what it says.** An
  audit found 15 controls that saved a value nothing ever read. The entry
  limits (per style/table grid, per style type, and the #1-#4 incremental
  tiers) are now enforced on add *and* edit; the member-discount password
  actually grants the member rate; Pay to Print gates entrant label printing;
  Accept Cash / Accept Checks gate the manual mark-as-paid methods; Checks
  Payable To prints on the check confirmation; Checkout Fees Paid by Entrant
  adds a real surcharge to the total; Contact Form CC copies the sender;
  "Records Displayed" drives the table page size (it previously wrote a column
  nothing read); and hiding the Brewer's Specifics field no longer risks
  clearing a stored value. The Auto Purge switch became a manual "purge stale
  entries" action (the legacy cron path does not exist here), and controls that
  could never have an effect were removed: the Search Engine Friendly URLs
  toggle (Laravel always serves clean URLs) and the test-email Yes/No radios
  (the button beside them runs the test).

### Added

- **Entrants can print their own entry bottle/can labels** at `/list/labels`,
  scoped to their own entries, with the legacy Pay-to-Print payment gate
  restored.

## [4.1.0-alpha.9] - 2026-09-15

### Added

- **An end-to-end update wizard (issue #42).** A Top-Level Administrator can
  now update the site from the browser: the site downloads the published
  release, unpacks it beside the live tree, carries `.env` and `storage/`
  across, swaps the files and then runs the ordinary backup-and-migrate steps —
  no zip download, upload or SSH required. It is offered from the dashboard
  release notice and the **Update your site** banner, and is only ever started
  by an administrator: a detected release is never applied on its own. The
  automatic swap works on the `app-data` (shared-hosting) layout; other layouts
  keep the manual and CLI paths.

### Changed

- **The "Check for updates" result now reads the same as the release notice.**
  The flash said a new version was "available to download", which stopped being
  true once the update wizard could install a release in the browser. It now
  reports the version "is available", leaving the notice below to carry the
  action (install automatically, or download by hand).

### Fixed

- **The automatic update now works on hosts that run the web server as its own
  user.** There every file belongs to the shell account, so the updater's
  copy-based overlay failed on files it was not allowed to write. Document-root
  files are now replaced by unlinking and renaming, which needs write access
  only to the folder, and `app-data` no longer has to be writable at all (the
  swap only renames it). The checks screen reports the web folder and each
  sub-folder separately and names the one that is blocking, so the remedy is a
  single command; where it cannot be granted, the manual and SSH paths remain.
- **Sponsor-logo and hero-image uploads report a permissions message instead of
  a 500** when the upload folder is not writable by the web server.

## [4.1.0-alpha.8] - 2026-09-14

### Added

- **A manual "Check for updates" on the admin dashboard.** The automatic
  release notice only runs when its 24-hour cache is stale and never blocks a
  page, so an update could go unnoticed for a day — and a failed check was
  invisible by design. The Competition Status panel now shows the installed
  version and, for Top-Level Administrators, a button that checks GitHub on
  demand and reports the outcome, including when the check could not run.

## [4.1.0-alpha.7] - 2026-09-14

### Fixed

- **A wrong username or password returned a 500 instead of the sign-in
  retry page.** The failed-login hook raised an `E_USER_WARNING`, which
  Laravel's error handler turns into an uncaught exception in every
  environment — so the guard meant to keep it out of test runs armed it in
  production instead. The fail2ban line is now logged without raising a
  warning (#36).
- **Switching table mode deleted judging tables.** Both directions
  destroyed persisted configuration: entering Competition Mode ran
  `TRUNCATE` on tables, assignments and flights whenever no flight existed
  (and otherwise pruned each table to its received styles, cascade-deleting
  the rest), while entering Planning Mode pruned each table to the styles
  that already had entries — deleting any table set up in advance for a
  style no one had entered yet. A mode switch now prunes only derived
  flight data; table configuration and assignments survive (#39).
- **Generated judge and steward sign-in sheets came out blank.** An
  assignment whose session could not be resolved was silently dropped from
  the sheet. Each assignment now falls back to its table's session, and any
  still unmatched appear on an "Unassigned" sheet (#38).
- **`admin/judging/tables` was cramped and mis-labelled.** The mode switch
  and "Add a Table" no longer touch, the Assign Roles / View / Print
  dropdowns share one row, the empty state is an alert, and assign judge /
  assign steward use the gavel and clipboard icons (#37).
- **`admin/competition-info` sections were hard to tell apart.** Each
  section now carries a light-blue Bruxellensis accent, expanded fields
  clear the dividers, the QR check-in help text sits beneath its button, and
  the page title matches the other admin headers (#40).
- **The central club picker only searched on a button click.** Typing now
  updates the match list live, clicking a match selects it, and Enter adds
  the typed name when nothing matches; duplicate detection compares whole
  club names (#41).

### Changed

- **Release builds no longer fetch fonts from the network.** The Instrument
  Sans files now resolve from `node_modules` via `fontsource`, so packaging
  is offline-deterministic (#34).
- **`softprops/action-gh-release` moved from v2 to v3,** off GitHub Actions'
  deprecated Node 20 runtime (#34).

## [4.1.0-alpha.6] - 2026-09-13

### Added

- **An audit of the back-office screens**, recorded in
  `docs/BUG-AUDIT-ADMIN-DASHBOARD.md`: 26 verified defects with their triggers,
  impact and fixes.

### Fixed

- **The sign-in page had no way to sign in.** `/login` — where every
  authenticated-only screen sends a signed-out visitor — rendered the heading
  and a "Reset Password" button and nothing else, because the form lives in the
  shell's login modal. The page now offers a **Log In** button and opens the
  form on arrival.
- **Scoresheet uploads and the scoresheet list were open to any signed-in
  account.** That screen never re-checked for an administrator; it now requires
  one, like every other back-office screen.
- **A participant profile failed to save whenever an optional field was left
  blank** (no phone number, no address), returning a server error instead of
  saving.
- **Saving Competition Info silently wiped the QR check-in password.** The
  password is set in its own modal, but every save of the main form cleared it;
  it is now preserved unless the modal itself submits blank.
- **A blank PayPal save erased an environment-configured client secret**,
  silently disabling online payments. It now keeps the env secret.
- **Shipped beer styles could be deleted or overwritten.** The list hides
  Edit/Delete for built-in styles; the endpoints now refuse them too.
- **Admin user-level and password changes were open to any admin.** Both are
  now Top-Level-Administrator only, a top admin cannot demote themselves, and
  the last remaining top admin cannot be demoted.
- **A NULL `userLevel` counted as an administrator.** Legacy/imported rows
  default to NULL; only the documented level values are admins now.
- **The entries screen's Admin Actions and row delete did nothing.** They were
  nested inside the bulk-edit form, which HTML discards, so they submitted the
  wrong route; each also submitted with the wrong verb. The forms are no longer
  nested, and the page gained the missing **Update Entries** button for the
  inline judging-number / paid / notes columns.
- **Custom-style delete never worked on the styles screen** (same nested-form
  problem), and "Update Accepted Styles" sat outside its own form and saved
  nothing.
- **Uploaded sponsor logos could not be deleted** — the delete control was a GET
  link to a POST-only endpoint and sent the wrong field name.
- **The participants list crashed** with a server error once anyone was assigned
  to a judging table.
- **The row icons on that list pointed at the wrong screens:** the key opened
  the *operator's* own password form, and the lock was a dead link.
- **The payment ledger recorded USD for every collection** regardless of the
  competition's currency; it now stores the currency the provider charged.
- **Entry Status fee totals ignored volume discounts, the member rate and the
  fee cap.** They now use the same fee model (`FeeCalculator`) as the rest of the
  application.
- **A judging-close suggestion rendered as 1970** when no judging session
  existed, because the fallback branches were swapped.
- **The sponsors bulk form skipped validation** the add/edit form enforces, so
  an out-of-range sponsor level or over-long image name could be saved from the
  list. It now validates identically.
- **Uploaded SVG sponsor logos never appeared in the logo picker**, although the
  uploader accepts them.
- **Unpaid filters ignored entries with a NULL `brewPaid`** (the legacy column
  default), so those entries were missing from the unpaid list and totals while
  the purge path still treated them as unpaid.
- **Deleting a non-existent contact, module, sponsor or style type reported
  success.** They now report honestly instead of claiming a save that did not
  happen.
- **The participants and competition-info screens had unassociated labels**
  (dangling `for=` on the radio/checkbox groups and the two Markdown textareas),
  so clicking a label did nothing and screen readers mis-announced the fields.

## [4.1.0-alpha.5] - 2026-09-13

### Added

- **Update from the install wizard.** A site attached to an older database can
  now run the update from the screen that follows the adoption, instead of being
  pointed at an upgrade it cannot reach: the upgrade wizard needs a signed-in
  administrator, and the site cannot start until the adoption has written its
  database details. It drives the same steps as the upgrade wizard, so the
  backup, the maintenance window and the progress reporting are identical.

### Fixed

- **The dashboard's "new release published" notice compared the wrong version.**
  It measured the latest published release against the version recorded in the
  database, so a site that had uploaded a new release but not yet run its upgrade
  was told to go and download the release it was already running.
- **The footer reported a version frozen at fork time.** It said `3.1.0` on every
  release and never moved when a site was upgraded.
- **The admin footer sat on top of the last row of a long page.** That row's own
  buttons ended up underneath the footer text, so clicking the footer reached
  them. Admin content now clears the fixed footer.
- **The at-a-glance deck left a stray card.** A four-card deck on the fixed
  3-wide grid wrapped to 3 + 1 and centred the orphan; a count that divides by
  four now fills a row of four, and the card header wraps so a long title cannot
  clip its status pill off the card's right edge.
- **Several admin pages were centred instead of left-aligned.** Twenty-six blades
  wrapped their body in Bootstrap's `.container` — a centred max-width box —
  inside the admin frame's already full-width container. They now use the same
  wrapper as the pages beside them. (`participants-print` keeps its narrow
  column: that width looks deliberate for a print sheet.)
- The upgrade banner said "(you are running 3.1.0.0)" about a site whose files
  were 4.1.0-alpha.4. It is the database that lags, so it now says so.

## [4.1.0-alpha.4] - 2026-09-13

### Added

- **Attach to an existing site.** The install wizard inspects the database once
  the connection test succeeds. A database that already holds a finished Brew
  Competition site is offered for adoption — connection details are saved and
  the data is left untouched — instead of the wizard installing over it. A
  half-finished or unrecognised database is refused with an explanation.
- The wizard warns when the database account supplied can administer the entire
  server, because those details are stored in plain text inside the web folder.

### Fixed

- **Installing over an existing site destroyed its accounts.** `createAdmin()`
  deletes every row in `users` and `brewer`, and the guard that prevented this
  only ran on the final step and only recognised `setup = 1`. Any populated
  database is now refused before anything is written.
- **`bcoem_sys.version` was too narrow for a release version.** The legacy
  column is `varchar(12)`, so upgrading a real 3.1.0.0 tenant to 4.1.0-alpha.3
  failed with "Data too long for column 'version'" on the last step, leaving the
  upgrade applied but unmarked. Widened to 32.
- **A fresh install recorded `4.0.0`** whatever the release was, so a new site
  immediately advertised an upgrade to the version it was already running.

## [4.1.0-alpha.3] - 2026-09-13

### Fixed

- **Install wizard 500 on hosts with a vendor-suffixed PHP version.** The
  system check handed `PHP_VERSION` (for example `8.4.22-nfsn1`) straight to
  `composer/semver`, which rejects it as an invalid version string and aborted
  the wizard's second screen. Found on NearlyFreeSpeech's shared hosting.
- **Error pages now survive an unreachable database.** The in-site 404 view
  loaded its contest chrome eagerly, so a database outage failed the error
  render too and returned a bare 500 where an error page was owed.

## [4.1.0-alpha.2] - 2026-09-13

### Fixed

- **Deployable release artifact for shared hosting.** A release now also ships
  `bcoem-<version>-webroot.zip`, which unpacks with the front controller and
  compiled assets at the web root and the application in `app-data/` beside
  them, so an (S)FTP upload serves the site instead of failing on the nested
  `public/`. The packaged `index.php` sets the public path explicitly; the
  existing flat zip is unchanged.

## [4.1.0-alpha.1] - 2026-09-13

First alpha of the 4.1 line, cut for production trials. It builds on 4.0.0 with
a complete install, upgrade and release system, a self-maintaining central clubs
list, and fixes from the first real deployments.

### Added

- **Install, upgrade and release system** (issue #27). `InstallationService`
  and `UpgradeService` hold all install, backup, migration, fixup and
  precondition logic exactly once, behind three interfaces: the `/install` and
  `/upgrade` web wizards for hosts with only FTP access, `php artisan
  app:install` / `app:upgrade`, and `scripts/install.sh` for VPS operators.
  Upgrades back up before touching the database and never roll it back
  automatically.
- **Resumable web wizards.** Installation and upgrade run one step per poll, so
  a shared host's request timeout no longer abandons a long migration.
- **PayPal alongside Stripe** (issue #24): Orders v2 with hosted approval,
  signature-verified webhooks and refunds. Stripe Connect stays the default.
- **Central clubs list** (issue #22). The maintained homebrew-club list is
  mirrored into the local database. The upgrade wizard primes it on completion
  and the club picker repairs it on demand, so a host with no cron still gets
  it. A failed upstream fetch is a no-op, and clubs that drop off the list are
  never deleted.

### Changed

- The upgrade wizard stays reachable while the site is in maintenance mode.
- Install secrets stay off the command line (`--db-password-stdin`, or
  `BCOEM_INSTALL_*`), and the install probes the database the caller named.
- The release notice points at the project's own repository, and `.env.example`
  ships usable session, cache and queue drivers.
- The release zip carries `vendor/` and compiled assets, so the target host
  needs only PHP and a web server — no Composer, no Node.
- README rewritten around the port's benefits over the legacy application.

### Fixed

- Judging assignments are never wiped when a brewer profile is saved (issue
  1752).
- Cup mats no longer print blank squares for empty groups (issue 32).
- `composer.lock` is back in sync with `composer.json`, so `composer validate
  --strict` passes again.
- Static analysis: guarded the `preg_match` offsets and nullable lookups in
  `SitePreferencesParityTest`; PHPStan level 8 is clean.

## [4.0.0] - 2026-09-12

The first release of the Laravel rewrite. It keeps the legacy application's
database schema, URLs and output documents, and rebuilds the application on
PHP 8.4+, Laravel 13, Bootstrap 5 and Vite with automated tests and static
analysis.

### Added

- Entrant, entry, judging, admin back-office and public-site features ported
  from the legacy BCOE&M application, verified against it.
- Stripe Connect payments with idempotent webhooks, refunds and the tiered fee
  model, alongside PayPal/IPN support for installs that still use it.
- 21 PDF output artifacts (pull sheets, QR-coded labels, table cards,
  scoresheets, results) rendered with dompdf, plus CSV exports and the awards
  presentation with Best Brewer / Best Club standings.
- Schedule-driven central clubs list, mirrored into the local database and
  used by the entrant club picker.
- Signup spam protection: honeypot, Cloudflare Turnstile and optional email
  verification, each independently toggled and off or failing open by default.
- A redirect map so existing legacy `.php` URLs continue to resolve.

### Changed

- Configuration is read from the environment using Laravel's standard `env()`
  pattern; `.env.example` ships every key the project defines.
- PHPStan at level 8 with an empty baseline, Pint code style, and a PHPUnit
  suite (Unit, Integration, Characterization, Feature) gating every change.

### Removed

- PayPal IPN as the default payment transport (Stripe Connect replaces it);
  PayPal remains available for installs that explicitly configure it.

[Unreleased]: https://github.com/delight-f/bcoem-next/compare/v4.1.0-alpha.1...HEAD
[4.1.0-alpha.1]: https://github.com/delight-f/bcoem-next/releases/tag/v4.1.0-alpha.1
[4.0.0]: https://github.com/delight-f/bcoem-next/releases/tag/v4.0.0
