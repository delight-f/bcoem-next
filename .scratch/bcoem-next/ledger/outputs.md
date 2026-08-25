# Behavior Ledger: PDF outputs pipeline (Slice D)

> Established by P5.1 (`output/pullsheets.output.php` port) as the pipeline
> validation case. Reference for every P5.2+ output agent — do not
> re-litigate these decisions per ticket.

## Decision: dompdf

Legacy vendors FPDF 1.x and draws every output with `Cell()`/`Ln()`
coordinate code across ~20 files under `includes/fpdf/*`, dispatched by
`includes/output.inc.php`. Ported with **dompdf 3.1** (HTML+CSS → PDF):

- outputs become Blade templates in `resources/views/outputs/` instead of
  coordinate-drawing ports;
- no binary dependency (snappy/wkhtmltopdf is deprecated upstream);
- deterministic enough for visual parity review against legacy prints.

Raw-FPDF-fork rejected: keeps ~20 files of coordinate code alive for no gain.

## Shared pattern (copy this shape)

1. **Route**: pre-wired in `routes/outputs.php` (integrator-owned):
   `GET /admin/output/{slug}` → single-action controller
   `App\Http\Controllers\Output\<StudlySlug>Controller::__invoke(Request)`,
   named `outputs.{slug}`. Controllers must exist for the route file to even
   load — the loop registers all slugs up front.
2. **Auth gate**: `$request->user()?->isAdmin()` check →
   `redirect('/?msg=99')` (legacy redirected unauthenticated/unprivileged to
   403.php; the port reuses the app-wide msg=99 idiom).
3. **Rendering**: `App\Support\Outputs\StreamPdf::response($view, $data,
   $filename)` streams inline (`Content-Disposition: inline;
   filename="..."`), letter paper, remote assets disabled. Pass
   `$download: true` for attachment-style outputs (labels etc.).
   `StreamPdf::bytes($view, $data)` renders raw bytes for tests.
4. **Filename convention**: lowercase slug + `.pdf` (e.g. `pullsheets.pdf`).
5. **Data access**: Laravel `DB::table` on the tenant DB;
   config rows via `TenantContext::load()` (replaces legacy's
   `$_SESSION['prefs*'] / jPrefs*` wholesale copies).
6. **Testing**: PDF bytes are deflate-compressed — do NOT scrape text from
   the response. Assert status/content-type/`%PDF` magic bytes over HTTP,
   then assert row content/ordering against the same data array the
   controller feeds the view (expose a small builder if needed).

## Legacy quirks mirrored (pullsheets.output.php)

Source: `output/pullsheets.output.php` (+ `output_pullsheets*.db.php`),
default "all tables" mode (`$id == "default"`), both judging modes.

