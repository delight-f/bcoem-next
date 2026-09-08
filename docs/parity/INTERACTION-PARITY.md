# INTERACTION-PARITY

Interaction inventory from legacy JS + markup, with port status.

| Interaction | Legacy implementation | Port status |
|---|---|---|
| Login modal focus (autofocus username) | `shown.bs.modal` → focus | `<dialog>` autofocus attr — verify |
| Loader overlay on form submit/nav | `#loader-submit` + fa spinner, shown by JS | UNKNOWN — verify port shows equivalent |
| No-JS alert hide | `#no-js-alert` hidden by JS | not ported (port has no no-JS alert) — acceptable/verify |
| IE user-agent alert | alert() on MSIE/Trident | not ported (IE dead) — acceptable, record |
| Session auto-logout (2min/30s modals, countdown in user dropdown) | autologout.min.js + moment tz | admin modals ported verbatim + app.js timer; **public countdown display missing** (N6) |
| Print icon → window.print | nav.sec.php admin navbar | UNKNOWN — verify port admin navbar print handler (N11) |
| Help icon per section | `bcoem_help()` tooltip/modal | UNKNOWN — verify (N12) |
| Sticky back-to-top (`#sticky-home`) | custom JS | verify port |
| Scroll indicator on landing | bounce arrow | not observed in port — verify |
| Anchor smooth-scroll + :target offset | CSS scroll-behavior + .anchor | compat layer has .anchor — verify |
| Hero random image per style types | PHP random_int + pref JSON | port: `$heroImage` passed to layout — verify randomisation parity |
| Archive offcanvas (Past Winners) | BS offcanvas | **MISSING** (N1) |
| fancybox iframe outputs (Reports menu) | fancybox 3.5.7 | direct links (N7) — behaviour delta |
| Barcode check-in scan | checkin page + scanner JS | ported `/admin/judging/checkin` (store) — functional tests pass |
| QR mobile check-in | qr.php standalone | **MISSING** (PARITY-002) |
| DataTables client sort | styles + BOS pages only | port: unknown — verify sort UI on those two pages |
| jQuery UI sortable row reordering | 28 admin pages | UNKNOWN — verify per page (biggest hidden surface) |
| Tom-select multi (clubs, styles filter) | tom-select BS5 | verify brew form club field behaviour |
| AJAX username/email validation | ajax/*.ajax.php, status envelopes | ported `/ajax/*` with legacy envelopes — PASS |
| AJAX save (judge/steward prefs) | ajax/save.ajax.php | ported — PASS |
| Count-records modal (entry limits) | ajax/count_records | ported — PASS |
| Date pickers (admin dates/forms) | eonasdan+moment | flatpickr — functional parity, visual differs (deliberate, documented) |
| Pay confirm modal → gateway | `#confirm-submit` modal then submit | Stripe checkout redirect — documented replacement |
| Delete confirmations | window.confirm / custom | verify parity per CRUD page |
| form-submit disabled until required filled | required modal + JS gate | UNKNOWN (FORM-1) |
| Winners reveal (awards page) | reveal.js themes | **MISSING** with awards.php (PARITY-001) |
| Mods top/bottom/sidebar includes | prefsUseMods | port `/admin/mods` CRUD exists; **rendering of mod includes on public pages** — UNKNOWN |

## Browser-journey parity tests (audit §14)

Existing suites cover DB-state convergence (auth-gated) and PDF/CSV outputs.
Missing journey-level browser tests:

1. anon → login modal → entrant list → add entry → pay (msg codes visible)
2. admin → dashboard → every off-canvas section → CRUD round-trip
3. judging flow: locations → tables → flights → scores → BOS → results print
4. archive → past-winners
5. check-in via barcode

None exist as browser tests today (harness is HTTP-diff based). Backlog:
PARITY-011.
