# PARITY-BACKLOG

Prioritised, actionable items from this audit. Priority per audit §16:
P1 = feature visibly broken/missing to users; P2 = information architecture;
P3 = content divergence; P4 = visual polish. Size: S/M/L.

| ID | Pri | Size | Domain | Item | Evidence |
|---|---|---|---|---|---|
| PARITY-001 | P1 | L | Route+Nav | Port `awards.php`: route `/awards/{view}/{sort}` (reveal.js, 3 themes × 4 sorts, judges/staff rolls, BOS + mini-BOS winners, msg=7 gate); restore dashboard Launch button + `#presentationLaunch` modal + sidebar launch button | 11 dead hrefs; button+modal absent |
| PARITY-002 | P1 | M | Route | Port `qr.php` mobile check-in; add admin-nav row (target=_blank) | dead stub on Entry Sorting card |
| PARITY-003 | P1 | M | Route | Port `process.inc.php?action=publish` (Publish Results): POST route setting prefsResultsReturned + releasing public winners; dashboard button + popover; fix PROCESS_MAP `publish` → wrong `/admin/archive` placeholder | button absent; PROCESS_MAP line 180 |
| PARITY-004 | P1 | S | Nav | Emit `participants?filter=judges/stewards`, `entries?filter=N`, staff-assign links on judging assign pages | 4 MISSING × 5 assign corpus pages |
| PARITY-005 | P1 | S | Nav | `/backoffice/participants?filter=judges\|stewards` reachable from assign nav | same evidence |
| PARITY-006 | ✓done | M | Layout | Render public anon sidebar (`sidebar.sec.php` 9 panels) on section pages — P2 Slice 7 (`public-sidebar` component) | 
| PARITY-007 | ✓done | S | Nav/Form | Restored distinct change-email page (`/user/username`) — P2 Slice 5; merged form also remains | GET_MAP row |
| PARITY-008 | ✓done | S | Page | Contact page: empty rendering when prefsContact=X matches legacy (no section content; Slice 3) | 200B vs 492B text streams |
| PARITY-009 | ✓done | S | Page | Volunteers page: Other Volunteer Info block gated on body, no coming-soon fallback (Slice 4) | stream diff |
| PARITY-010 | P4 | S | Visual | Screenshot pass — 4 captures in tools/parity/screenshots/ (P4 Slice 6); user's visual review of the frames is the final gate. Pixel-level button/input sizing unverified until then | VISUAL-PARITY |
| PARITY-011 | ✓done | L | Test | Browser journey tests — flows 1 (entrant register→entry→pay) + 2 (admin nav) in BrowserJourneysTest; flows 3-5 DB-covered by per-screen suites (Slice 11) | BrowserJourneysTest |
| PARITY-012 | ✓done | S | Route | Sponsors: created `/sponsors` page + landing sponsors section — P2 Slice 1 | GET_MAP line 47 |
| PARITY-013 | P1 | L | Component | Restore dashboard dropdown menus (53 of 63 flattened): per-table/session/style-type/round `<button class="dropdown-toggle">` menus — "For Table…", "For Session…", "Numbers for Session…", "Winners for Session…", "Add Entries to…" — data queryable as `Add Scores to...` already proves | dashboard raw diff |
| PARITY-014 | P1 | M | Component | Replace 19 dead TODO spans: JN-regen modals ×3, purge/confirm flows ×6, help modals ×9, qr.php link, Import Scores | dashboard raw |
| PARITY-015 | ✓done | M | Component | Results card: legacy link matrix + method labels from constants.inc.php — P2 Slice 3 | dashboard raw |
| PARITY-016 | P2 | S | Nav | Dashboard assignments: emit per-session variants (location=N view=name/table, view=location) | 6 missing hrefs |
| PARITY-017 | P2 | S | Nav | Table-cards: add `psort=sorting-tables{&view=master-list}` + per-table `go&action=default&id=N` links | 3 missing hrefs |
| PARITY-018 | P2 | S | Nav | Output variants: export-results BOS download pdf/html, export-staff download, staff print, inventory go=scores | 5 missing hrefs |
| PARITY-019 | P2 | S | Nav | Dashboard "Archive Current Data" → `/admin/archive?action=add` (currently plain `/admin/archive`, losing action) | controller line 640 |
| PARITY-020 | ✓done | M | Nav | Judging Dashboard (N4, gated) + Auto Log Out countdown (N6) in user dropdown; N1 Past Winners offcanvas = deliberate replacement (sidebar panel serves IA) (Slice 7) | nav.pub.php |
| PARITY-021 | ✓done | M | Interaction | CSS tooltips, loader overlay, sticky-home in app.js (no Bootstrap on public); animate.css entrances → reveal-element fade-in (Slice 8) | COMPONENT/INTERACTION |
| PARITY-022 | ✓done | S | Route | In-site 404 with contest chrome + 'NNN Error. {text}' salutation (Slice 5) | index.pub.php numeric sections |
| PARITY-023 | ✓done | M | Component | Client-side sort + pagination widget for <table data-dt>, tagged participants/entries (Slice 9); public winners tables server-ordered | grep dataTable |
| PARITY-024 | ✓done | M | Component | Sortable rows — RESOLVED: no jQuery-UI sortable in legacy; the 28 'sortable' grep hits are DataTables `id="sortable"` table ids (client sort → PARITY-023/Slice 9). Flight reorder is a form POST (`action=reorder_flight_entries`, ported → FlightController + GET_MAP row). No drag-reorder to port. | grep sortable |

