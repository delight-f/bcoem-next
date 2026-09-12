# Handoff — remaining open issues

State as of 2026-09-12, after issues #14–#21, #26 and #22 shipped. Written for a
**fresh agent session** picking up the remaining queue. Everything described here
is committed and pushed to `main` of `delight-f/bcoem-next`.

## Tracker state (GitHub `delight-f/bcoem-next`)

| Issue | Title | State |
|---|---|---|
| #14–#21, #26 | logout, signup anti-bot, FYI banner, website preferences, data management, PDF empty state, competition-info collapse, clubs wiring, small fixes | **CLOSED** — `4b5bd4c` |
| #22 | ASPIRATION: UPDATE CLUB LOGIC | **CLOSED** — `9311756`, docs `c800578` |
| #23 | Fix general preferences in admin dashboard | OPEN — **next** |
| #24 | Harden up payment interface | OPEN |
| #25 | BJCP XML org report implement | OPEN |
| #27 | Implement robust packaging workflow | OPEN |
| #28 | Chore: remove accidental literal `+` prefixes in `RegisterFlowTest::setUp` | OPEN — trivial |

## Running the suite locally — read this first

The developer shell exports `APP_ENV=local` and `CACHE_STORE=file`. PHPUnit's
`<env>` entries do not overwrite already-set variables, so a plain
`vendor/bin/phpunit` runs with the wrong environment: `APP_ENV=local` leaves CSRF
enabled so **every POST login returns 419**, and `CACHE_STORE=file` lets the signup
rate limiter persist across tests (**429**). Unset both to match CI:

```bash
env -u APP_ENV -u CACHE_STORE vendor/bin/phpunit
```

## Known-good baseline failures — do not chase

These fail on a clean checkout of `main` and are unrelated to feature work:

- `AdminDashboardLinksTest::test_every_active_dashboard_link_renders_with_label` —
  `All By Table` not found.
- `AdminDashboardLinksTest::test_every_active_dashboard_route_responds` —
  `/admin/output/pullsheets` returns 403.
- `JudgingConfigTest::test_judging_session_round_trip_stores_epochs_and_blank_to_null`
  — timezone offset.

Confirm against a clean tree (`git stash` or a scratch worktree) instead of
assuming a regression.

## Local database

MySQL `127.0.0.1:3307`, database `bcoem_test_fix`, table prefix `baseline_`.
Apply port-added migrations one at a time — the framework defaults collide with
the `baseline_` tables:

```bash
for f in $(ls database/migrations/*.php | grep -v '0001_'); do
  php artisan migrate --force --path="$f"
done
```

## Conventions worth not rediscovering

- **Admin screens**: routes in `routes/admin.php`, inside the `['web','auth']`
  group, with the admin gate re-checked **in-controller**
  (`if (! ($request->user()?->isAdmin() ?? false)) return redirect('/?msg=99');`).
  Their tests extend `AdminScreensTestCase`, which logs in a `userLevel=0` admin
  and snapshots/restores `preferences`, `contest_info` and `judging_preferences`.
- **Command tests** use `Artisan::call()` + `Artisan::output()` rather than
  `->expectsOutputToContain()`; the latter also trips PHPStan level 8.
- **PHPStan level 8, permanently empty baseline** — no suppressions, ever.
  `vendor/bin/pint --test` for style.
- **Most baseline tables (24 of 25) are MyISAM** — no hard foreign keys on them
  (this is why `brewer.brewerClubId` from #22 is an indexed column, not an FK).
- `legacy/` is a frozen, read-only behavioural reference; behaviour questions are
  answered by reading it, not by guessing.

## Credentials created for #22 (already in place)

- Public repo **`delight-f/clubs-list`** — holds the published `dist/clubs.json`
  (version `10dfedcead4b`, 2329 clubs). Installs fetch it unauthenticated.
- **`CLUBS_LIST_DEPLOY_KEY`** Actions secret on `delight-f/bcoem-next` — an
  ed25519 private key whose public half is a **write deploy key** on
  `clubs-list` only. Used instead of a PAT because GitHub has no API for
  fine-grained PATs. Regenerating it means creating a new keypair, replacing the
  deploy key, and re-setting the secret.
- Workflow **`.github/workflows/sync-clubs-list.yml`** — daily + `workflow_dispatch`.

## Working tree note

`legacy/common.lib.php` has an **uncommitted modification that pre-dates this
work** and is not part of any shipped commit. Investigate before touching it;
leave it out of unrelated commits (as `4b5bd4c` and `9311756` did). An untracked
`.slop-scan.cache.json` is likewise tool output, not source.
