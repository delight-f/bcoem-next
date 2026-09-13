# Changelog

All notable changes to bcoem-next are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Release notes for a tag are taken from the matching `## [version]` section below.

## [Unreleased]

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
