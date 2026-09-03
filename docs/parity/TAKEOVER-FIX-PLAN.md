# Fix Plan — Comprehensive Review to Functioning Drop-In Replacement

Date: 2026-09-03. Basis: full legacy-vs-port parity harness (173 page pairs,
`tools/parity/reports/run-20260903-105405`), live DOM probes via headless
browser, and source-level tracing against legacy BCOE&M 3.1.0.

The four user-reported symptoms below were each traced to a root cause. Each
root cause is a **class of defect** that repeats across the codebase — fixing
only the reported instance is explicitly out of scope for each item.

---

## Defect Class A — Unlayered CSS overrides layered Bootstrap 5

**Root cause.** `resources/css/app.css` imports Bootstrap into
`@layer bs5` but then declares *unlayered* identity rules (`a { color:
var(--blue) }`, button/theme rebinds). Per CSS cascade rules, ANY unlayered
rule beats ANY layered rule regardless of specificity. Consequences observed:

- `<a class="btn btn-primary">` rendered **blue text on blue background**
  (user: "two buttons in blue with barely readable white text"). Verified
  live: computed `color: rgb(0,123,255)` on `bg rgb(0,123,255)`.
- Any future unlayered rule will silently defeat Bootstrap component
  styling — this is the mechanism behind "warped text" and other cosmetic
  breakage reported earlier.

**Already fixed (verified):** `a {…}` scoped to `a:not(.btn)…` in
`resources/css/app.css:219-231`; rebuilt; live probe now returns
`color: rgb(255,255,255)` on all account-page anchor buttons.

### Instructions A1 — complete the cascade hygiene
1. Sweep `resources/css/app.css` for every unlayered rule that targets
   elements/classes Bootstrap also styles (`a`, `.btn`, `.badge`, `.form-control`,
   `.nav-link`, `.dropdown-item`, `.modal`, `table`, `.card`…).
2. For each: either delete the rule (if Bootstrap already provides the
   correct visual) or re-scope it (like `a:not(.btn)`) or move it into a
   named layer declared BEFORE the bs5 import (`@layer base, bs5;` then
   `@layer base { … }`) so bs5 wins.
3. Theme-variable blocks (`:root`, `[data-bs-theme="bcoem-brux"]`) set
   custom properties only — they may stay unlayered.
4. Acceptance: computed style of every button/badge/nav link on these pages
   equals its intended BS5 value (white text on primary/success/dark;
   theme palette backgrounds), verified by a Dusk probe similar to
   `tests/Browser/RegressionProbeTest.php`.
5. Run `npm run build` after every CSS change (server serves `public/build/`).

## Defect Class B — Port "improves" legacy behavior instead of matching it

**Root cause (Custom announcement on home).** Legacy custom modules are
**PHP files included from `mods/`** (`paths.php: MODS`), gated by
`mod_display()` in `includes/db/mods.db.php`. If the DB row's
`mod_filename` does not exist, legacy renders **nothing** on public pages
(and an admin-only "file does not exist" alert). The port renders
`mod_description` (raw DB text) instead — a behavior legacy does not have —
so the row `test_mod.php` with description `<div>Custom announcement</div>`
leaks onto the public home page.

**Already fixed:** no. See instructions.

### Instructions B1 — make mods parity-faithful
1. In `resources/views/components/public-layout.blade.php` (the `$modsTop`
   build, lines ~32-63): render the *file contents* of
   `mods/<mod_filename>` (path `base_path('mods/'.…)`), NOT
   `mod_description`. Include semantics: `file_exists()` check first.
2. When the file is missing: render nothing on public pages; keep (or add)
   the admin-side missing-file alert. Never fall back to `mod_description`
   on public pages.
3. Preserve legacy gating exactly:
   - session `mods_display` priming happens for anon + logged-in
     (`mods.db.php:27`), but `$user_level_mods` is only set when logged in —
     so **anon users see no mods** (mod_permission >= null is never true).
     Port currently defaults anon to level 2 — change to match legacy
     (skip mod rendering for guests).
   - `mod_type` filter: only informational static-HTML mods render through
     mods_top/mods_bottom (legacy `mod_type` semantics: 0=Static HTML,
     1=Report, 2=Export, 3=PHP code).
   - Section map: default/rules/volunteers/sponsors/contact/pay → 1,
     register → 6, list → 8; `mod_extend_function` must equal the section
     or 0; `mod_display_rank` 1=top / 2=bottom; admin pages use
     `mod_extend_function_admin` matched against `$go`.
4. Acceptance: with the seeded `mods` row (`test_mod.php`, missing file),
   public home shows no announcement; with `mods/top_example.php` seeded as
   filename, home renders the example jumbotron HTML; anon sees nothing.

**Root cause (Change Email routed to edit-account).** Legacy builds the
email-change link as `user/account/username/<id>` (dedicated page). The port
my-account button stack linked **Change Email → `/list/edit-account`**.

**Already fixed (verified):**
`resources/views/public/partials/account-main.blade.php:33` now points to
`/user/username` (route `user.username` → `ChangeEmailController@show`).
Live probe confirms the rendered href.

