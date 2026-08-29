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

### Port design system

- Tailwind CSS + daisyUI, two themes (app.css):
  `bcoem` public — Bootstrap-5 blue family (#007bff primary) from legacy
  `default-3.min.css`; `bcoem-brux` admin — the Materials palette above.
- Bootstrap-class compat layer (row/col/container/table/nav/form/lead/h*)
  so views keep legacy class names.
- Admin pages load jQuery 2.2.4 + BS 3.3.7 JS + flatpickr from CDN;
  FontAwesome 6.5.1 everywhere (legacy: FA4 admin / FA6 public — v4 shims
  only on legacy public).

## Verified structural parity (from markup diffs + views)

| Element | Status |
|---|---|
| Page width: public `container-xxl` (1320px @1400) / admin `container-fluid` | PASS — constants_post_lang.inc.php:24-29 mirrored in layout |
| Fixed dark navbar public / navbar-inverse admin | PASS |
| Hero band + salutation band + print-only h1 | PASS |
| Footer fixed dark with BCOE&M version line | PASS |
| Admin 9/3 dashboard row (col-lg-9 + sidebar) | PASS |
| Public anon sidebar col-lg-3 | **FAIL — not rendered** (PARITY-006) |
| animate.css entrance animations | MISSING |
| daisyUI radius 0.25rem vs BS3 4px / BS5 0.375rem | close; buttons/inputs not pixel-audited |

## Public/admin theme split — CORRECT in port

bootstrap.php:422-431: public pages hardcode `default-3.min.css` + `common-3.css`;
`prefsTheme` (corpus value: `bruxellensis`) applies **to admin only**. The port's
two-theme split — `bcoem` (public, default-3 palette) / `bcoem-brux` (admin,
bruxellensis Materials palette) — matches the legacy selection exactly.
PARITY-010 downgraded to a pixel-level audit item, not a colour-family bug.

## Screenshot pass still required
Audit §5 requires controlled same-viewport/same-data screenshots. Queue
(browser relay against both local apps on the anon-base corpus):
home, list, brew, pay, contact, volunteers, register×3, login, admin
dashboard, preferences×5, entries, participants, judging ×7, scores, BOS,
eval dashboard + scoresheet. Until then visual parity is UNVERIFIED, only
structural/text parity is evidenced.
