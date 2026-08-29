# PARITY-PLAN-P1

Implementation plan for the P1 backlog batch (PARITY-001 through PARITY-019).
Each slice is one commit. Slices ordered by dependency: link emissions first
(controller-only, no new routes), then un-stubbing flows that need new
routes/controllers, then the two big unported surfaces (awards.php, qr.php)
last.

All evidence from `docs/parity/PAGE-PARITY.md` dashboard audit +
`admin/default.admin.php` + `DashboardController::sections()`.

## Architecture context

Dashboard model: `DashboardController::sections()` (lines 190-690) builds a
left/right array of panels. Each panel = `[title, icon, help, categories]`.
Each category = `[label, items]`. Each item is one of:

- `['label', 'href']` — active link (via `$l()` helper, line 192)
- `['label', null, 'todo']` — dead span (via `$todo()` helper, line 193)
- `['label', 'children' => [...], 'descriptor' => '...']` — dropdown
  (via `$family()` helper, line 194; or `$countFamily()`, line 200)

`resources/views/admin/dashboard.blade.php` renders: `@foreach ($rowLinks as
$item)` → `@if children` → dropdown; `@elseif todo` → `<span class="text-muted">`;
`@else` → `<a href>`. The blade already handles all three shapes. **No blade
changes needed for any P1 slice except awards/presentationLaunch modal.**

Port modal pattern: `<dialog id="X" class="modal">` + `data-open-modal="X"`
trigger (see `dashboard.blade.php:21,228`). Replaces legacy BS3
`data-toggle="modal" data-target="#X"`.

Port purge surface: `ArchiveController` (`/admin/archive` GET/POST with
`confirm=yes` gate) + `PurgeController` (`/admin/purge` GET,
`/admin/purge/{flow}` POST with `confirm=yes` gate). All 12 purge flows +
`purge-all` already implemented. JN regen: **no port route exists yet**.

Port output routes: `routes/outputs.php` registers 21 output controllers
under `/admin/output/{name}` via `__invoke`. Each controller reads query
params (`filter`, `view`, `go`, `psort`, `id`, `location`, `sort`).

---

## Slice 1 — Dashboard link fixes (PARITY-004, 005, 016, 017, 018, 019)

**Target:** `app/Http/Controllers/Admin/DashboardController.php`
**No new routes, no blade changes.**

Fix six classes of wrong/missing dashboard hrefs by replacing flat links
with `$family()` dropdowns or correcting the href:

### 1a. Assignments per-session variants (PARITY-016, 6 missing hrefs)

Current (line 449-457): 6 flat links all pointing to
`/admin/output/assignments?filter=judges` (or stewards), no `view=` or
`location=` params — all 6 render identical output.

Legacy emits (admin/default.admin.php:1605-1634):
- `filter=judges&view=name` (By Last Name)
- `filter=judges&view=table` (By Table)
- `filter=judges&view=location` (By Session — per-location dropdown)
- `filter=judges&view=sign-in` (Sign-in sheet)
- `filter=stewards&view=name` (By Last Name)
- `filter=stewards&view=table` (By Table)
- `filter=stewards&view=location` (By Session)
- `filter=stewards&view=sign-in` (Sign-in sheet)

Plus per-session dropdowns: `filter=judges&location={id}&view={name,table}`
for each `judging_locations` row (lines 1612-1634).

**Change:** Replace the flat `Assignments` category (lines 449-457) with:
- 4 flat links per role (judges + stewards × name/table/sign-in) with
  correct `view=` param appended.
- 2 `$family()` dropdowns per role: "Judges for Session..." and "Stewards
  for Session...", each building `location={id}&view={name,table}` children
  from `judging_locations` query (same pattern as `$bosStyleTypes` family
  at line 487).

**Controller support needed:** `AssignmentsController` currently ignores
`view` and `location` params (line 27 DIVERGENCE comment). Must add:
- `view=name` → sort by last name (current behavior, already default)
- `view=table` → group by table
- `view=location` → filter by `location=` param, group by session
- `view=sign-in` → sign-in sheet layout (different blade)

### 1b. Table-cards sorting-tables variants (PARITY-017, 3 missing hrefs)

Current (line 439-443): 3 links — `table_cards`, `table_cards?id=1`,
`table_cards?go=judging_locations&location=1&round=1`.

