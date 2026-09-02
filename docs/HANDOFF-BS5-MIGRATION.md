# Handoff — BCOEM BS5 Migration (issue #1 parent workstream)

State as of 2026-09-02, after issue #2 shipped and issue #3 was scoped. Written for a
fresh agent to pick up the migration at issue #3.

## Tracker state (GitHub `delight-f/bcoem-next`)

| Issue | Title | State |
|---|---|---|
| #1 | Port admin + public UI to real Bootstrap 5 (parent spec) | OPEN |
| #2 | Dusk test harness for BS5 migration gate | **CLOSED (completed)** |
| #3 | Expand: load real Bootstrap 5 beside shim stack + preserve palettes | **CLOSED (completed)** — see Implementation log below |
| #4–#13 | Public daisy purge, admin chrome ×4, view batches A–C, contract, docs+visual gate | OPEN (deps on #2 → now unblocked) |

Issue #2 is fully implemented, verified, and closed. Its delivery:

- `laravel/dusk` v8.6 added (`composer.json`). Side effects: `guzzlehttp/guzzle` 8.0.2 → 7.15.5,
  `laravel/framework` 13.26.1 → 13.30.1 (both within Laravel 13's accepted ranges).
- `geckodriver` 0.37.1 installed at `~/.local/bin/geckodriver` (the distro
  `firefox-geckodriver` apt package ships no binary; the official Mozilla tarball was used).
  Browser available in this env: **Firefox 154** (no Chrome).
- `tests/DuskTestCase.php` — Firefox-over-geckodriver Dusk base. **Requires an external
  geckodriver on :4444** (`geckodriver --port=4444`). Do NOT auto-spawn geckodriver as a
  child of the PHPUnit process: it breaks Dusk's `tearDownDuskClass` session DELETE and
  makes otherwise-green tests exit 2.
- `tests/Concerns/AssertsBs5Markers.php` — migration gate trait. Vocab:
  `bs3OnlyClasses` (panel*, navbar-inverse, btn-default, caret, input-group-addon,
  navmenu*, pull-right, help-block, form-horizontal, page-header, …), `daisyOnlyMarkers`
  (*-bordered, modal-box, table-zebra, btn-ghost, label-text, bare `data-theme`), `bs5Markers`
  (data-bs-*, btn-close, form-label, form-select, container-xxl). Methods: `assertBs3Absent`,
  `assertDaisyAbsent`, `assertBs5Present`, `assertPageIsBootstrap5`, and pure browser-free
  statics `markersInHtml()` / `markerInHtml()` (disambiguates `data-bs-theme` from daisy's
  `data-theme`; avoids BS3/BS5 shared-class false positives).
- `tests/Browser/Bs5MarkerHarnessTest.php` — 3 proof-of-harness browser tests. Logs in via
  the real login dialog as `smoke.p57@brewingcompetitions.com` / `bcoem` (level-0 smoke admin
  seeded in the `review_scabs` dev DB), asserts pre-migration admin dashboard carries BS3
  markers and judging-tables carries daisy markers.
- `tests/Unit/Bs5MarkersTest.php` — 6 fast unit tests on the marker detector.
- `.gitignore` gained Dusk artifact entries.

**How to run the Dusk harness** (preconditions: geckodriver on :4444, dev server on :8000 —
the :8000 server is a throwaway local dev target, safe to break):
```
geckodriver --port=4444 &        # external; must NOT be a phpunit child
php artisan serve --port=8000 &  # or reuse the running one
php artisan dusk tests/Browser/Bs5MarkerHarnessTest.php    # → 3/3 pass, exit 0
php artisan test --filter Bs5MarkersTest                   # → 6/6 pass
```
`php artisan dusk` swaps `.env` ↔ `.env.dusk.local`; with no `.env.dusk.local` present it
uses `.env` unchanged and restores cleanly. Dusk 8 does not auto-start a PHP server.

**Full-suite note:** 881 tests → 879 pass, 1 skip, 1 pre-existing failure
(`EvalSubAppTest::test_import_consensus_round_trip_and_idempotency`) that **passes in
isolation** — a shared-`bcoem_test` DB fixture-collision flake unrelated to issue #2 (no
app source touched).

