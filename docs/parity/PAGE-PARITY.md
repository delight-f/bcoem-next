# PAGE-PARITY

Per-screen coverage matrix. "Harness" = page-diff run 20260829-073729
(147 pages fetched, content word-stream compared). Content-diff here means
the word-stream diff; 143/144 are 5-line single-stream diffs dominated by
chrome ordering — after `chrome-exclude.txt` substring stripping the core
anon/entrant pages re-diff clean, so most rows are PASS-with-residuals.

## Public screens

| Legacy screen | Legacy URL | Port route | Exists | Reachable | Functional | Content parity |
|---|---|---|---:|---:|---:|---|
| Home/landing | `?section=default` | `/` | YES | YES | YES | PARTIAL (hero/salutation/sections; sidebar panels missing) |
| Entry info | `?section=entry` | `/` `#entry-info` anchor | YES | YES | YES | PASS |
| Rules | landing section | `/#rules` | YES | YES | YES | PASS |
| Contact | `?section=contact` | `/contact` | YES | YES | YES | **DIFF** (see below) |
| Volunteers | `?section=volunteers` | `/volunteers` | YES | YES | YES | **DIFF** (see below) |
| Sponsors page | `?section=sponsors` | none | **NO** | NO | NO | NO — home anchor only (ROUTE-PARITY FAIL row) |
| Competition/custom info | `?section=competition` | `/` | PARTIAL | YES | PARTIAL | custom_competition_info.pub.php not portable by design — UNKNOWN |
| Past winners | `?section=past-winners&go=X` | `/past-winners/{X}` | YES | YES | YES | PASS (demoarchive fetched clean) |
| Login | `?section=login` | `/login` + modal | YES | YES | YES | PASS (chrome) |
| Forgot/verify/reset password | `login&go=password&action=*` | `/forgot-password*`, `/reset-password` | YES | YES | YES | PASS |
| Register entrant/judge/steward | `?section=register&go=*` | `/register/{go}` | YES | YES | YES | entrant PASS; judge/steward residual 5-line diffs (admin chrome) |
| Maintenance | `?section=maintenance` | none | **NO** | NO | NO | UNKNOWN scope |
| Error pages 400-500 | `?section=404` etc. | Laravel error views | PARTIAL | YES | YES | visual parity unverified |

## Entrant screens (auth)

| Legacy screen | Port route | Status |
|---|---|---|
| My Account / list | `?section=list` → `/list` | PASS-with-residual (word order: "My Account"/"Account Info" band + "Edit Account Info" placement) |
| Add entry | `brew&action=add` → `/brew` | PASS-with-residual (same chrome ordering) |
| Edit entry | `brew&action=edit&id=N` → `/brew/{id}/edit` | PASS (admin corpus variant diffed) |
| Edit account | `brewer&go=account&action=edit` → `/list/edit-account` | PASS-with-residual |
| Edit clubs/profile | `brewer&go=profile` → `/list/edit-clubs` | PASS |
| Edit judging prefs | `list&go=account` → `/list/edit-judging` | PASS |
| Change password | `user&action=password` → `/user/password` | PASS |
| Change email | `user&action=username` → merged into `/list/edit-account` | **PARTIAL** — merged, distinct page gone |
| Pay | `?section=pay` → `/pay` (+checkout/callback/cancel) | PASS (Stripe replaces PayPal — documented replacement) |

## Admin screens (userLevel ≤ 1)

All 36 `go=` surfaces exist and render (admin corpus 731 URL pairs fetch
through the harness). Rows with residuals:

| Surface | Status | Residual |
|---|---|---|
| Dashboard | **FAIL (card-level)** | see "Admin dashboard card-by-card audit" below — awards (12 links) + Publish Results (1) + assignments per-session variants (6) truly missing; 19 dead placeholder rows; 53 of 63 dropdowns flattened; 32 of 43 modals absent; 0/5 popovers |
| Judging assign (judges/stewards/bos/staff) | DIFF | 4 missing links each: participants?filter=…, entries?filter=N, tables assign staff — PARITY-004/005 |
| Preferences tabs (5) | DIFF | 5-line stream diffs (chrome) |
| Brewer edit ×5 ids | DIFF | 5-line stream diffs |
| Count by style/substyle (+no_zeros) | DIFF | 5-line stream diffs |
| make_admin/edit-username/change-password ×5 | DIFF | 5-line stream diffs |
| All other admin CRUD | PASS | fetched clean |

## Admin dashboard card-by-card audit (run-20260829-073729, level 0 admin, corpus anon-base)

Method: full `<a href>` extraction + modal/popover/dropdown inventory from
both `.raw` artifacts; card inventory from `admin/default.admin.php`
panel-headings vs `DashboardController::sections()`. The 10 card titles
match 1:1. Raw link-count comparison (760 vs 684 `<a>`) is misleading —
574 legacy hrefs resolve through the urls.txt contract / dynamic redirect
handler (`LegacyUrlRedirectTest` 163/163). True deltas:

| Class | Count | Verdict |
|---|---|---|
| awards.php links | 11 unique hrefs (12 renders) | MISSING (PARITY-001) |
| process.inc.php?action=publish | 1 | MISSING (PARITY-003); PROCESS_MAP currently 302s `publish` → `/admin/archive` placeholder |
| assignments per-session variants (`go=judging_assignments` + `location=1` + `view=name/table`; `view=location`) | 6 | MISSING — port emits filter-only assignments links, no location/session variants |
| table-cards: `go=judging_tables&psort=sorting-tables` (+master-list), `go&action=default&id=1` | 3 | MISSING variants (port has placards + `?id=1`) |
| export-results BOS download ×2, export-staff download, inventory `go=scores`, staff download | 5 | MISSING output variants |
| labels matrix (306 legacy hrefs) | 306 | **ALL MATCH** by (go,action,filter,psort,sort) param-set — the harness "missing" rows were param-order noise; port emits 367 (6 combo dupes) |

**Structural regressions (rendering, not href inventory):**

- Legacy 43 modal targets vs port 11. Port lost: all 9 dashboard-help
  modals, JN-regen ×3, quicksort, judgeInventory, updateSummary,
  presentationLaunch, BJCPCompID, purge ×12 (7 stubbed as dead spans,
  5 absent entirely).