Legacy emits: `psort=sorting-tables` + `&view=master-list` variant, and
`go=judging_tables&action=default&id=1` per-table variant.

**Change:** Add to the `Table Cards` category (line 439):
- `$l('/admin/output/table_cards?psort=sorting-tables', 'Sorting Tables')`
- `$l('/admin/output/table_cards?psort=sorting-tables&view=master-list', 'Sorting Tables (Master List)')`
- `$family('For Table...', per-table children)` emitting
  `go=judging_tables&action=default&id={id}`

**Controller support:** `TableCardsController` already handles
`psort=sorting-tables` + `view=master-list` (line 76). No controller change.

### 1c. Output variants (PARITY-018, 5 missing hrefs)

Current: Results card missing BOS download links, staff download, inventory
go=scores.

Legacy emits:
- `export-results&go=judging_scores_bos&action=download&view={pdf,html}` (BOS
  results download — 2 links)
- `export-staff&go=judging_assignments&action=download&view=pdf` (staff
  download — 1 link)
- `staff&go=judging_assignments&action=download&view=default` (staff print
  variant — 1 link)
- `inventory&go=scores` (inventory with-scores — 1 link)

**Change:** Add these 5 links to the appropriate categories in the Results
and/or Data Exports panels. The exact placement depends on which legacy
panel they lived in — trace from `admin/default.admin.php` card headings.

**Controller support:** `ResultsController` and `ExportController` may need
`view=pdf|html` + `action=download` param handling. Audit before implementing.

### 1d. Archive action loss (PARITY-019, 1 missing action)

Current (line 640): `$l('/admin/archive', 'Archive Current Data')` — plain
manage URL, loses `?action=add` flow.

Legacy: `admin|archive|add` → `/admin/archive?action=add` (GET_MAP line 135).

**Change:** Replace line 640:
```
$l('/admin/archive?action=add', 'Archive Current Data')
```

**Controller support:** `ArchiveController::index` must handle `action=add`
query param to show the create flow vs the manage list. Currently `index()`
shows the manage view. Add an `action` check or a dedicated create route.

### 1e. Participants assign-page filter links (PARITY-004, 005)

Current (line 243-245): assign links point to
`/admin/judging/tables?action=assign&filter=judges` etc. — correct port path.

Legacy assign pages also emit `participants?filter=judges|stewards` nav links
for cross-navigation. The port's assign blade may be missing these.

**Change:** Audit `resources/views/admin/` assign blades (if any exist for
the assign flow) and add the nav links. If no assign blade exists yet, this
is a P2 item (assign UI is a separate surface).

---

## Slice 2 — Un-stub dead TODO spans (PARITY-014, 19 dead spans)

**Target:** `app/Http/Controllers/Admin/DashboardController.php` (replace
`$todo()` calls), `resources/views/admin/dashboard.blade.php` (add modals),
new routes for JN regen.

### 2a. JN regen ×3 (3 dead spans → modal + POST route)

Current (line 387 area): `$todo('Import Scores', ...)` is one; the three JN
regen entries are `$todo()` calls in the Entry Sorting card.

Legacy modal triggers (default.admin.php:855-869):
- `#jn-random-modal` → `regenerate_judging_numbers(ajax, 'default', ...)` —
  random JN assignment
- `#jn-style-modal` → `regenerate_judging_numbers(ajax, 'legacy', ...)` —
  style-prefix JN
- `#jn-entry-modal` → `regenerate_judging_numbers(ajax, 'identical', ...)` —
  JN = entry number

Legacy AJAX: `ajax/regenerate.ajax.php` calls
`generate_judging_numbers($prefix.'brewing', $action)` from
`lib/process.lib.php`.

**Change:**
1. New route: `POST /admin/judging/regenerate-numbers` → new
   `JudgingNumberController::regenerate(Request)` accepting `mode` param
   (`default|legacy|identical`).
2. Port `generate_judging_numbers()` logic from `lib/process.lib.php` —
   the `JudgingNumber` class (`app/Support/`?) already has `::random()` (used
   in BrewController:228); add `::withStylePrefix()` and `::sameAsEntry()`.
3. Dashboard: replace 3 `$todo()` calls with `$l('/admin/judging/regenerate-numbers?mode={default|legacy|identical}', '...')` 
   — OR, matching legacy's modal pattern, use `data-open-modal` confirm
   dialogs that POST to the route.