| # | Quirk | Where mirrored |
|---|-------|----------------|
| 1 | Column set (default mode): pull-order blank, judging number `%06s`, style ("12A American Stout" + category line), entry-info cell, box number, empty score/place boxes for handwriting | Ticket P5.1 specifies entry #, judging #, style, brewer name, special-ingredients cell — port renders exactly those five columns; blank handwriting columns dropped (divergence, see below) |
| 2 | Sort order: SQL orders `brewJudgingNumber ASC`; final display order is datatables' natural sort of the ZERO-PADDED number — port sorts server-side with `strnatcmp(sprintf('%06s', …))` since dompdf runs no JS | `PullsheetsController::tableEntries()` |
| 3 | Grouping: one sheet per table ordered by `tableNumber ASC`; entries matched to tables via `tableStyles` CSV → `styles.brewStyleGroup/brewStyleNum` → `brewing.brewCategorySort/brewSubCategory`, one query per style id in legacy, one OR-grouped query in the port | `build()` / `tableEntries()` |
| 4 | Page breaks: `<div style="page-break-after:always;"></div>` after EVERY table (last one included); flights-mode additionally breaks between flights | view `.page-break` divs |
| 5 | Flights mode: an entry without a `judging_flights` row is silently NOT pulled (`check_flight_number("")` → `check_flight_round("", "default") === false`) | `buildTable()` |
| 6 | Manual pull order: saved `flightEntryOrder` ASC with NULLs LAST, tie-break natural-cmp of padded judging number (ledger flight-assignment.md #4/#5); overrides judging-number order only for flights with saved order | verbatim legacy usort semantics |
| 7 | Queued judging (`jPrefsQueued='Y'`): NO flight grouping/filtering — every received entry pulled flat per table, even with zero flight rows | `buildTable()` else branch |
| 8 | Received filter: `brewReceived='1'` always (pull sheets never include unreceived entries, unlike table-planning counts) | `tableEntries()` |
| 9 | Empty-category guard: rows with empty `brewCategorySort` skipped (`if (!empty(...))` guard); unreachable via the pair join, noted for parity reviewers | `tableEntries()` docblock |
| 10 | Required Info: `brewInfo` shows ONLY when the style demands special ingredients (`styles.brewStyleReqSpec=1` via `style_convert(…,"9")`), labelled "Regional Variation" for style 2A under BJCP2021/2025 | `infoRows()` |
| 11 | Number padding: both entry id and judging number rendered `sprintf('%06s')` | `entryNo`/`judgingNo` |
| 12 | Header block: "Table N: Name", location line = location name + long-form local date-time ONLY when exactly one matching location row with numeric date exists (`table_location()` returns "" otherwise); entries count; flights count = MAX(flightNumber) (`number_of_flights()`) | `locationLine()`, header fields |
| 13 | Empty table → literal "No entries available." | view `@unless ($hasRows)` |
| 14 | Special-ingredients cell content beyond brewInfo: optional info, brewer specifics (`brewComments`), mead carbonation/sweetness/strength, allergens, ABV, sweetness-level JSON {OG,FG}, staff notes | `infoRows()` |

## Deliberate divergences from legacy

- **Column set**: ticket specifies entry #, judging #, style, brewer name,
  special ingredients. Legacy has no brewer-name column and does carry blank
  pull-order/score/place/box-number columns for handwriting. Re-add when the
  repo owner's visual side-by-side review asks.
- **Category line under style**: legacy appends the category display name via
  `style_convert(catSort, 1)` backed by the `style_sets` config arrays; the
  standalone build hardcodes "{group}{sub} {style name}" instead.
- **Language labels**: hardcoded English ("Required Info:", "Optional
  Info:", …) instead of `language.lang.php` lookups.
- **Packaging/pouring/juice-source info lines** omitted: they need the
  `packaging_display` language map and pouring JSON shapes; add alongside the
  label-port work.
- **Per-style queries collapsed** into one OR-grouped query per table
  (same rows, fewer round trips).
- **mini_bos / judging_scores_bos / all_entry_info modes** not ported here:
  separate outputs/tickets own them; this controller ports the default
  pull-sheet mode only.

## Test conventions for Slice D outputs

`tests/Feature/OutputPullSheetsTest.php` is the template: seed corpus-shaped
fixtures with class-unique prefixes ('P51%'), clean them in setUp AND
tearDown, gate assertions (anonymous + non-admin → `/?msg=99`), HTTP 200 +
`application/pdf` + `%PDF` magic bytes, then row-level assertions on the
builder payload.

## P5.2 pair D: maps, dropoff, print, results, bos_mat

Sources: `output/maps.output.php`, `output/dropoff.output.php` +
`lib/output.lib.php` dropoff helpers, `output/print.output.php`,
`output/results.output.php` (+ winners/bos sections),
`output/bos_mat.output.php` + `db/output_bos_mat.db.php`.

### Quirks mirrored

| # | Output | Quirk | Where mirrored |
|---|--------|-------|----------------|
| 1 | maps | NOT a document — a redirect to `maps.google.com/...&q=<address>` with spaces as `+`; the fancybox `&KeepThis=true` leftover is stripped with rtrim() on a CHARACTER LIST, so trailing letters from that list are eaten too ("Street" → "S") | `MapsController`, characterized in `OutputPairsDTest::test_maps_redirects_to_google_maps_with_the_address` |
| 2 | dropoff | Location 999 is the shipping pseudo-location (brewerDropOff=999), shown only when contestShippingAddress is set; counts join brewing.brewBrewerID → brewer.uid and count ALL of those brewers' entries, received or not — hence the legacy footnote about counts reflecting profile choice, not receipt | `DropoffController::entryCount()` |
| 3 | dropoff | go=default lists every configured location incl. zero counts; go=check skips empty locations and renders an empty "received" box cell per entry | controller mode switch, view |
| 4 | bos_mat | 2×3 tiles per letter page; tile header repeats the round title per tile; footer number = entry id when filter=entry else zero-padded judging number | view page flattening, `str_pad` |
| 5 | bos_mat | prefsWinnerMethod=0 labels tiles by judging table ("Table N: name"); >0 by category with style_convert-style subcategory expansion (port reads styles.brewStyleGroup→brewStyleNum CSV directly) | `labelByTable` / `subcats` map |
| 6 | bos_mat | Style-type id 4 merges the Mead+Cider rounds (`scoreType IN ('2','3')`); place filter per styleTypeBOSMethod via `Place::bosEligiblePlaces()` (explicit list — never legacy's string >=) | group builder |
| 7 | bos_mat | mini-bos action groups by judging table on `scoreMiniBOS='1'`; blank action prints one page of six empty mats; pro-am filters places by ?sort (no place restriction when sort is absent/unrecognized — verbatim) | controller actions |
| 8 | bos_mat | Brewer flagged brewerProAm=1 gets "** NOT ELIGIBLE FOR PRO-AM **" | view |
| 9 | results | Modes go=judging_scores / judging_scores_bos / best / all (default ordering: lead line, BOS, best brewer, winners); winners grouping follows prefsWinnerMethod 1=category 2=subcategory else flat — same shape as the public results block; data entirely from `ResultsRepository` | `ResultsController` |
| 10 | print | Legacy was an HTML chrome dispatching ?section= to other output modules plus one owned content: the contact card. Every wrapped module now has its own PDF endpoint, so the route keeps only contacts | class docblock |

### Deliberate divergences

- **maps**: ticket assumed a "location sheet"; source verified it is a
  redirect. Mirrored the redirect (stricter than legacy: admin-gated).
- **print**: renders all contacts ordered by last name when no ?id=
  (legacy printed only the FIRST row outside action=edit — wrapper
  artifact); email shown in plain text (JS obfuscation is meaningless in a
  server-rendered PDF).
- **bos_mat**: BA style-set tile variant (style_convert BA heading) not
  reproduced; subcategory expansion reads the styles table instead of
  porting every per-set switch table.
- **results**: best-club standings aggregated in the view from
  bestBrewers() club points (legacy bestbrewer.sec.php computes its own
  rollup); language labels hardcoded English.
- All four non-maps outputs are admin-gated like their legacy
  userLevel<=1 checks; maps gains a gate it never had (route-group
  consistency).

## P5.2 pair B: shipping_label, entry, judge_notes, assignments, scoresheets

Sources: `output/shipping_label.output.php`, `output/entry.output.php`,
`output/judge_notes.output.php`, `output/assignments.output.php` +
`db/output_assignments.db.php`, `output/scoresheets.output.php`.

### Quirks mirrored

| # | Output | Quirk | Where mirrored |
|---|--------|-------|----------------|
| 1 | shipping_label | Legacy renders the SAME label twice on one sheet (two half-sheet copies); label = brewery name (optional), name, address, city/state/zip, country line only when != "United States"; destination is contestShippingName + contestShippingAddress | view two-up block |
| 2 | judge_notes | Three ?go= variants: org_notes (brewerJudgeNotes rows), allergens (paid entries with brewPossAllergens + table/flight info), admin (rows where brewAdminNotes OR brewStaffNotes set — either one suffices) | controller mode switch |
| 3 | judge_notes | Round/flight breakdown printed only when jPrefsQueued == "N" | `$showFlight` |
| 4 | judge_notes | get_flight_info: first judging_flights row whose flightEntryID CSV contains the entry supplies table number/name + round/flight | `withPlacement()` |
| 5 | assignments | judging_assignments.bid references brewer.UID (not brewer.id) — assignment admin writes uid; roster joins brewer.uid and bull pen counts assignments per uid | both joins |
| 6 | assignments | assignRoles CSV codes HJ/LJ/MBOS rendered "Table Head Judge"/"Lead Judge"/"Mini-BOS Judge"; rank shows the FIRST brewerJudgeRank token plus Cicerone/Pommelier/pro-brewer certs found in the rank CSV | role map, rank builder |
| 7 | assignments | Bull pen: staff_judge/staff_steward signups with zero assignments in that role listed under "Bull Pen" on a fresh page | `whereNotExists` query |
| 8 | scoresheets | NOT bundling or generation — streams a SINGLE uploaded scoresheet PDF from USER_DOCS as an attachment download | ScoresheetsController |

### Deliberate divergences

- **entry**: legacy module is DEAD CODE — its template body was commented
  out in 2.7.0 (the live print became bottle_label via
  output.inc.php section=entry-form-multi). Port restores the deprecated
  intent: one paper-entry sheet per RECEIVED entry carrying the old
  template fields (entry/judging numbers %06s, style, brewer, special
  ingredients with ^ → " | ", mead trio, *** PAID *** marker).
- **shipping_label**: legacy was brewer-facing self-service (session
  fields); port is the admin batch variant — labels for every participant
  who has entries.
- **judge_notes**: legacy renders an empty page at go=default; port
  defaults to the org_notes variant.
- **assignments**: view=name/table/location sort variants, staff
  availability filter and sign-in sheets not ported; single canonical
  roster ordered by location, table, round, flight. Legacy's
  not_assigned() compared bid against BOTH uids and brewer ids across
  call sites; port standardizes on uid.
- **scoresheets**: no filename obfuscation / user_temp copy (that dance
  hid paths from brewer-facing URLs; pointless behind an admin route);
  ?file= is basename-clamped into public/user_docs, which ALSO closes a
  traversal hole legacy never checked. Per-entry subdirectory parameter
  (?view=) dropped.