## P5 additions (2026-08-31, product framing: ftp-deploy, setup-from-scratch anywhere)

Product reality (from the maintainer): the repo is downloaded, uploaded via FTP to a
server, and the site is set up from scratch — scabs is just an example of an already-set-up
site. So a fresh install must be self-sufficient for any language and file-based
extensibility is the norm, not an edge case.

| ID | Pri | Size | Domain | Item | Evidence |
|---|---|---|---|---|---|
| PARITY-026 | P1 | L | I18n | **Language packs + runtime toggle** (promoted from U15-DEFERRED → missing feature). Legacy ships 6 languages: `lang/{en,cs,es,fr,hu,pt}/*-*.lang.php` (each locale: `{code}.lang.php` public + `{code}_admin.lang.php` + `{code}_help.lang.php`; codes en-US/en-GB/cs-CZ/es-419/fr-FR/hu-HU/pt-BR). Selection contract (lang/language.lang.php:42-78): `prefsLanguage` default `en-US`; per-session cookie `userLanguage` overrides when `prefsLanguageToggle=Y` AND the code is in `prefsLanguageOptions` (JSON; fallback `get_available_language_codes()` = glob `lang/*/*.lang.php` matching `[a-z]{2}-[A-Za-z0-9]+`, common.lib.php:160); `prefsLanguageFolder` = lowercase first segment (`en-US`→`en`). Admin/evaluation/setup/update sections ALWAYS force en-US (language.lang.php:95-110). Navbar toggle (pub/nav.pub.php:130-152): globe dropdown, gated `prefsLanguageToggle=Y && count>1`, builds `?lang={code}` on the current URL (strips existing `lang=`), sets the 30-day cookie, active item marked + fa-check. **Port state**: `SitePreferencesController` validates+stores `prefsLanguage`/`prefsLanguageToggle`/`prefsLanguageOptions` (updateDefault, ~line 211) but the port renders English only — `lang/en/site.php` + English literals in blades; `prefsLanguage` is read in exactly 3 places (PublicController:39,164,376 — `str_starts_with(..., 'en-')` for DateFmt long-style) and NEVER applied to strings. No `?lang=` handling, no toggle, no `lang/` other than `en/`. **Work**: (1) port the 5 non-en lang packs (copy legacy `lang/*/` → port `lang/*/`; strings already exist there); (2) add a `__()`/`Lang` layer keyed by resolved language (session cookie override → prefsLanguage; admin forces en) — the port's `lang/en/site.php` keys must map to legacy's variable names per locale; (3) navbar globe dropdown in `public-layout.blade.php` gated on prefsLanguageToggle + options, `?lang=` param sets cookie; (4) keep admin en-US-forced. Size L: the string-surface is ~4000 legacy vars/locale; a pragmatic first cut ports the public surface only (admin stays en-US like legacy's own comment "A future version will have full conversions for Admin"). | lang/language.lang.php:42-110, pub/nav.pub.php:130-152, common.lib.php:160-170; port lang/ has only en/ |
| PARITY-027 | P1 | M | Feature | **Mods public rendering** (re-examine U6 deliberate-replacement). Legacy contract (index.pub.php:357+618: `prefsUseMods=Y && !HOSTED` → include `mods_top.inc.php`/`mods_bottom.inc.php`): builds `mods_display` from the `mods` DB rows (includes/db/mods.db.php), then per row `mod_display($row,$section,$go,$user_level,$page_location)` (mods.db.php:54-108) checks `file_exists(MODS.$mod_filename)` + enable flag + placement: `display_section` map (default/rules/volunteers/sponsors/contact/pay→1, register→6, list→8, admin→9, else 0); public rows render when `mod_extend_function == display_section || 0`, `mod_display_rank == page_location (1=top,2=bottom)`, `mod_permission >= user_level`; admin rows when `go == mod_extend_function_admin`. Missing-file alert lists enabled/disabled files (mods_top.inc.php:29-50). **Port state**: mods CRUD admin ported (ModsController, spec §7 P5.4 — ten mods columns, mod_enable toggles, dashboard Manage/Add links at DashboardController:725-726) but NO public include-render — the file-include itself was the RCE concern. **Work**: port the public render WITHOUT arbitrary PHP include — render DB-stored mods (mod_top/mod_bottom content fields if the schema has them; else the admin-editable HTML) at the legacy placement points in `public-layout.blade.php` (top of main, after footer) with the same gating (prefsUseMods=Y, enabled, placement, permission, section-map). The `mods` table columns available: check the migration; legacy rows carry `mod_extend_function`, `mod_display_rank`, `mod_permission`, `mod_filename` + content fields — map to a safe HTML render. Keep the RCE boundary: no `include()`. | index.pub.php:357,618; includes/mods_top.inc.php; includes/db/mods.db.php:54-108; port ModsController has CRUD only |
| PARITY-028 | P1 | S | Feature | **Custom competition-info drop-in equivalent** (re-examine U20). Legacy: `if (file_exists(PUB.'custom_competition_info.pub.php')) include(...)` renders an optional file-based block at `?section=competition` (index.pub.php:405) and adds an "Other Info" nav link when present (pub/nav.pub.php:109). The repo ships NO such file (checked: absent) — it's a deploy-time customization hook. **Port state**: nothing — `/competition` section (competition-info) renders no equivalent hook; no nav "Other Info" item. **Work**: a safe equivalent — a DB-stored `competition_info_extra` block (or reuse a contest_info column / the mods-style table) rendered on the competition-info page + an "Other Info" nav item when non-empty, mirroring legacy's file_exists gate. S-size: one blade section + one nav conditional + a storage location. | index.pub.php:405, pub/nav.pub.php:109; file absent in repo (deploy-time only) |

## Suggested execution order (updated)

P5 batch (new): PARITY-028 (S, quick) → PARITY-027 (M, safe render) →
PARITY-026 (L, language packs + toggle).

## Deliberate replacements (no action)

PayPal→Stripe, moment/eonasdan→flatpickr, BS-modals→`<dialog>` on public,
CSRF hardening, email-change page merged. Documented in FORM-PARITY /
MISSING-FUNCTIONALITY.

