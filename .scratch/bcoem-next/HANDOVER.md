# HANDOVER — bcoem-next Frontend Parity Recovery

**Goal**: Make the Laravel/Tailwind/daisyUI port at `/home/faraaz/dev/bcoe/bcoem-next` a
drop-in replacement for the legacy PHP app at `/home/faraaz/dev/bcoe/brewcompetitiononlineentry`
(BCOEM/SCABS). "Drop-in" = from an end user's perspective everything works and looks the same
as legacy. Priorities: (1) full functionality identical to legacy, (2) same UX/appearance.
User is angry about hallucinated links, failed saves, skeleton pages. Written so a junior agent
can execute without supervision.

## 0. Ground Rules (violations caused the original disaster)

1. **Legacy code is the ONLY contract.** Never invent field names, value domains, URLs, labels,
   messages. For every UI piece open legacy source and copy:
   - Public pages: `brewcompetitiononlineentry/pub/*.pub.php` (Bootstrap 5)
   - Admin pages: `brewcompetitiononlineentry/admin/*.admin.php` (brux theme)
   - Save handlers: `brewcompetitiononlineentry/includes/process/*.inc.php` (read `$_POST` keys)
   - Labels/messages: `brewcompetitiononlineentry/lang/en/en-US.lang.php`
   - Admin dispatch: `brewcompetitiononlineentry/index.legacy.php` (`go == "..."` list)
2. **Per-field value domains.** Legacy booleans are SOMETIMES `Y/N`, SOMETIMES `0/1` — per
   field. Verified truth: `prefsSEF`/`prefsSponsorLogos`/`prefsLanguageToggle`/`prefsTransFee`
   = `Y/N`; `prefsAutoPurge`/`prefsSpecific`/`prefsPaypalIPN` = `0/1`. Verify each field via
   (a) legacy form radio `value=` attrs, (b) `process_prefs.inc.php` writes, (c) a dump row
   (`mysql -e "SELECT * FROM preferences\G"`). Never "modernize" a domain — old rows must
   round-trip.
3. **Port reads/writes the SAME DB** (tenant dump schema). Schemas vary per fork: SCABS drops
   `prefsLanguageToggle`/`prefsLanguageOptions`. Guard writes with a column-existence filter —
   pattern already in `app/Http/Controllers/Admin/SitePreferencesController::update` (~line 84).
4. **After every blade/controller edit**: `php -l <file>` + `php artisan view:clear`. The edit
   tool mangles blade occasionally; for big blocks use a python full-block rewrite and re-read
   the touched region.
5. **`npm run build` after ANY `resources/js/*` or `resources/css/*` change** — stale built
   assets made a previous fix "invisible".
6. Conventional Commits (repo git config has the right author). Commit+push per verified fix.
7. NEVER treat "renders 200" as verification. Submit the form, check the DB row, follow the
   link. User-reported breakage is ground truth even when local repro works (their DB/flow
   differs — e.g. entry registration works on review DB but user saw it fail elsewhere).
8. Hero images rotate per page load — never report hero mismatch as a bug.

## 1. Environments & Credentials

| Thing | Value |
|---|---|
| Port repo | `/home/faraaz/dev/bcoe/bcoem-next` (main, clean at `bb0e749`) |
| Legacy repo | `/home/faraaz/dev/bcoe/brewcompetitiononlineentry` (read-only reference) |
| Real tenant dump | `/home/faraaz/dev/bcoe/corpus/scabs-bcoem-20260815-1333.sql` (SCABS schema; passwords UNKNOWN, bcrypt cost 08) |
| Anonymized dumps | `/home/faraaz/dev/bcoe/corpus/derived/anon-base.sql`, `synth-100/300/500.sql` — ALL accounts use password `bcoem-parity`. Admin: `sam.holloway1@example.invalid` (userLevel 0); entrant: `jordan.oakes2@example.invalid` (userLevel 2). |
| MySQL | root/root @ 127.0.0.1 |
| Review DB | `review_scabs` (real SCABS dump; admin `faraaz@debelder.com`/`review-admin`; entrant `test4@example.com`/`TestPass123!`). Schema LACKS prefsLanguageToggle/Options. |
| Review server | hub process `review-serve`: `php artisan serve --host=127.0.0.1 --port=8000`, `.env` on mysql+review_scabs, SESSION_DRIVER=file. KEEP RUNNING until user finishes. Browser tab `admin-review2` logged in as admin. |
| Debug DB | `parity_dbg` — leftover corpus copy; safe to drop. |

Logins: port POSTs `/login` with `loginUsername`/`loginPassword` (legacy field names!) +
`_token` scraped from the login page. Legacy POSTs
`includes/process.inc.php?section=login&action=login`, same fields, no CSRF.

## 2. Existing Tooling (use, don't rebuild)