- Legacy 63 dropdown menus (caret) vs port 9. Every per-table /
  per-session / per-style-type `<button class="dropdown-toggle">` menu
  ("For Table…", "For Session…", "Numbers for Session…", "Winners for
  Session…", "Add Entries to…") is either absent or flattened to a single
  hardcoded link (`table_cards?id=1`, `location=1&round=1` — wrong-table
  selection semantics).
- Legacy 5 popovers vs port 0 (Publish Results explainer etc.).
- 19 dead `<span class="text-muted">TODO</span>` rows render as disabled
  text: JN regen ×3, qr.php check-in ×1, Import Scores ×1, Clean-Up ×1,
  Confirm/Purge ×5, help ×9.
- Results card link labels are machine strings
  ("judging_scores | scores | winners (filter)") instead of legacy wording
  ("All with Scores for Table..."), and the per-method matrix
  (prefsWinnerMethod × entry/judging number × per-table) is collapsed to
  26 generic links.
- Publish Results button absent entirely (legacy: popover +
  process.inc.php?action=publish; PROCESS_MAP maps `publish` →
  /admin/archive 302 as a placeholder, so even the legacy URL lands on the
  wrong page — PARITY-003 includes fixing the PROCESS_MAP row).
- Launch Awards Presentation button + #presentationLaunch modal absent
  (PARITY-001).

Cards whose links are complete and correct: Competition Preparation,
Preferences, Data Exports (all CRUD/manage+add pairs present), Scoring
(except Import Scores stub), Entries and Participants (assign links go to
tables assign surface per contract), Entry Sorting (except JN-regen/qr
stubs), Organizing, Data Management (except the 7 dead purge/confirm rows
and Archive Current Data pointing at manage page instead of
`?action=add` flow — GET_MAP maps `admin|archive|add` →
`/admin/archive?action=add`, but the dashboard hardcodes `/admin/archive`,
losing the action).

### Whole-dashboard losses (not card-specific)

| Legacy surface | Evidence | Port |
|---|---|---|
| **Publish Results button** | popover + `process.inc.php?action=publish` | **absent entirely** (PARITY-003) |
| **Launch Awards Presentation button + modal** | `#presentationLaunch` modal with Light/Dark/Blue ×3 sorts | **absent entirely** (PARITY-001) |
| Quick Sort labels modal (`quicksortModal`) | bottle/box label quick access | absent |
| Judge Inventory modal (`judgeInventoryModal`) | explains judge inventories | absent |
| Update Summary modal (`updateSummary`) | DB version notice | absent (port has "Post-Competition Tasks" modal instead) |
| Purge modals ×12 (`purgeEntries`, `purgeScores`, `purgeScoresheets`, `purgeUploadedScoresheets`, `purgeAvailabilty`, `purgeCustom`, `purgeAll`…) | confirm-then-process.inc.php flows | absent (7 stubbed) |
| Popovers ×5 (Publish Results explainer etc.) | `data-toggle="popover"` | 0 in port |
| JN regen modals ×3 | `#jn-random-modal`… | stubbed |
| BJCPCompIDModal | BJCP comp id copy helper | absent |

### Fix guidance (PARITY items)

1. PARITY-001 (awards): restore button + `#presentationLaunch` modal in
   dashboard + sidebar; route `/awards/{view}/{sort}` port of awards.php.
2. PARITY-003 (publish): restore button + popover; port
   `process.inc.php?action=publish` (sets prefsResultsReturned, releases
   winners publicly) as POST route.
3. PARITY-013 (new): restore the 53 dropdown menus as data-driven
   `<select>`-style menus (per-table/session/style-type/round) — the data is
   all queryable the way `Add Scores to...` already does.
4. PARITY-014 (new): replace 19 dead TODO spans with real targets (JN regen,
   purge flows, help modals, qr.php, import scores, Add Entries to...).
5. PARITY-015 (new): restore label text of Results matrix links to legacy
   wording; regenerate per-method matrices from prefsWinnerMethod.

## Content divergences investigated

### Contact page (anon corpus)

Legacy text stream (200 bytes) is chrome-only: title, "Contact", hero, footer.
Port (492 bytes) renders: "Thank you for your interest… Display of
competition contacts has been disabled by the site administrators…"

Cause: legacy `contact.pub.php`/`contact.sec.php` content is conditioned on
prefsContacts/contacts rows; with none configured, legacy emits nothing while
the port still renders the disabled-help message. Required behaviour: match
legacy — when contacts are disabled/absent, legacy shows nothing beyond the
salutation; verify `sections/contact.sec.php` vs `pub/contact.pub.php`
conditions and align the port's PublicController::contact.

### Volunteers page (anon corpus)

Same pattern: legacy stream shorter; port renders the full volunteer pitch
text ("Thank you for your interest… sunshinecoastbrewers.com"). Legacy gates
volunteer text on prefsVolunteers/judging window; port unconditionally shows.
Align gates.

## Screens with no port equivalent (MISSING-FUNCTIONALITY cross-ref)

| Legacy | Notes |
|---|---|
| `awards.php` | full awards presentation (reveal.js, 3 themes × 4 sorts; judges/staff rolls, BOS winners, mini-BOS results) |
| `qr.php` | standalone mobile check-in |
| Public sidebar panels | sidebar.sec.php (anon sections) |
| Numeric error sections | rendered via index.pub.php with salutation treatment |
| `ppv.php` | UNKNOWN purpose — investigate before porting |
