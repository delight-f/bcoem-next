# COMPONENT-PARITY

Bootstrap-era components → Tailwind/daisyUI translation audit.

## Architecture finding

The port does **not** use shared Blade components for Bootstrap-era widgets
(`<x-button>`, `<x-panel>`, `<x-alert>` do not exist). Instead it uses a
three-layer strategy:

1. **daisyUI theme tokens** (`resources/css/app.css`): two themes —
   `bcoem` (public; palette from legacy `default-3.min.css` :root:
   primary #007bff, success #28a745, danger #dc3545 — Bootstrap 5 default
   blue) and `bcoem-brux` (admin; palette from `css/bruxellensis.css`:
   primary #1565C0 indigo-blue, success #4CAF50, warning #FF9800,
   error #F44336 — Google Materials).
2. **Bootstrap-class compat layer** (`app.css` @layer components): `.row`,
   `.col-*` (BS5 breakpoints), `.container*`, `.table-*`, `.nav-*`,
   `.form-*`, `.lead`, `.small`, heading sizes — because Blade views
   deliberately keep legacy Bootstrap class names.
3. **Direct legacy classes in views** (`btn btn-primary`, `panel`, `alert
   alert-danger`…): resolved by the compat layer + unlayered legacy CSS
   blocks migrated from `default-3.css`/`common-3.css` (app.css :root vars
   --blue…--dark, --breakpoint-*).

This is a legitimate translation strategy (audit §7 allows it): the shared
"component" is the CSS layer, not a Blade include. Consequence: fixes belong
in `app.css` theme/compat layers, not per-page.

## Component map

| Bootstrap-era pattern | Legacy source | Port implementation | Status |
|---|---|---|---|
| Tables (`.table`, dataTables) | DataTables 1.10 admin / 2.1.8 public | plain tables; **no DataTables in port** | **PARTIAL** — only 2 legacy pages actually init DataTables (styles.admin.php:153, judging_scores_bos.admin.php:130/217; client-side sort, no paginate). 28 other admin pages use jQuery `sortable()` row reordering — verify port equivalents per page |
| `.panel panel-{default,info,success,danger}` | BS3 sidebar/dashboard | compat layer (`bcoem-*` classes migrated); daisyUI card unused | PARTIAL — sidebar panels not rendered at all (PARITY-006) |
| `.alert alert-{danger,success,warning,info}` | both | daisyUI `.alert` + compat | PASS (msg codes mapped: 5,8,11,13,14,99…) |
| `.modal fade` (login, forgot, pay confirm, session-expire, required-info) | BS modal JS | public: `<dialog>` + `data-open-modal` JS; admin: real BS3 modal kept (jQuery+BS3 JS loaded) | PARTIAL — admin ✓; public dialog semantics (focus trapping, Esc) ≈ BS modal but not verified per-modal |
| Dropdown menus (user, admin off-canvas) | BS3 dropdown / BS5 dropdown | admin: BS3 JS kept; public: CSS dropdown | PARTIAL — no keyboard/focus parity audit |
| `.navbar` fixed-top dark | BS5 navbar (public) / BS3 navbar-inverse (admin) | compat + tailwind utilities | PASS structure; N1/N2 gaps in NAVIGATION-PARITY |
| Off-canvas admin menu | Jasny offcanvas (`navmenu-fixed-right`) | CSS-transform off-canvas in layout | PASS |
| Tables (`.table`, dataTables) | DataTables 1.10 admin / 2.1.8 public | plain tables; **no DataTables in port** | **PARTIAL/UNKNOWN** — sorting/search/pagination behaviour of big tables (entries, participants, scores) differs; verify which legacy tables actually initialised DataTables |
| fancybox iframe links (Reports) | fancybox 3.5.7 | direct navigation | PARTIAL (N7) |
| Date/time picker | eonasdan+moment (admin) | flatpickr | PASS (functional equivalent; visual differs deliberately) |
| tom-select (public multi) | tom-select BS5 | daisyUI select / native | UNKNOWN — verify multiselect fields (brew form clubs?) |
| Tooltips (`data-toggle=tooltip`) | BS3 tooltip JS | title attr fallback? | UNKNOWN — admin keeps BS3 JS (works); public side unverified |
| Loader overlay (`#loader-submit`) | custom | port? | UNKNOWN — verify |
| animate.css hero/salutation animations | animate.css | **MISSING** in port (no fadeIn animations on hero/salutation) | PARTIAL — visual only |
| Print styles (`hidden-print`, print.min.css) | BS print utils | tailwind `print:` variants | PASS |
| Session-expire modals (2min/30s) | BS3 modal + autologout.js | kept verbatim (admin), JS timer in app.js | PASS |

## Gaps ranked for the backlog

1. DataTables behaviour on big admin tables (entries/participants/scores) —
   biggest interaction-parity risk; check legacy pages that call
   `DataTable()` (grep `dataTable` in admin/*.php) and either port the
   behaviour or confirm tables were plain.
2. animate.css entrance animations on hero/salutation (visual).
3. Tooltip init on public side.
4. Loader overlay.
5. Modal focus/Esc behaviour parity on `<dialog>` conversions.
