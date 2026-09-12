# Changelog

All notable changes to bcoem-next are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Release notes for a tag are taken from the matching `## [version]` section below.

## [Unreleased]

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

[Unreleased]: https://github.com/bcoem/bcoem-next/compare/v4.0.0...HEAD
[4.0.0]: https://github.com/bcoem/bcoem-next/releases/tag/v4.0.0