4. Add 3 `<dialog>` confirm modals to dashboard.blade.php (pattern: line 228
   `post-comp` modal) with a confirm button that POSTs to the route.

### 2b. Purge/confirm ×6 (6 dead spans → link to existing purge controller)

Current (lines 625-637): 6 `$todo()` calls — Clean-Up Data, Confirm All
Unconfirmed, Purge All Unconfirmed, Purge All Unpaid, Purge Payments, Purge
Participants, Purge Judging Tables.

Port `PurgeController` already has all flows: `unpaid`, `unconfirmed`,
`entries`, `participants`, `payments`, `tables`, `custom`, `scores`,
`availability`, `evaluation`, `scoresheets`, `purge-all`, `cleanup`,
`confirmed`.

Legacy modal→AJAX mapping:
- `cleanUp` → `purge_data(ajax, 'cleanup', 'cleanup', ...)` → flow `cleanup`
- `confirmAll` → `purge_data(ajax, 'confirmed', 'confirmed', ...)` → flow `confirmed`
- `purgeUnconfirmed` → `purge_data(ajax, '', 'unconfirmed', ...)` → flow `unconfirmed`
- `purgeUnpaid` → `purge_data(ajax, 'purge', 'unpaid', ...)` → flow `unpaid`
- `purgePayments` → `purge_data(ajax, '', 'payments', ...)` → flow `payments`
- `purgeParticipants` → `purge_data(ajax, '', 'participants', ...)` → flow `participants`
- `purgeTables` → `purge_data(ajax, '', 'tables', ...)` → flow `tables`
- `purgeAll` → `purge_data(ajax, '', 'purge-all', ...)` → flow `purge-all`
- `purgeScores` → `purge_data(ajax, '', 'scores', ...)` → flow `scores`
- `purgeScoresheets` → flow `evaluation`
- `purgeUploadedScoresheets` → flow `scoresheets`
- `purgeAvailabilty` → flow `availability`
- `purgeCustom` → flow `custom`

**Change:** Replace each `$todo()` call with a link to
`/admin/purge` (the purge index page) — the existing purge blade already
has the confirm gate. The `dateThreshold` input for entries-purge (legacy
modal `purgeEntries`) must be on the purge page, not the dashboard. The
dashboard links point to `/admin/purge` which lists all flows with confirm
panels.

OR: replace each `$todo()` with a `data-open-modal="purge-{flow}"` trigger +
add `<dialog>` modals directly on the dashboard (matching legacy's inline
modal pattern). This is closer to legacy but adds 12+ modals to the
dashboard blade.

**Recommendation:** Link to `/admin/purge` — the purge page already exists
and has the server-enforced `confirm=yes` gate. Dashboard just needs the
links. 6 `$todo()` → `$l('/admin/purge', '...')` changes.

### 2c. Help modals ×9 (9 dead spans)

Current (lines 672-680): 9 `$todo()` calls for help modals.

Legacy: 9 BS3 modals with help text per dashboard section.

**Change:** Two options:
1. **Static modals:** Add 9 `<dialog>` modals to dashboard.blade.php with the
   help text extracted from legacy `admin/default.admin.php` modal bodies.
   Replace 9 `$todo()` with `data-open-modal="help-..."` triggers (need blade
   support for non-link items with modal triggers — currently `$todo()`
   renders as dead span).
2. **Inline help:** Collapse help into the existing panel-collapse help
   toggle (blade line 41 already has `data-open-modal="help-{side}-{index}"`).

**Recommendation:** Option 1 — extract help text from legacy, render as
`<dialog>` modals. The dashboard blade already has the `data-open-modal`
pattern (line 41). Need to add a 4th item type to the sections model:
`['label', null, 'modal', 'modal-id']` or repurpose `todo` with a modal id.

### 2d. qr.php check-in ×1 (1 dead span)

Current (Entry Sorting card): `$todo('Check-in: Via Mobile Devices', 'qr.php')`.

**Change:** Replace with `$l('/qr', 'Check-in: Via Mobile Devices')` once
Slice 6 (qr.php port) lands. Until then, leave as `$todo()` — it's a
dependency on PARITY-002.

### 2e. Import Scores ×1 (1 dead span)

Current (line 387): `$todo('Import Scores', 'import_scores.eval.php modal')`.

Legacy: `ajax/import_scores.ajax.php` — imports scores from eval system.