### tools/parity/parity.sh — authenticated parity harness
Boots legacy (:8091) + port (:8092 via `router-port.php`) on one throwaway DB, logs in roles
(cookie jars), fetches 50 URL pairs from `urls.txt` (`role|legacy_url|port_url`), diffs
content+markup per page.
```
LEGACY_DIR=/home/faraaz/dev/bcoe/brewcompetitiononlineentry \
DUMP_SQL=/home/faraaz/dev/bcoe/corpus/derived/anon-base.sql \
PARITY_DB_PASS=root PARITY_DB_PREFIX= ./tools/parity/parity.sh
```
- `PARITY_DB_PREFIX=` empty is REQUIRED (unprefixed dumps). Artifacts:
  `tools/parity/reports/run-<ts>/` — `*.legacy.raw`, `*.new.raw`, `*.content-diff`,
  `*.markup-diff`. Current: **5 pass / 45 DIFF / 18 skipped**.
- KNOWN OPEN BUG (§4.1): legacy role sessions don't stick (probe → `legacy ?msg=99`); port OK.
- Empty/partial legacy responses ⇒ check `reports/run-*/legacy-server.log` for mysqli fatals —
  historically the stale-config bug (now fixed), but verify `site/config.php` matches run DB.

### tools/parity/form_audit.py — static save-breaker gate
`python3 tools/parity/form_audit.py` (exit 1 = hard findings). A = controller requires a field
no form submits (save ALWAYS fails); C = form value outside `in:` domain; D = legacy renders
field, port doesn't. Resolves `@foreach` loop keys to avoid false positives. Now: 10 D
findings on site-preferences, all intentional drops (hidden tokens `user_session_token`,
`form1`, `relocate`, `prefsRecordLimit` hidden input, `style_type_entry_limits`,
`prefsLanguageOptions`, `prefsGoogleAccount0/1/2` collapsed to one field,
`contestEntryFeeDiscount` derived). Either add real fields or extend the audit ignore-list
with a why-comment.

### tools/parity/linkmap.php — per-page link diff
`php tools/parity/linkmap.php <legacy.html> <new.html> tools/parity/urls.txt` — MISSING/EXTRA
hrefs via urls.txt translation. NOT yet wired into parity.sh loop (add: one call per page
pair, append to report).

### tools/parity/configure-legacy.py
Regenerates legacy `site/config.php` from sample with env DB + base_url. Fixed `bb0e749`: had
silently stopped writing the file (stale configs → "Unknown database" fatals → misleading diffs).

## 3. Fixed (verified, committed, pushed)

| Commit | Fix |
|---|---|
| `58f142a` | Best brewer/club prefs form: missing `prefsScoringCOA` radios → every save failed. Rebuilt to legacy field set (position selects -1..50, COA radios+modal, points 0..25, tie-break rules 1-6, en-US labels). Laravel 13 has NO `Str::ordinal` — inline `$ordinal` closure used. |
| `04ce41d` | Site-preferences save parity, ALL FOUR tabs: added ~14 missing legacy fields (SEF, AutoPurge, LanguageToggle, SponsorLogos, CAPTCHA, RecordPaging, GoogleAccount, EntryForm 7-option select, Specific, SpecialCharLimit 25-255/5, USCLExLimit, fee-password fields, PaypalIPN); fixed Y/N vs 0/1 domains per field; schema-aware column filter for SCABS variance. Tests updated to legacy contract. 563/563 pass; all tabs save `?msg=2` live. |
| `1deb35c` | Admin Essentials offcanvas rebuilt to legacy 40-item contract (was 22 items, 4 wrong targets). New Printing/Data Mgmt/Prefs groups; Assign Judges/Stewards → `judging/locations?action=assign`; Quick Register → `/register/{judge,steward}?view=quick`. Verified 40/40 labels, 0 wrong, all targets resolve. |
| `bb0e749` | configure-legacy.py writes config.php again. |
| `f59c898` | Harness: urls.txt 6→50 role-tagged pairs; auth crawls; linkmap.php; form_audit.py. |

Also VERIFIED WORKING (do not re-fix): entrant registration canary (login→/brew→submit→
`/list?msg=1`), login with legacy field names, 181 dynamic view hrefs route-resolve (1 dead:
`welcome.blade.php → /dashboard` — Breeze leftover, delete), 24 DB tables/columns in `app/`
all exist in tenant schema.

## 4. Work To Do (ordered; one commit each)

### 4.1 Fix legacy-side auth in parity.sh — BLOCKER for the matrix
Symptom: `WARN: entrant/admin session did not stick (legacy -> ?msg=99, port -> ok)`.
Repro loop:
```
cd /home/faraaz/dev/bcoe/brewcompetitiononlineentry
mysql -h127.0.0.1 -uroot -proot -e "CREATE DATABASE IF NOT EXISTS parity_dbg2"
mysql -h127.0.0.1 -uroot -proot parity_dbg2 < /home/faraaz/dev/bcoe/corpus/derived/anon-base.sql
PARITY_DB_NAME=parity_dbg2 PARITY_DB_PREFIX= LEGACY_BASE_URL=http://127.0.0.1:8099/ \
  python3 /home/faraaz/dev/bcoe/bcoem-next/tools/parity/configure-legacy.py
php -S 127.0.0.1:8099    # from legacy dir
# curl: GET / (save jar) → POST includes/process.inc.php?section=login&action=login
#   loginUsername=sam.holloway1@example.invalid&loginPassword=bcoem-parity
# → follow redirects → GET index.php?section=admin; find why session drops.
```
Suspects: (a) `users.userFailedLogins` lockout from earlier bad-password attempts — legacy
fail2bans; if >0, `UPDATE users SET userFailedLogins=0` after dump load in parity.sh;
(b) session cookie flags (path/secure/httponly) vs curl jar; (c) email casing
(`CredentialNormalizer` lowercases; check legacy comparison). Patch `login_role()` in
parity.sh; re-run harness.