### Instructions B2 — sweep for wrong-target links
1. Diff every link on the port's public pages against the legacy
   `build_public_url(...)` target with the same label. Priority pages:
   `/list` (my-account stack), topbar dropdown
   (`public-layout.blade.php:179` already correct), footer.
2. The harness linkmaps (`tools/parity/reports/run-*/​*.linkmap`) list
   legacy-vs-new href pairs per page — review every pair where the port
   href differs and the label is identical.
3. Acceptance: zero linkmap entries where label matches but target diverges
   (excluding documented internal renames listed in ROUTE-PARITY.md).

## Defect Class C — Malformed blade markup (attrs outside the tag)

**Root cause (pencil/trash icons "don't work").**
`resources/views/public/partials/entries-table.blade.php:51-52` broke the
`<form>` tag: `onsubmit="…"` sat on its own line AFTER the closing `>`,
so it rendered as stray text inside the form; the confirm dialog never ran
and the markup inside `<td>` was invalid. (Icons are additionally
gate-locked to muted spans when the entry window is closed — that part is
correct legacy behavior: entry window opens 2026-10-03 in the dev DB.)

**Already fixed (verified):** form tag repaired; rendered row now
well-formed with `onsubmit="return confirm('Delete this entry? …');"`.

### Instructions C1 — mechanical sweep for the same defect
1. Run this scan and repair every hit by joining the attribute into the
   tag (multi-line tags are fine — the defect is only when the tag's `>`
   closes BEFORE the attribute):
   ```
   python3 - <<'PY'
   import glob, re
   for f in glob.glob('resources/views/**/*.blade.php', recursive=True):
       lines = open(f).read().split('\n')
       for i, l in enumerate(lines):
           if re.search(r'<form\b[^>]*>\s*$', l.rstrip()) and i+1 < len(lines):
               nxt = lines[i+1].strip()
               if re.match(r'(onsubmit|class|style|id|data-[a-z-]+)\s*=', nxt):
                   print(f, i+1, nxt[:60])
   PY
   ```
2. Extend the scan to ALL tags (`<a`, `<button`, `<input`, `<select>`) —
   the same junior-agent error pattern can hide anywhere.
3. Add a Dusk test asserting the delete-confirm flow on `/list` with an
   entry in an OPEN window (seed `brewing` row with
   `contestEntryOpen < now < contestEntryDeadline`), asserting
   `switch frame / accept dialog` posts to `/entries/{id}` and the row
   disappears.
4. Acceptance: scan output is empty; Dusk delete test passes.

## Defect Class D — Missing feature wiring (date/time pickers)

**Root cause.** Legacy attached the jQuery datetimepicker to
`#judgingDate` / `#judgingDateEnd` on THREE admin pages:
`all_dates.admin.php`, `judging_locations.admin.php`,
`non-judging_locations.admin.php`. The port only wires flatpickr to
`.date-time-picker-system`, a class present ONLY in
`admin/all-dates.blade.php` (18 inputs). The judging/non-judging session
forms (`resources/views/judging/config/location-form.blade.php:59,67`)
have NO picker class → no widget, free-text `YYYY-MM-DD hh:mm AM`
placeholder only. The flatpickr asset itself IS loaded on those pages
(verified), the class hookup is what's missing.

**Already fixed:** no.

### Instructions D1 — complete picker wiring
1. Add `date-time-picker-system` to the two datetime inputs in
   `resources/views/judging/config/location-form.blade.php` (lines 59, 67)
   and to any other admin datetime inputs found via
   `grep -rn 'YYYY-MM-DD hh:mm' resources/views` (also check
   `non-judging` form variant and `competition-info` custom fields).
2. Keep the value contract: prefilled values use
   `DateFmt::dateTime(..., 999, 'system')` → `Y-m-d H:i:s`;
   `app.js` flatpickr `dateFormat` ('Y-m-d H:i' 24h / 'Y-m-d h:i K' 12h)
   and `allowInput: true` must stay, and
   `LocationController::toUtcEpoch` (PHP `DateTimeImmutable`) already
   parses both. Normalize the prefilled pre-render to match the picker
   format exactly (trim `:ss` via the existing DateFmt call style) OR teach
   flatpickr `dateFormat` with seconds — pick one, document in a comment.
3. Add `data-time-24hr="1"` on the form when `prefsTimeFormat === '1'`
   (mirror `admin/all-dates.blade.php`).
4. Acceptance: Dusk test opens `/admin/judging/locations/create`, clicks a
   datetime input, asserts the `.flatpickr-calendar` appears; saving a
   session persists the correct UTC epoch (compare against
   `strtotime` of the same wall time in tenant TZ).

## Defect Class E — Raw translation keys rendering as text

**Root cause.** `__('site.contact')` renders literally on ~13 pages
(harness: `- Contact | + site.contact` on 13 page pairs; visible in nav on
EVERY public page). `lang/en/site.php` has `site.contacts_disabled`,
`site.contact_intro` etc. but NO `contact` key; same class of defect
produces the `ReqSpec` literal (36 occurrences from the brew style-reveal
labels).