**Change:** Defer to P2 — import scores is an eval-integration feature, not
a simple link. Leave as `$todo()` with a note.

---

## Slice 3 — Publish Results action (PARITY-003)

**Target:** `app/Http/Controllers/Admin/DashboardController.php`,
`app/Http/Controllers/LegacyRedirectController.php`, new controller + route,
`resources/views/admin/dashboard.blade.php`.

### 3a. Fix PROCESS_MAP placeholder

Current (LegacyRedirectController.php:180):
```php
'publish' => ['/admin/archive', 302],  // WRONG — lands on archive page
```

Legacy `process.inc.php?action=publish` (lines 348-410): sets
`prefsDisplayWinners='Y'`, `prefsWinnerDelay=time()`, forces all future
deadlines (registration, entry, judge, judging closed, location dates) to
`time()`, clears the prefs session cache, redirects to
`index.php?section=admin&msg=36`.

**Change:** Remove the `'publish'` PROCESS_MAP row entirely — publish is not
a redirect, it's a POST action. The GET redirect for the legacy
`process.inc.php?action=publish` URL should go to a new
`/admin/results/publish` route.

### 3b. New publish route + controller

1. New route: `POST /admin/results/publish` →
   `PublishResultsController::store()`.
2. Controller logic (port from process.inc.php:348-410):
   - `UPDATE preferences SET prefsDisplayWinners='Y',
     prefsWinnerDelay=UNIX_TIMESTAMP() WHERE id=1`
   - For each deadline column in `contest_info`, if value > time(), set to
     time() (registration, entry, judge deadlines).
   - `UPDATE judging_preferences SET jPrefsJudgingClosed=UNIX_TIMESTAMP()`
     if > time().
   - For each `judging_locations` row where `judgingDate > time()`, set to
     time().
   - `UPDATE judging_locations SET judgingDateEnd=UNIX_TIMESTAMP() WHERE
     judgingLocType=1`.
   - Clear session prefs cache.
   - Redirect to `/admin?msg=36` (or the port equivalent message).
3. Admin gate: `userLevel <= 1` (same as all admin controllers).

### 3c. Dashboard button + popover

Current: Publish Results button absent entirely.

Legacy: button with `data-toggle="popover"` explaining the action, then
`process.inc.php?action=publish` link.

**Change:**
1. Add a "Publish Results" button to the dashboard (right column, near the
   Results card or in a "Post-Competition" area). Use the existing
   `postCompTasks` status gate (line 170) to show/hide.
2. Add a `<dialog>` or daisyUI popover explaining: "Publishes results
   publicly. Resets all deadlines to now. Cannot be undone."
3. Button: `POST` form to `/admin/results/publish` with `confirm=yes` gate
   (matching PurgeController pattern) or a `<dialog>` confirm modal.

---

## Slice 4 — qr.php mobile check-in (PARITY-002)

**Target:** New controller + routes + blade view, dashboard link un-stub.

### 4a. Route + controller

Legacy `qr.php` (434 lines): standalone mobile check-in page.
- Password gate: `contest_info.contestCheckInPassword` (bcrypt-verified,
  session-stored `qrPasswordOK`).
- Check-in action: `process_barcode_check_in.inc.php` — scans judging
  number / entry ID, flips `brewReceived='1'`.
- `target="_blank"` from dashboard (standalone window).

**Port already has:** `BarcodeCheckinController` at
`/admin/judging/checkin` (GET + POST) — admin-gated, scans judging number,
flips `brewReceived`. This is the admin check-in surface.

**Missing:** The public/guest-facing `qr.php` — password-gated, no admin
login required, uses `contestCheckInPassword`.

**Change:**
1. New routes (no auth middleware, password gate is the auth):
   - `GET /qr` → `QrCheckinController@show()` — renders password form or
     check-in page depending on session state.
   - `POST /qr` → `QrCheckinController@authenticate()` — verifies password
     against `contest_info.contestCheckInPassword`, sets session flag.
   - `POST /qr/checkin` → `QrCheckinController@store()` — the actual
     barcode scan → `brewReceived=1` flip (reuse the logic from
     `BarcodeCheckinController::store()`).
2. Controller: `app/Http/Controllers/QrCheckinController.php`.
3. View: `resources/views/qr/checkin.blade.php` — mobile-optimized,
   password form + scan input + result display.
