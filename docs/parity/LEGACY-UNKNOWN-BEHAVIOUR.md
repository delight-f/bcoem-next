# LEGACY-UNKNOWN-BEHAVIOUR

Audit rule §19.10: record UNKNOWNs rather than guessing.

Resolved during P3 Slice 6 (2026-08-30). Disposition legend:
- **RESOLVED** — behaviour determined; no port change needed (parity or
  documented replacement).
- **DEFERRED** — needs live scabs data / organiser input (external).
- **→ Slice N** — a P3 slice owns the resolution.

| # | Question | Disposition (P3 Slice 6 sweep) |
|---|---|---|
| U1 | `ppv.php` purpose + whether scabs uses it | **RESOLVED** — PayPal IPN verification endpoint (PayPal POSTs IPN; ppv.php verifies + records payments). Port uses Stripe (documented PayPal→Stripe replacement, FORM-PARITY); no port equivalent needed. |
| U2 | Maintenance-mode UX parity (MAINT flag) | **RESOLVED** — legacy MAINT is a build-time constant in config (index.php:36-58): when on, non-admin/non-logged-in users redirect to ?section=maintenance; when off, that section redirects home. Port uses Laravel's built-in `php artisan down` (standard deploy-mode replacement); no runtime pref equivalent. |
| U3 | Numeric error pages visual parity | **RESOLVED** — P3 Slice 5 ported the in-site 404 (contest chrome + "NNN Error. {text}" salutation, index.pub.php:113-121); 401/403/500 keep Laravel defaults (edge cases). Visual pass owed in Slice 12. |
| U4 | jQuery `sortable()` on admin pages — port drag ordering | **→ Slice 10** (PARITY-024): 28 legacy surfaces; port per-surface native/sortable decision there. |
| U5 | DataTables on styles + judging_scores_bos client sort | **→ Slice 9** (PARITY-023). |
| U6 | Mods rendering (`prefsUseMods=Y`) on public pages | **RESOLVED (deliberate replacement)** — legacy include-renders `mods/*.php` files (mods_top/bottom.inc.php, gated prefsUseMods=Y && !HOSTED). Port stores mods in DB (ModsController CRUD, filename regex `[A-Za-z0-9_-]+\.php`) but does NOT include-render: arbitrary PHP include is an RCE boundary. Admin workflow preserved; public rendering not ported. |
| U7 | Tooltips on public side | **→ Slice 8** (PARITY-021). |
| U8 | Loader overlay + no-JS alert parity | **→ Slice 8** (PARITY-021). |
| U9 | Required-fields modal + disabled submit gate | **RESOLVED (deliberate replacement)** — legacy has no dedicated required-fields modal; it relies on the Bootstrap validator + HTML5 `required` + pwstrength meter (load_cdn_libraries_public.inc.php). Port uses native HTML5 required + daisyUI validation + server-side Laravel validation: equivalent UX, standard replacement. |
| U10 | tom-select multiselect (clubs/styles filters) | **RESOLVED (deliberate replacement)** — legacy loads tom-select 2.3.1 but actual multiselects use `bootstrap-select` (eval_scoresheet_checklist.pub.php etc.). Port uses native/daisyUI selects with equivalent multi-value support. |
| U11 | Delete confirmations per CRUD page | **RESOLVED** — port has 18 `confirm()` guards across admin blades (sponsors/entries/styles/mods/contacts/participants/...), matching legacy's confirm-before-destroy pattern. |
| U12 | Help icon (`bcoem_help()`) per admin section | **RESOLVED (deliberate replacement)** — legacy navbar right-side fa-question-circle opens a per-section help modal (nav.sec.php:389-392, help.lib.php). Port covers help content contextually: dashboard help modals (P1, PARITY-014), per-field popovers on entries (P2). The navbar-level per-section icon is not replicated; in-page contextual help replaces it. |
| U13 | Sticky home + scroll-indicator + smooth anchor | **→ Slice 8** (PARITY-021). |
| U14 | Archive offcanvas triggers on live scabs data | **DEFERRED (P4 Slice 7 re-confirmed)** — corpus has demoarchive only; needs a real scabs DB copy to verify archiveSuffix-driven conditions. |
| U15 | Language toggle scope for scabs | **DEFERRED (P4 Slice 7 re-confirmed)** — pref-driven; needs organiser confirmation whether scabs serves non-English users. |
| U16 | DataTables public side (10 pub/*.php surfaces) | **→ Slice 9** (PARITY-023). |
| U17 | `maps.output.php` port controller binding | **RESOLVED** — `php artisan route:list` shows `GET|HEAD admin/output/maps .. outputs.maps › Output\MapsController@__invoke`; the `..` in the earlier dump was a display artifact. |
| U18 | Session countdown on public side (dropdown footer) | **→ Slice 7** (PARITY-020) — session-expire modals already present (public-layout 467-500); the "Auto Log Out in N" countdown in the user dropdown is the residual (nav.pub.php:189). |
| U19 | Judging Dashboard dropdown link conditions | **→ Slice 7** (PARITY-020) — eval row exists with prefsEval gate (public-layout:49); exact brewer_assignment() conditions to verify there. |
| U20 | `custom_competition_info.pub.php` drop-in | **DEFERRED (P4 Slice 7 re-confirmed)** — file-based drop-in; check scabs deploy bundle (external). |