**Already fixed:** no.

### Instructions E1 — repair missing keys + add a guard
1. `grep -rhoE "site\.[a-z_0-9]+" resources/views | sort -u` → diff against
   the keys defined in `lang/en/site.php`. Add every missing key with the
   legacy English text from `~/dev/bcoe/brewcompetitiononlineentry/lang/en/`…`.lang.php`.
   Known-missing: `site.contact` (= "Contact").
2. Do the same audit for every other prefix in use (`brewery.`, `input.`,
   `judging.`, …) — the harness token-diff list is the test oracle.
3. Trace `ReqSpec` in `resources/views/brew/_fields.blade.php` (style-flag
   map / `@unless` blocks): it is a literal where legacy had a lang string;
   restore the legacy label.
4. Acceptance: rerun the parity harness — the `- Contact | + site.contact`
   (13) and `+ ReqSpec` (36) clusters disappear from the token-diff.

## Defect Class F — Whole-site parity gate (the process failure)

The four symptoms above were caught by the USER, not by the gates. The
documented 143 content-diffs in the harness baseline hid real defects
inside "known noise" clusters. Every page must be treated as suspect until
the diff clusters are each classified REAL vs NOISE with evidence.

### Instructions F1 — per-cluster parity burn-down
1. Re-run the harness exactly as documented in the repo (needs
   `PARITY_DB_PREFIX=` empty for the unprefixed corpus dump):
   `LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql PARITY_DB_PASS=root PARITY_DB_PREFIX= ./tools/parity/parity.sh`
2. For EVERY token-diff cluster (see `tools/parity/reports/<run>/`):
   - classify REAL (port must change) or NOISE (document in
     `docs/parity/P4-DIFFS.md` with the reason);
   - REAL clusters get a fix task; NOISE clusters get a harness
     normalizer entry ONLY if they are pure text-extraction artifacts
     (e.g. BS close-icon `×`), never to hide real diffs.
3. Current clusters to burn down after E1/D1 fixes (from
   run-20260903-105405): `- Contact | + site.contact` (13, REAL),
   `+ ReqSpec` (36, REAL), `1B → 1B:` label-colon noise (8×~40, classify),
   awards-deck wording (13, classify), `- limit - use keywords… | + limit.`
   (18, classify), admin-topbar label deltas (9, classify),
   `+ Table 1: Main table…` (13, classify), `+ *` required-asterisks (11).
4. Acceptance for "functioning drop-in replacement": zero REAL clusters
   remaining; NOISE clusters all documented; full Dusk gate set green:
   `AdminTopbarBs5Test AdminOffcanvasBs5Test AdminPageFrameBs5Test
   AdminSessionModalsBs5Test AdminBatchABs5Test AdminBatchBBs5Test
   AdminBatchCBs5Test PublicBs5GateTest Bs5MarkerHarnessTest`,
   `tests/Unit/Bs5MarkersTest.php`, `Issue12ShimGoneProbeTest.php`,
   `RegressionProbeTest.php` (known pre-existing flakes excluded:
   `AdminPagesControlsTest::test_payments_records_table_renders_and_deletes`,
   `BackofficeControlsTest::{test_participants_page_renders_legacy_control_set,
   test_judges_filter_renders_table_and_entry_columns}` — DB-seeding env
   issue, proven with workstash).

## Verification contract (applies to every task above)

- CSS/JS edits: `npm run build`, then probe the real served page
  (computed styles via `tab.evaluate(getComputedStyle(...))`).
- Blade edits: fetch the real page and assert the rendered HTML
  (`curl` + grep, or Dusk).
- Every fix: name the harness cluster it removes and show the cluster
  count drop on the next harness run.

## Already-fixed in this review (committed state, rebuild verified)

1. `resources/css/app.css` — `a:not(.btn)` scoping (Defect A). Verified:
   account buttons render white-on-blue / white-on-dark.
2. `resources/views/public/partials/account-main.blade.php:33` — Change
   Email → `/user/username` (Defect B). Verified: rendered href.
3. `resources/views/public/partials/entries-table.blade.php:51-52` —
   delete form tag repaired (Defect C). Verified: rendered form + confirm.
4. (Previously committed, issue 12/13 work) topbar `navbar-expand`,
   `&ndash;` `{!! $label !!}` fix, BS3-replica CSS removal. NOT yet
   committed: items 1-3 above + `tests/Browser/RegressionProbeTest.php`.

## Open items this review did NOT finish (hand to the coding agent with the tasks above)

- Commit the working tree (items above) referencing this plan.
- B1 mods file-include behavior (currently renders description — wrong).
- D1 picker wiring on judging/non-judging session forms.
- E1 missing `site.contact` key + full key audit + `ReqSpec` label.
- F1 harness cluster burn-down + P4-DIFFS.md classification pass.
- Issue 13 remaining: screenshot pass + user visual sign-off, then close.
