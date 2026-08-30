# MISSING-FUNCTIONALITY

Legacy capabilities with no port equivalent, ranked by user impact.

| ID | Capability | Legacy evidence | Impact | Notes |
|---|---|---|---|---|
| MF-1 | Awards presentation screen | `awards.php` (reveal.js; 3 themes × 4 sort modes; judges/steward/staff rolls; category/BOS winners; public after results published, else `msg=7`) | HIGH — ceremony-day feature; sidebar launch button + 12 dashboard links | PARITY-001 |
| MF-2 | Mobile QR check-in | `qr.php` (standalone, target=_blank) | MEDIUM — day-of ops | PARITY-002 |
| MF-3 | Results publish action | `process.inc.php?action=publish` (dashboard) | HIGH — gates public winners display | PARITY-003 |
| MF-4 | Public anon sidebar panels | `sections/sidebar.sec.php` (9 panels incl. judging locations, windows, past winners, awards launch) | MEDIUM-HIGH — anon info architecture | PARITY-006 |
| MF-5 | Standalone sponsors page | `sections/sponsors.sec.php` via `?section=sponsors` | LOW (home anchor serves most traffic) but GET_MAP target 404s | PARITY-012 |
| MF-6 | Past Winners offcanvas from navbar | `#archive-list` offcanvas (nav.pub.php:117) | MEDIUM — only nav path to archives | N1 |
| MF-7 | Language toggle + translations | `?lang=` cookie dropdown; lang/{de,en,es,fr,hu,pt} | MEDIUM if scabs serves non-English users; port has `lang/en/site.php` only | scope decision needed |
| MF-8 | Custom competition info page | `custom_competition_info.pub.php` (drop-in file) + `?section=competition` | LOW — file-based extension point | design decision: content field vs file |
| MF-9 | Maintenance mode surface | MAINT flag → `?section=maintenance` for non-admins | LOW-MEDIUM | port: verify a maintenance equivalent exists (Laravel down?) |
| MF-10 | Judging Dashboard link in user dropdown | nav.pub.php:182 | LOW (link exists on account page) | N4 |
| MF-11 | Auto-log-out countdown in user dropdown | nav.pub.php:189 `#session-end` | LOW | N6 |
| MF-12 | Mods rendering on public pages | `mods_{top,bottom,sidebar}_*.inc.php` when prefsUseMods=Y | UNKNOWN — CRUD ported, rendering not verified | INTERACTION cross-ref |
| MF-13 | animate.css entrance animations | hero/salutation fadeIn | LOW visual | COMPONENT cross-ref |
| MF-14 | fancybox lightbox for outputs | Reports menu opens iframes in lightbox | LOW-MEDIUM interaction | N7 |
| MF-15 | ppv.php | 12.5KB standalone | UNKNOWN purpose | investigate |
| MF-16 | Numeric error pages via app chrome | `?section=404` renders in-site | LOW | verify Laravel 404 page matches structure |

## Deliberate, documented replacements (not missing)

- PayPal IPN → Stripe (checkout/webhook/connect) — FORM-PARITY.
- moment/eonasdan datetimepicker → flatpickr.
- Bootstrap JS on public side → `<dialog>` + vanilla JS.
- ~~`brewer&action=username` distinct email page → merged account form~~
  restored as /user/username (PARITY-007, P2 Slice 5); the merged form
  also remains.
- Legacy AJAX GET/POST without tokens → CSRF-protected POSTs (envelopes kept).

## Open gaps (P2 residuals, 2026-08-29)

- ~~`?section=admin&go=participants&action=add`~~ — CLOSED 2026-08-30
  (P3 Slice 2): the legacy Register dropdown (participants.admin.php:583
  -587) is ported verbatim into /backoffice/participants (Register...
  → A Participant / A Judge (Standard) / A Steward (Standard) / Judge
  (Quick) / Steward (Quick)); each routes through /register/{go}, where
  an authenticated admin bypasses the registration window gates and the
  store creates users + brewer + staff rows then redirects
  /backoffice/participants?msg=1. Admin-driven participant creation is
  ported; the standalone participants.admin.php:765 email/password form
  is replaced by the combined register form (documented replacement).
- The 13 `awards.php*` linkmap rows and the
  `send_test_email.admin.php?csrf=` row are legacy-URL-shape noise on
  mapped surfaces (`/awards`, `/admin/send-test-email` both exist);
  the redirect `/awards.php` route keeps old bookmarks working.