4. Dashboard: replace `$todo('Check-in: Via Mobile Devices', 'qr.php')` with
   `$l('/qr', 'Check-in: Via Mobile Devices')` — but as
   `target="_blank"` (the `$l()` helper doesn't support target; may need a
   4th item shape or a direct href with target attr).

### 4b. Dashboard target="_blank"

The dashboard `$l()` helper (line 192) creates `['label', 'href']` — no
`target` support. Legacy `qr.php` links use `target="_blank"`.

**Change:** Either extend the item model to support `target`, or hardcode
the qr link as a raw item: `['label' => 'Check-in: Via Mobile Devices',
'href' => '/qr', 'target' => '_blank']`. Update blade render (line 74) to
emit `target` attr when present.

---

## Slice 5 — Dashboard dropdown menus (PARITY-013, 53 missing)

**Target:** `app/Http/Controllers/Admin/DashboardController.php` (replace
flat links with `$family()` calls), no blade changes (blade already renders
`children` as dropdowns).

This is the largest controller-only slice. 53 of 63 legacy dropdown menus
are flattened to single links or absent. The data is all queryable the way
`Add Scores to...` (line 392-398) and `$bosStyleTypes` (line 487) already
prove.

### 5a. Assignments per-session dropdowns (overlaps with Slice 1a)

"Judges for Session..." and "Stewards for Session..." dropdowns —
per-`judging_locations` row, each with `view=name` and `view=table`
children. Build via `$family()` from `judging_locations` query.

### 5b. Table-cards per-table dropdown

"For Table..." dropdown — per-`judging_tables` row, emitting
`go=judging_tables&action=default&id={id}`. Build via `$family()`.

### 5c. Pullsheets per-table / per-location dropdowns

Legacy emits `Mini-BOS per Table...`, `Mini-BOS per Location...`,
`BOS per Style Type...` dropdowns. The port already has some of these
(lines 502-503, 487-489) — audit which are missing and add the rest.

### 5d. BOS Cup Mats per-table / per-style-type dropdowns

Legacy emits `For Table...`, `For Style Type...`, `Pro-Am...` dropdowns
under BOS Cup Mats. Port already has some (lines 481, 487, 493, 496) —
audit and complete.

### 5e. "Add Entries to..." dropdown

Legacy: per-table dropdown for adding entries to a table. Absent in port.

**Change:** Add `$family('Add Entries to...', per-table children)` emitting
the appropriate `table_cards` or `pullsheets` hrefs.

### 5f. Results card per-method matrix

Legacy: `prefsWinnerMethod` × entry/judging-number × per-table matrix of
links. Port collapses to 26 generic links with machine-string labels.

**Change:** Rebuild the matrix using `$family()` calls:
- For each `prefsWinnerMethod` value, emit a family of links.
- Restore legacy link labels ("All with Scores for Table..." etc.) instead
  of machine strings ("judging_scores | scores | winners (filter)").

---

## Slice 6 — awards.php (PARITY-001, biggest)

**Target:** New controller + route + blade view, dashboard button + modal,
sidebar link.

### 6a. Route + controller

Legacy `awards.php` (1360 lines): reveal.js presentation.
- URL params: `view={white|black|blue}` (theme), `go={table-numbers|
  table-name-only|table-entry-count-asc|table-entry-count-desc}` (sort).
- Access gate: `msg=7` redirect if results not published and not admin.
- Renders `<section>` slides: title slide, judges/staff roll, per-table
  winners, BOS winners, mini-BOS results, best brewer/club.
- reveal.js from CDN + theme CSS (`white.min.css`, `black.min.css`,
  `moon.min.css`).

**Change:**
1. New route: `GET /awards` → `AwardsController@show()` accepting `view`
   and `go` query params.
2. Controller: port the slide-building logic from `awards.php`. The queries
   (judges/staff, per-table winners, BOS, best brewer/club) map to existing
   `ResultsRepository` methods (line 173) and direct DB queries.
3. View: `resources/views/awards/show.blade.php` — reveal.js layout with
   `@foreach` over slide sections. Load reveal.js + theme CSS from CDN
   (matching legacy).
4. Access gate: if `!$displayToPublic && !isAdmin`, redirect to `/?msg=7`.
   `$displayToPublic` = `judgingPast && entryWindowClosed &&
   registrationClosed && judgeWindowClosed && prefsDisplayWinners=Y &&
   judging_winner_display(prefsWinnerDelay)`.