## The central finding driving the whole migration

**No real Bootstrap CSS is loaded anywhere today.** The public pages' "Bootstrap 5" look is
entirely a hand-written BS5-primitive replica inside `resources/css/app.css`. The admin's
Bootstrap is also a hand replica (BS3), plus real BS3.3.7 **JS** via CDN. `app.css` is a
**Tailwind v4** stylesheet:

```
resources/css/app.css (1617 lines)
├── 1-2    Google-font @imports (Noto Serif/Sans, Merriweather, Droid Sans)
├── 3      @import 'tailwindcss'
├── 4-5    @source directives
├── 7-46   @plugin "daisyui" + themes
├── 12-45  daisy @plugin theme "bcoem"   (public palette — default-3 :root)
├── 46-124 daisy @plugin theme "bcoem-brux" (admin palette — bruxellensis Materials)
├── 125-266 @layer components { BS5-primitive replica }   ← the hand BS5 "compat layer"
│          (.row/.col-*/.container*/.nav*/.table*/.form*/.h*/.lead/.small …)
├── 267-…   @layer components { migrated legacy CSS }     ← default-3/common-3 + bruxellensis
│          (:root vars --blue…--dark, .bcoem-*, .landing-page-*, hero/salutation/footer,
│           admin top-bar/off-canvas, BS3 panels/buttons, BS3.3.7 replica at 1079-1617)
└── 594,628 unlayered blocks (glance cards, bruxellensis) — unlayered on purpose to win
```

Cascade reality: daisy themes supply the colour palette via CSS vars; the `@layer
components` blocks hand-implement the Bootstrap class names views use. Views are
overwhelmingly **BS5-class-named** (`btn`, `form-control`, `card`, `container-xxl`,
`.row/.col-*`); daisy-only tokens exist in ~55 views; BS3-only tokens in the admin chrome +
~90 admin/backoffice/eval blades. Shared class names valid in both BS3 and BS5
(.btn, .form-control, .modal, .container-fluid) must NOT be treated as legacy markers —
the `AssertsBs5Markers` vocab already handles this.

## Issue #3 — the expand step (next)

