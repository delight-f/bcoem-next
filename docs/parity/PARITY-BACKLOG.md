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
| PARITY-025 | ✓done | S | Unknown | U1-U20 resolved/deferred/routed in LEGACY-UNKNOWN-BEHAVIOUR (Slice 6) | LEGACY-UNKNOWN-BEHAVIOUR |

## Deliberate replacements (no action)

PayPal→Stripe, moment/eonasdan→flatpickr, BS-modals→`<dialog>` on public,
CSRF hardening, email-change page merged. Documented in FORM-PARITY /
MISSING-FUNCTIONALITY.

## Suggested execution order

1. P1 batch: PARITY-004/005/019 (trivial link emissions) → 014 (un-stub)
   → 003 (publish) → 002 (qr) → 013 (dropdowns) → 001 (awards, biggest).
2. P2 batch: 012, 016/017/018 (link variants), 006 (sidebar), 015, 007.
3. P3/P4: content gates (008/009), journeys (011), unknowns (025), visuals.