### 6b. Dashboard presentationLaunch modal + button

Legacy (default.admin.php:519-560): "Launch Awards Presentation" button +
`#presentationLaunch` modal with a table of 4+ methods × 3 themes (Light/
Dark/Blue-Green).

**Change:**
1. Add "Launch Awards Presentation" button to dashboard (gated on
   `prefsWinnerMethod == 0` && `judgingStarted` && `userLevel == 0` —
   matching legacy line 518).
2. Add `<dialog id="presentationLaunch">` modal to dashboard.blade.php with
   the method × theme link table. Each link: `/awards?go={method}&view=
   {theme}` with `target="_blank"`.

### 6c. Sidebar launch button

Legacy may have a sidebar launch button (check `sidebar.admin.php.inc`).
If present, add to the port's admin sidebar layout.

---

## Dependency graph

```
Slice 1 (link fixes) — no deps, controller+output-controller only
  └── 1a needs AssignmentsController view param support
  └── 1c needs ResultsController/ExportController view param audit
  └── 1d needs ArchiveController action=add support

Slice 2 (un-stub) — depends on Slice 1 (1a assigns dropdowns)
  └── 2a needs new JudgingNumber regenerate route + controller
  └── 2b no deps (purge controller exists)
  └── 2c no deps (static help text)
  └── 2d depends on Slice 4 (qr.php)
  └── 2e deferred to P2

Slice 3 (publish) — no deps
  └── 3a PROCESS_MAP fix — no deps
  └── 3b new controller + route — no deps
  └── 3c dashboard button — depends on 3b

Slice 4 (qr) — no deps
  └── 4a new controller + routes — no deps
  └── 4b dashboard target=_blank — depends on 4a
  └── 2d un-stub depends on 4a

Slice 5 (dropdowns) — depends on Slice 1 (1a assignments dropdowns)
  └── 5a overlaps with 1a — merge
  └── 5b-5f independent families

Slice 6 (awards) — no deps, biggest, last
  └── 6a controller + route — no deps
  └── 6b dashboard modal — depends on 6a
  └── 6c sidebar — depends on 6a
```

## Commit sequence

1. **`feat(dashboard): fix assignment/table-cards/output/archive link emissions`** — Slice 1 (1a-1d; 1e deferred). Controller + output controller param handling. Run parity harness after.
2. **`feat(dashboard): wire purge flows to existing PurgeController`** — Slice 2b. Replace 6 purge `$todo()` with `/admin/purge` links. No new routes.
3. **`feat(dashboard): restore help modals as dialog elements`** — Slice 2c. 9 `<dialog>` modals with legacy help text. Blade + controller.
4. **`feat(judging): add judging-number regenerate route + dashboard modals`** — Slice 2a. New route + controller + 3 modals.
5. **`feat(results): add publish-results action with dashboard button`** — Slice 3. New route + controller + PROCESS_MAP fix + button.
6. **`feat(checkin): port qr.php mobile check-in page`** — Slice 4. New controller + routes + blade. Un-stubs Slice 2d.
7. **`feat(dashboard): restore per-table/session/style-type dropdown menus`** — Slice 5 (5b-5f; 5a merged into Slice 1). Controller `$family()` calls.
8. **`feat(awards): port awards.php reveal.js presentation`** — Slice 6. New controller + route + blade + dashboard modal + button. Biggest commit.

## Verification per commit

After each commit:
1. Run `tools/parity/parity.sh` — confirm MISSING row count drops.
2. Run `php artisan test` — confirm 806 tests still pass (no regressions).
3. Run `tools/parity/fetch_export.sh` — confirm export byte-parity holds.
4. For new routes: `php artisan route:list --path=/admin` confirms registration.
5. For dashboard changes: load `/admin` in browser, confirm no dead spans for
   the un-stubbed items, dropdowns render, links resolve.

## Out of scope (P2+)

- PARITY-006 (public anon sidebar) — P2, separate surface.
- PARITY-007 (change-email page decision) — P2, design decision.
- PARITY-008/009 (contact/volunteers gating) — P3, content divergence.
- PARITY-010 (pixel audit) — P4, visual.
- PARITY-011 (browser journey tests) — P3, test suite.
- PARITY-012 (sponsors 404) — P2, route fix.
- PARITY-015 (results label wording) — P2, could merge into Slice 5f.
- PARITY-020-025 — P3/P4, separate batches.
