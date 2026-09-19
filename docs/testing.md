# Running the test suite

The suite is ~1300 tests. A 2026-09 audit found it **overwhelmingly warranted**
(no dead tests) but dominated by two costs: per-test application boot, and a
small set of tests that do real I/O — throwaway-database installs, `mysqldump`
backup/restore, an actual update download/swap/migrate, and dompdf rendering.

Rather than delete coverage, the slow tests carry a `slow` PHPUnit group.

## Commands

| Command | What it runs |
|---|---|
| `composer test` | Everything **except** `@group slow` — the fast local default |
| `composer test:all` | The whole suite, slow tests included |
| `vendor/bin/phpunit` | Same as `test:all` (this is what CI runs) |
| `php artisan test --group=slow` | Only the slow tests |

`php artisan test` forwards `--group` / `--exclude-group` straight to PHPUnit.

## What is in `slow`

A test is tagged `slow` when it is **individually** expensive (~1s or more) —
real I/O rather than app overhead. Whole classes are tagged only where the
*harness* is the cost:

- `tests/Feature/Installation/` — the installer/upgrader suite (throwaway MySQL
  database, baseline import, and real migrations per test). This is the single
  biggest cluster and is deliberately isolated: it is the only suite that can
  touch a database, a `.env`, and a backup directory.
- `tests/Feature/ArchiveFlowsTest.php` — per-test database clone (CREATE
  DATABASE + ~100 `CREATE TABLE LIKE`).
- The handful of tests that render many PDFs, walk ~75 admin URLs, or hash
  passwords in fixtures.

Everything in `slow` **still runs in CI**; the group only keeps the everyday
local loop short. If you are changing installer, archive, PDF, or admin-dashboard
code, run `composer test:all` (or the specific files) before pushing.

## Database

Feature tests are MySQL-backed and select their schema through `BCOEM_TEST_DB_*`
(`BCOEM_TEST_DB_NAME`, `BCOEM_TEST_DB_HOST`, `BCOEM_TEST_DB_PORT`,
`BCOEM_TEST_DB_USER`, `BCOEM_TEST_DB_PASS`). CI loads `sql/bcoem_baseline_3.0.X.sql`
into `bcoem_test` and sets these; locally, point them at a test database you have
loaded the same way. If they are unset the suite falls back to `bcoem_test`, which
may not be the database your `.env` names.