### 4.2 Triage the 45 DIFFs into a matrix (needs 4.1)
Per `reports/run-*/` content-diff classify: **A** broken function (dead link/failed save/wrong
redirect), **B** structural (missing rows/sections/tabs vs legacy), **C** styling (LAST).
Write `.scratch/bcoem-next/parity-matrix.md`: page | class | legacy source file to port from.
Commit.

### 4.3 Legacy URL contract (drop-in requirement)
Old bookmarks/emails use `index.php?section=X&go=Y&action=Z` + POSTs to
`includes/process.inc.php?section=...`. Now: `index.php?section=list` → 302 `/index.php/list`
→ msg=99 (path-info mangling). Build ONE redirect map from `index.legacy.php`'s `go ==` cases
+ pub sections → clean port routes, preserving `go/action/filter/view/id` params. Also map
`process.inc.php` POST targets → port endpoints (see `includes/process.inc.php` switch).
Partial shims exist in `routes/web.php`. Add tests: each legacy URL → correct route + auth
behavior.

### 4.4 Form-contract sweep beyond site-preferences
form_audit.py only sees literal `value=` attrs — audit BY HAND vs process scripts:
`/brew` (BrewController ↔ `process_brewing.inc.php`), `/register/*` (RegisterController,
BrewerForm1/2 ↔ `process_brewer.inc.php`), `/list/edit-account`, admin CRUD (contacts,
sponsors, styles, dates, dropoff ↔ `process_*.inc.php`), judging preferences
(`process_judging_preferences.inc.php`). Check field set, required-ness, domains, msg codes.
Fix form+validation together, one commit per area. Verify by live submit (review server,
browser `admin-review2` pattern: `page.evaluate` set values → submit → assert `?msg=N` + DB row).

### 4.5 Admin dashboard = legacy control panel (BIGGEST chunk)
Legacy `admin/default.admin.php` (3119 lines): ~120 links / 47 go-targets, category-organized,
CRUD pairs (Manage/Add per entity), dozens of `includes/output.inc.php?section=...` report
links. Port `resources/views/admin/dashboard.blade.php` (269 lines) is a stats summary with
13 URLs. Rebuild category-by-category; create route+controller per missing output target
(legacy `includes/output/*.output.php` as reference). One category per commit, harness-gated.

### 4.6 Public/entrant structural parity
Port `/list` is a skeleton vs `pub/brewer_info.pub.php` (858 lines) + `pub/brewer_entries.pub.php`
(713): missing account rows, entry tables, button groups; "Change Entry Registration" window
text has a concat bug ("TrainingCertified" concatenation seen on review). Harness content-diff
lists exactly what's missing. Same for `/brew` (brew_form_0/1/2 variants), `/pay`, register
variants (legacy `view=quick` HIDES fields — port must too), `/rules` `/volunteers`
`/entry-info` `/contact` (legacy = landing-page ANCHORS `#rules`…, port has separate pages —
align to legacy).

### 4.7 Styling pass (LAST)
Use normalized markup diffs (structure, classes, heading hierarchy). Map Bootstrap→daisyUI
per conventions already in admin views (`.row`/`.col-sm-*`, `.btn .btn-primary` already used).
No screenshots; user reviews at batch boundaries on review server.

### 4.8 Housekeeping
- Delete `resources/views/welcome.blade.php` + `/dashboard` dead link.
- Drop `parity_dbg` DB. Keep `review-serve` until user says done; then stop it and revert
  `.env` to sqlite.
- `.scratch/bcoem-next/frontend-parity-plan.md` is superseded by the matrix (4.2).

## 5. Verification Checklist (per fix — NEVER skip)

1. `php -l` touched files; `php artisan view:clear` (blade) / `npm run build` (assets).
2. `vendor/bin/pint --test <files>` clean.
3. `php artisan test` full suite green (563 tests at `bb0e749`).
4. Behavioral check on review server: submit/click the changed flow; assert legacy `?msg=N`
   and the DB row (mysql CLI). Menus: fetch every href, assert no 404/auth-bounce
   (`page.evaluate(fetch(...))` pattern in transcript).
5. Re-run relevant gate (`form_audit.py` / `parity.sh`); confirm count moved.
6. Conventional commit + `git push origin main`.

## 6. User's Standing Instructions (do not violate)

- English only. No screenshots for parity (legacy code is the reference).
- Status reports want blunt numbers (X/40 items, N findings), not prose.
- User's word is ground truth — reported breakage is real; find the environment difference,
  don't argue.
- Never "clean up" legacy quirks (Pro-edition MHP suppression, far-future sentinel dates like
  2145916800, msg-code strings) — copy them verbatim.
