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
| PARITY-008 | P3 | S | Page | Contact page: match legacy empty rendering when contacts disabled/absent | 200B vs 492B text streams |
| PARITY-009 | P3 | S | Page | Volunteers page: gate text on prefsVolunteers/judging window like legacy | stream diff |
| PARITY-010 | P4 | S | Visual | Pixel-level button/input audit (daisyUI vs BS3/BS5 sizing/radius); screenshot pass owed | VISUAL-PARITY |
| PARITY-011 | P3 | L | Test | Browser journey tests (5 flows listed in INTERACTION-PARITY) | none exist |
| PARITY-012 | ✓done | S | Route | Sponsors: created `/sponsors` page + landing sponsors section — P2 Slice 1 | GET_MAP line 47 |
| PARITY-013 | P1 | L | Component | Restore dashboard dropdown menus (53 of 63 flattened): per-table/session/style-type/round `<button class="dropdown-toggle">` menus — "For Table…", "For Session…", "Numbers for Session…", "Winners for Session…", "Add Entries to…" — data queryable as `Add Scores to...` already proves | dashboard raw diff |
| PARITY-014 | P1 | M | Component | Replace 19 dead TODO spans: JN-regen modals ×3, purge/confirm flows ×6, help modals ×9, qr.php link, Import Scores | dashboard raw |
| PARITY-015 | ✓done | M | Component | Results card: legacy link matrix + method labels from constants.inc.php — P2 Slice 3 | dashboard raw |
| PARITY-016 | P2 | S | Nav | Dashboard assignments: emit per-session variants (location=N view=name/table, view=location) | 6 missing hrefs |
| PARITY-017 | P2 | S | Nav | Table-cards: add `psort=sorting-tables{&view=master-list}` + per-table `go&action=default&id=N` links | 3 missing hrefs |
| PARITY-018 | P2 | S | Nav | Output variants: export-results BOS download pdf/html, export-staff download, staff print, inventory go=scores | 5 missing hrefs |
| PARITY-019 | P2 | S | Nav | Dashboard "Archive Current Data" → `/admin/archive?action=add` (currently plain `/admin/archive`, losing action) | controller line 640 |
| PARITY-020 | P3 | M | Nav | Public navbar: Past Winners offcanvas (N1), session countdown in user dropdown (N6), Judging Dashboard link (N4) | nav.pub.php |
| PARITY-021 | P3 | M | Interaction | Restore tooltips (public), loader overlay, sticky-home, animate.css entrances | COMPONENT/INTERACTION |
| PARITY-022 | P3 | S | Route | Error pages 404 etc. rendered in-site with salutation treatment | index.pub.php numeric sections |
| PARITY-023 | P3 | M | Component | DataTables parity: legacy styles + judging_scores_bos (admin), 10 pub/*.php surfaces (bestbrewer/bos/…) client sort/pagination | grep dataTable |
| PARITY-024 | P3 | M | Component | jQuery-UI sortable row reordering on 28 admin pages — audit + replicate drag ordering | grep sortable |
| PARITY-025 | P3 | S | Unknown | Resolve U1-U20 list (ppv.php, maintenance, mods rendering, tom-select, …) | LEGACY-UNKNOWN-BEHAVIOUR |

## Deliberate replacements (no action)

PayPal→Stripe, moment/eonasdan→flatpickr, BS-modals→`<dialog>` on public,
CSRF hardening, email-change page merged. Documented in FORM-PARITY /
MISSING-FUNCTIONALITY.

## Suggested execution order

1. P1 batch: PARITY-004/005/019 (trivial link emissions) → 014 (un-stub)
   → 003 (publish) → 002 (qr) → 013 (dropdowns) → 001 (awards, biggest).
2. P2 batch: 012, 016/017/018 (link variants), 006 (sidebar), 015, 007.
3. P3/P4: content gates (008/009), journeys (011), unknowns (025), visuals.
