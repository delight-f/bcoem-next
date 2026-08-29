# LEGACY-DESIGN-SYSTEM

Reconstructed from `css/common.css`, `css/common-3.css`, `css/default-3.css`,
`css/bruxellensis.css`, `includes/load_cdn_libraries_*.inc.php`, and
`site/bootstrap.php:415-431`.

## Two surfaces, two systems

| Token | Public (`default-3.min.css` + `common-3.min.css`) | Admin (`{prefsTheme}.min.css`, default bruxellensis + `common.min.css`) |
|---|---|---|
| Framework | Bootstrap 5.3.3 + Ninja Bootstrap | Bootstrap 3.3.7 |
| Icons | FontAwesome 6.7.2 + v4 shims | FontAwesome 4.5.0 |
| Fonts | Droid Sans (body), Merriweather (headings) — bruxellensis.css @import | same fonts via bruxellensis.css |
| Primary | #007bff (BS5 blue) | #1565C0 (Materials indigo-blue) |
| Success | #28a745 | #4CAF50 |
| Warning | #ffc107 | #FF9800 |
| Danger | #dc3545 | #F44336 |
| Info | #17a2b8 | #29B6F6 |
| Greys | BS5 greys #f8f9fa…#343a40 | #DFE4EC → #303E4B blue-grey ramp |
| Container | `container-xxl` (BS5: 1320px max @≥1400) | `container-fluid` |
| Navbar | fixed-top `navbar-dark bg-dark` | `navbar-inverse` fixed-top |
| Radius | 0.375rem controls | 4px (BS3) |
| Footer | fixed-bottom dark | fixed-bottom (navbar-fixed-bottom) |
| Extra libs | DataTables 2.1.8, animate.css, tom-select, fancybox | DataTables 1.10.12, fancybox, moment+eonasdan, Jasny offcanvas |

## Custom BCOEM classes (common*.css) — both sides

- `.bcoem-comp-logo` sidebar logo padding
- `.bcoem-admin-element` admin block margin treatment
- `.bcoem-warning-container` alert stack padding-top 30px
- `.bcoem-user-info-table` borderless info tables
- `.bcoem-account-info`, `.bcoem-sponsor-container/name`
- `.anchor` / `:target:before` — 50px scroll-offset system
- `.landing-page-section`, `.landing-page-salutation`, `.landing-page-section-header`
  (public section rhythm)
- `.layout-hero`, `.color-hero`, `.shadow-text` hero band
- `.admin-nav-off-canvas` right-fixed menu
- Panels: BS3 `.panel .panel-heading .panel-title .panel-body` with
  success/danger/info variants for window states

## Port mapping (verified)

`resources/css/app.css`:

- daisyUI theme `bcoem` (default) = public palette (default-3 :root vars)
- daisyUI theme `bcoem-brux` = admin palette (bruxellensis)
- `data-theme="bcoem-brux"` set by public-layout when admin-side
- BS5 layout primitives + legacy `:root` CSS vars (--blue…--dark,
  --breakpoint-*) + heading scales inside `@layer components`
- legacy classes `bcoem-*`, `landing-page-*`, panels carried via the
  migrated legacy CSS blocks (app.css lines ~263-418 unlayered→components)

## Gaps in the mapping

1. `.anchor`/:target offset — present; verify hero-anchor nav offsets match.
2. Hero image randomisation by style type (hero_images.lib.php) — port
   passes `$heroImage`; verify pref-JSON filtering + random pick parity.
3. animate.css animations not mapped.
4. Google-font @import parity: port imports Noto + Droid Sans + Merriweather;
   legacy imports Droid Sans + Merriweather only — Noto extra is harmless
   but verify headings actually use Merriweather both sides.
