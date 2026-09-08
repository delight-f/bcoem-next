# VISUAL-PARITY

## Method

The standing parity harness (`tools/parity/parity.sh`) compares normalised
markup + word streams, not pixels. No side-by-side screenshot pass has been
run. This doc records the design-system mapping (verifiable from CSS) and
flags what needs screenshot comparison — per audit §5 that pass is still
owed before visual parity can be claimed.

## Legacy design system (reconstructed from CSS + CDN loads)

### Split frameworks (verified from load_cdn_libraries_*.inc.php)

**Public** (index.pub.php): Bootstrap 5.3.3 + Ninja Bootstrap override +
FA 6.7.2 (with v4 shims) + DataTables 2.1.8 + animate.css 4.1.1 + tom-select
2.3.1 + fancybox 3.5.7 + jQuery 3.7.1. Fonts: Droid Sans + Merriweather
(bruxellensis.css @import). Landing-page pattern: hero band (3000×500 webp,
gradient overlay), salutation band (black bg), section blocks, fixed dark
footer, fixed dark navbar.

**Admin** (index.legacy.php): Bootstrap 3.3.7 + FA 4.5.0 + DataTables
1.10.12 + fancybox + moment 2.29.1 + eonasdan datetimepicker. Theme
`bruxellensis.css` Google-Materials palette:
primary #1565C0, info #29B6F6/#0288D1, success #4CAF50, warning #FF9800,
danger #F44336; greys #394a59…#DFE4EC; container-fluid layout, page-header
h1 pattern, panels, navbar-inverse, Jasny off-canvas right menu.

### Port design system (re-baseline — issue 12)

- **Single real Bootstrap 5** build (no Tailwind, no daisyUI, no BS3 compat
  shim). `resources/css/app.css` declares `@layer bs5` with the BS5 import
  at the lowest layer; all legacy identity/BS3-replica CSS is unlayered
  author CSS that beats the `bs5` layer.
- Two theme palettes, both mapped to BS5 `--bs-*` component variables:
  `:root` — public bcoem palette (#007bff primary, #17a2b8 info, #28a745
  success, #dc3545 danger, #ffc107 warning), matching legacy default-3.css;
  `[data-bs-theme="bcoem-brux"]` — admin Materials palette (#1565C0 primary,
  #29B6F6 info, #4CAF50 success, #F44336 danger, #FF9800 warning),
  matching legacy bruxellensis.css. BS5 `.btn-*`/alerts rebound to `var(--bs-*)`
  so both themes flow through one mechanism.
- No jQuery, no Bootstrap 3 JS CDN — BS5's `bootstrap.bundle.js` drives all
  interactions (dropdowns, modals, collapse, offcanvas, tooltips via the
  `.bcoem-tooltip` CSS pattern). Flatpickr (admin date picker, CDN) and the
  framework-agnostic DataTables-parity sort/pagination remain.
- FontAwesome 6.5.1 everywhere (v4 shims via `fa-solid` + `fa-regular` +
  `fa-brands` CSS).

## Verified structural parity (from markup diffs + views)

| Element | Status |
|---|---|
| Page width: public `container-xxl` (1320px @1400) / admin `container-fluid` | PASS — constants_post_lang.inc.php:24-29 mirrored in layout |
| Fixed dark navbar public / navbar-inverse admin | PASS |
| Hero band + salutation band + print-only h1 | PASS |
| Footer fixed dark with BCOE&M version line | PASS |
| Admin 9/3 dashboard row (col-lg-9 + sidebar) | PASS |
| Public anon sidebar col-lg-3 | PASS — rendered via `public-sidebar` (PARITY-006, P2 Slice 7) |
| animate.css entrance animations | **MISSING → P3 Slice 8**: `reveal-element` fade-in via IntersectionObserver in app.js (not animate.css, but entrance animation parity); CSS tooltips + loader + sticky-home added |
| daisyUI radius 0.25rem vs BS3 4px / BS5 0.375rem | close; buttons/inputs not pixel-audited — screenshot pass owed (PARITY-010) |

## Public/admin theme split — CORRECT in port

bootstrap.php:422-431: public pages hardcode `default-3.min.css` + `common-3.css`;
`prefsTheme` (corpus value: `bruxellensis`) applies **to admin only**. The port's
two-theme split — `bcoem` (public, default-3 palette) / `bcoem-brux` (admin,
bruxellensis Materials palette) — matches the legacy selection exactly.
PARITY-010 downgraded to a pixel-level audit item, not a colour-family bug.

## Screenshot pass (P4 Slice 6, PARITY-010)
Admin session captured via headless fetch-CSRF login (the browser-relay
login works when the _token is fetched first and the POST submitted via
`page.evaluate(fetch())`). Saves to `tools/parity/screenshots/`:
- `home-p4.webp` (1600×2831 full-page) — home with salutation, section
  cards, sidebar panels, footer.
- `admin-dashboard-p4.webp` (1600×1374) — admin chrome: off-canvas nav,
  status panels, create-entrant quick-register link, off-canvas nav.
- `list-p4.webp` (1600×2185) — entrant list (logged in as admin, showing
  own entries table with the data-dt sort widget).
- `participants-p4.webp` (1600×1790) — admin participants filter grid
  with column headers, Register... dropdown.

The full queue (eval/scores/BOS/register×3/contact/volunteers/brew/pay)
owes the browser-relay pass with a stable login profile for the next
round. The user's visual review of these 4 frames is the final gate.