Spec (from #3): load real Bootstrap 5 beside the shim stack so later view batches render
under a genuine BS5 engine, while daisy/Tailwind/the BS3 replica keep working until the
contract (#12). Preserve the two-palette split (public `bcoem`, admin `bcoem-brux`) as BS5
themes.

**Decision made (user, 2026-09-02): option 3 — make the expand meaningful.**
Add real Bootstrap 5 CSS/JS, and **delete the now-redundant hand-written BS5-primitive
replica** (app.css ~lines 77–264, the `/* ---- Compat layer: Bootstrap-5 layout
primitives ---- */` comment through the close of the first `@layer components` block), so
pages genuinely render under real BS5 — with daisy still supplying its palette and its own
components until each batch purges them. This is the highest-alignment-with-end-state
option. It is the largest immediate visual-change risk, but the :8000 dev server is
disposable — safe to iterate on.

Implementation notes for the next agent:

- `bootstrap@^5.3.8` is **already installed** in `package.json` (added 2026-09-02, not yet
  wired). `dependencies: { bootstrap: ^5.3.8 }`.
- Real BS5 must be introduced so the daisy/legacy layers (which must keep winning until
  batches land) sit above it. Recommended: load real Bootstrap CSS in a low native cascade
  layer (or via CDN link in `public-layout.blade.php` head before the Vite bundle — the
  layout currently loads only FontAwesome CDN + admin flatpickr/jQuery/BS3.3.7 JS, then
  `@vite(['resources/css/app.css', 'resources/js/app.js'])`). Decide the exact mechanism
  deliberately; document it in the issue.
- The BS5 *primitives* (grid/containers/typography/utilities) are the clean deletion; the
  migrated **legacy identity classes** (.bcoem-*, .landing-page-*, hero/salutation/footer,
  admin chrome) and the **BS3 replica** (admin, 1079–1617) stay until their own batches.
- Keep the marker harness green on representative pages: no BS3/daisy markers introduced,
  no BS5 regression (acceptance criterion from #3).
- Admin currently CDN-loads real BS3.3.7 JS + jQuery 2.2.4 for modals/dismiss — that stays
  until #8 (session modals) / the contract.

Dependency-order reminder: #4 (public purge) and #5–#8 (admin chrome) block #9–#11 (view
batches); #12 (contract: delete Tailwind/daisy/shim) blocks on #4, #8, #9, #10, #11; #13
(docs + visual gate) blocks on #12. The migration is expand–contract; every batch keeps CI
green because the old shim still exists until #12.

## Environment facts a fresh agent needs

- App: Laravel 13.30.1, PHP 8.4, MySQL. Local `.env` → `DB_DATABASE=review_scabs` (the
  disposable dev DB, unprefixed tables, contest "Brewer's Brewer 2025").
- Feature-test DB: MySQL `bcoem_test` with `baseline_`-prefixed tables (CI loads
  `sql/bcoem_baseline_3.0.X.sql`). Feature tests skip locally without MySQL.
- Dev server already running on :8000 (`php artisan serve`), plus a raw `php -S` on 8899.
- Admin login for browser work: `smoke.p57@brewingcompetitions.com` / `bcoem` (level 0).
- graphify: `graphify-out/` exists with a presentation-layer graph (resources/, docs/parity,
  package.json, vite.config.js). Query with `graphify query "…"` or the `/graphify` skill.

---

## Implementation log — issue #3 (expand) DONE 2026-09-02

Real Bootstrap 5 is now served on both surfaces; the hand-written BS5-primitive
replica is deleted.

**What changed:**
- `resources/css/app.css`: `@import 'bootstrap/dist/css/bootstrap.css' layer(bs5);`
  with `@layer bs5;` declared FIRST → bs5 is the LOWEST cascade layer. Emitted
  order (low→high): properties, bs5, theme, base, components, utilities,
  daisyui.l1-l4. daisyUI components and Tailwind utilities outrank BS5, so the
  public `bcoem` and admin `bcoem-brux` palettes keep winning wherever both
  define a class — NO re-skin. BS5 supplies primitives nothing else defines.
- Deleted the replica `@layer components { … }` block (~137 lines: 12-col grid,
  col-*, gutters, row-cols, form-label/form-check, nav, table helpers, spacing
  utilities). Every deleted class is now supplied by real BS5 (verified: none
  missing from the built CSS).
- KEPT the unlayered `.container/.container-xxl/.container-fluid` override
  (~lines 77–131): Tailwind v4 emits its own `.container` UNLAYERED, so a
  layered BS5 `.container` would lose to it. The unlayered override (later in
  source) is what keeps the ~64 container blades at BS5 px widths. Real BS5's
  own `.container` is inert under it — harmless duplication, required by the
  cascade.
- `resources/js/app.js`: `import 'bootstrap/dist/js/bootstrap.bundle.js';` at
  top. Inert until views use `data-bs-*` (only 1 blade has `data-bs-theme`, a
  CSS marker). No clash with admin's CDN BS3.3.7 JS (BS5 is jQuery-free, never
  touches `$.fn`; BS3 listens to `data-toggle`, BS5 to `data-bs-toggle`).
- `public/build/` rebuilt (gitignored): app CSS 432KB (gzip 65KB), app JS
  87.5KB (gzip 26KB).

**Verification (real Firefox via Dusk):**
- Computed styles on public home: `.row` = flex (BS5 grid live), `.btn-primary`
  bg = #007bff (public bcoem preserved, NOT BS5 #0d6efd), `container-xxl` =
  1320px @ ≥1400px (override wins).
- Computed styles on admin dashboard: `.btn-success` bg = #4CAF50 (brux
  preserved, NOT BS5 #198754); admin chrome BS3 markers unchanged.
- Screenshots: public home + admin dashboard render clean (navbar/hero/cards/
  footer; admin top bar/cards/status panel) — no overlap, no unstyled HTML.
- Dusk harness 3/3 pass (issue-2 gate), Bs5MarkersTest 6/6 pass.
- Temp smoke test (tests/Browser/TmpExpandSmokeTest.php) deleted after passing.

**Cascade landmines for later batches (remember!):**
- Tailwind's `.container` is UNLAYERED — any future BS5-only container blade
  change must keep the unlayered override or explicitly outrank it.
- bs5 layer is BELOW daisyui: when a batch purges daisy tokens from a view, BS5
  components will then style it (that's the migration working as intended).
- BS3 `.col-sm-*` → BS5 `.col-md-*` tier-shift is the grid correctness
  landmine — verify at tablet width (from the original handoff).

---

## Implementation log — issue #4 (public daisy purge) DONE 2026-09-02

The public-facing surface (home shell, auth pages, contact, account, entries
table, brew entry form) is now single-dialect Bootstrap 5 markup with zero
daisyUI-only class tokens. Admin chrome (`data-theme`, BS3 panels, admin
dialogs) is untouched (issues #5-#11).

**Blades purged** (daisy -> BS5):
- `components/public-layout`: login/forgot `<dialog class="modal">` +
  `modal-box/modal-action` -> real BS5 modals (.modal-dialog-centered /
  .modal-content / .modal-header / .modal-body, btn-close, data-bs-toggle /
  data-bs-dismiss); nav Log-In trigger + home/hamburger icons `btn-ghost` /
  `btn-square` -> `btn-link`; modal fields `floating-label`+`input
  input-bordered` -> `form-floating`+`form-control`.
- `auth/login`, `auth/register`, `auth/password`, `auth/passwords/{forgot,
  reset,verify}`: `input/select/textarea-bordered`, `checkbox`, `radio`,
  `floating-label`, `alert-error` -> `form-control`, `form-select`,
  `form-check-input`, `form-floating`, `alert-danger`. Login page auto-reopen
  now uses `bootstrap.Modal.getOrCreateInstance().show()`.
- `public/contact`, `public/account-username`, `public/partials/entries-table`
  (table-zebra -> table-striped, badge-success/error -> text-bg-*),
  `public/partials/{account-main,entries-info}`, `public/pay`,
  `brew/_fields` (8 inputs, 1 select, 3 textareas, 7 radios), `brew/{create,
  edit}`: same dialect sweep.

**CSS bridge (app.css)** — real BS5 modal/form markup would be broken by the
residual shim layers (daisyUI + BS3 replica sit ABOVE bs5 until #12):
- `.modal.show` unlayered rule: daisy `.modal{visibility:hidden}` (only
  reveals `[open]`/:target/`.modal-toggle`) would hide BS5 modals; BS3-replica
  `.modal{z-index:1040}` would put the BS5 `.modal-backdrop` (1050) OVER the
  content. Bridge restores `visibility:visible; z-index:1060` on `.modal.show`
  (admin daisy dialogs never carry `.show`, so they are untouched).
- `.modal-header .btn-close`: daisy's `.btn-close{position:absolute;top:0;
  right:0}` fought the flex header; bridge pins `position:static;
  margin-left:auto`.
- `.form-floating` placeholder: daisy `::placeholder{color:...}` (higher
  layer) made the floating-label text duplicate the placeholder; bridge makes
  form-floating placeholders transparent.
- `.form-select`: daisy styles the bare `select` ELEMENT (transparent bg,
  border-style none, tiny clamp width) so BS5 form-selects were invisible;
  bridge re-states the BS5 look unlayered.
- BS5 theme variables (`--bs-primary:#007bff` etc.) added to the legacy
  `:root` (components layer, above bs5) so purged `.btn-primary/.table/...`
  fall through to real BS5 with bcoem colors, not BS5 defaults.

**Verification**: new `tests/Browser/PublicBs5GateTest` (4/4: home, contact,
auth pages, open login modal — no daisy markers + BS5 markers present);
harness login updated to the BS5 trigger (3/3); unit 6/6; BrowserJourneys
2/2; auth/contact feature tests 19/19. Browser probes confirmed BS5 modal
close button right-aligned, form-floating labels single, select border
#dee2e6/38px. Screenshots of home/contact/register/forgot/login-modal-open
captured and reviewed.

**Gotchas hit**: vision-inspection API cost is exhausted (no more vision
calls — use DOM/computed-style probes); app.css `:root` sits in
`@layer components` and the BS bridge must live there (above bs5) to win;
daisy `data-theme="bcoem-brux"` on admin `<html>` remains (that is issue #7
scope, not #4 — public pages never render it).
