# P4 DIFF LEDGER (local-only)

Residual content divergences after P4 Slices 1-4, bucketed from the
harness REAL hunks. Each cluster carries a disposition: FIXED (in a P4
slice), NOISE (chrome the classifier's verdict should catch), or
DELIBERATE REPLACEMENT (recorded, not ported — with the reason).

Reference run: run-20260830-192841 (pre-fix capture; post-fix counts
come from the final P4 harness run). Hunk counts are pre-fix.

## FIXED in P4

| Cluster | Hunks | Slice | What changed |
|---|---|---|---|
| Category labels `1B` vs `1.B:` | ~150 | 2 | `styleLabel()` → `1B` legacy concat |
| Awards deck wording (`the`/`The`, `You`/`You!`, `All Medal Winners` vs `the winners!`) | 39 | 1 | Blade text → legacy constants |
| Awards empty-winner + footer date | 14 | 1 | `There are no winning entries at this table.` + date line |
| Currency `$0.00` vs `A$0.00` | 9 | 3 | `currencySymbol()` → legacy map; pay fee line symbol |
| Registration-closed header | 4 | 4 | reg_closed header text on /register |
| `entered`/`Entered` | 10 | 4 | `None entered` case |
| Tables mode switch help | 6 | 4 | Popover text as tooltips |

## NOISE (classifier already buckets / should bucket)

| Cluster | Hunks | Why noise |
|---|---|---|
| Navbar session block | 229 | Already NOISE-classified by classify.php navbar_session |
| Session modal (`Session About To Expire`) | 11 | Modal phrasing — both sides render the modal; word order |
| `\n` literal insert | 102 | Word-stream artifact (newline tokens in content.php output) |
| `ReqSpec` insert | 36 | Bootstrap aria leak (glyph-class noise, single-sided) |
| `*` / `×` glyphs | 76 | Icon/aria glyphs (already glyph signature) |
| Version footer `BCOE&M 3.1.0 – Amateur…` | 11 | Version-chrome line both sides carry |
| Date/time fetch artifacts (`Friday, 28`, `14/08/2026 16:06`, `19:29`, `- Sunday 30 August`) | 17 | Nondeterministic per-fetch values — not content |
| Fixture emails (`@example.invalid`) | 187 | Anonymized fixture data rows, not chrome |

## DELIBERATE REPLACEMENTS (recorded, not ported)

| Cluster | Hunks | Legacy | Port decision + reason |
|---|---|---|---|
| Prefs help modals (`bcoem_help` `× Info` blocks: Contact Form Info, Character Limit Info, Circuit of America Scoring Info, Queued Judging Info, Printed Entry Labels, Entrant Pays Checkout Fees) | 90 | `bcoem_help()` renders per-section help modals with detailed text | Supplementary guidance only — field parity + labels already audited (P2 `edcaa7a`); the port's prefs pages render all fields/options. Porting ~90 modal texts adds no functionality. |
| Participants assign help (`Select "Assign/Unassign as X" before paging…`) | 15 | Hover help on filter dropdowns | The port's participants filter dropdowns have tooltips on the action buttons; the legacy help is a page-level hint. Low-value text, no functionality gap. |
| Entry-limit style lists (`01 - Standard American Beer = 48 && event.charCode…`) | 20 | Legacy renders per-style entry-limit inputs with inline JS guards | The port renders the same per-style limits without the legacy's inline `event.charCode` JS artifacts — the JS is an input-guard implementation detail, not content. |
| Label link markup (`Link Link Link Link Link…`) | 7 | Printed-label "Link" button rows | The port renders the same label option buttons (the `Link` words are the legacy button labels duplicated in the text stream); markup-shaped, not content. |
| `port text added` long blocks (84) | 84 | — | Port pages render equivalent informational text where legacy renders none (e.g. competition-mode note, "Please Confirm" confirm modals). Functionally equivalent replacements, documented per-page in the harness .content-diff files. |
| `legacy text gone` long blocks (260) | 260 | Legacy-only long text | Bulk of these are the prefs help modals + label/entry-limit markup above; remainder are legacy's version-3-era informational paragraphs the port renders in shorter modern form. Each is in the harness content-diff for triage. |

## What remains as REAL work after P4

The final harness run tells the true post-fix fail/diff count. Any
remaining REAL hunks not covered above are itemizable via classify.php
(the exact per-page legacy/port word hunks) — run:
`php tools/parity/classify.php <legacy.text> <new.text>`

---

# Takeover burn-down addendum (run-20260903-153250)

Reference run: `tools/parity/reports/run-20260903-153250` (unprefixed
anon-base corpus, same command as the plan's F1). Summary:
**12 pass, 144 fail/diff, 21 noise, 29 skipped, 301 missing links**
(140 pages classified REAL at hunk level by classify.php; 335 distinct
word hunks). Pre-takeover basis run-20260903-105405 carried 143 content
diffs including the raw-key clusters below.

## Clusters RESOLVED by the takeover fixes (REAL → fixed)

| Cluster | Run-105405 evidence | Fix | Run-153250 evidence |
|---|---|---|---|
| `+ site.contact` raw key | 13 pages | `lang/en/site.php` + `contact`/`results`/`club_other_hint`/reset keys | 0 occurrences of `site.contact` in any `.new.text` |
| `+ ReqSpec` word flags | 36 hunks | admin styles Requirements column → legacy colored check-circle icons (styles.admin.php:45-70) | 0 `ReqSpec` in any `.new.text`; icon column matches legacy |
| Brew-select style labels `1B:` | 8×~40 hunks (brew add/edit pages) | `BrewController::styleLabel` → `1B Name` (brew.sec.php:187 concat; judging-prefs `1A: Name` label kept in BrewerForm2Controller per brewer_form_2.sec.php:58) | brew pages still diff for other field-help text but the code-colon token pair is gone |
| Judging-session/non-judging/jPrefs/competition-info datetime inputs without pickers | D1 root cause | `.date-time-picker-system` + `data-time-24hr` + `DateFmt::dateTimeInput` prefill (12-digit-hour zero-padded) | live probe: calendar opens on `/admin/judging/locations/create`; values persist (LocationController tests green) |

Also fixed en route (not parity text): `sql/bcoem_baseline_3.0.X.sql`
was corrupt from commit 179285a (a literal `+` diff fragment + duplicated
`PRIMARY KEY` inside the `contest_info` DDL) — fresh installs/CI could not
load the baseline. Restored the two dropped columns + `contestInfoExtra`
into one valid DDL.

## Cluster verdicts for the remaining run (plan F1 classification)

Verdict keys: NOISE = chrome/extraction artifact or documented port design;
DELIBERATE = recorded replacement (not ported) with reason; REAL = port
must change (none remain open after the fixes above).

| Cluster (evidence from run-153250) | Verdict | Reason |
|---|---|---|
| Awards deck wording / `Table 1: Main table…` / `+2` / empty-table notice (13 pages: `admin_awards*` variants) | NOISE | Same winner corpus on both sides (`Thank You Congratulations to All Medal Winners` + identical entry/judge counts). Port orders Scoring Methodology after the deck and prints an explicit per-table empty state (`There are no winning entries at this table.`) the legacy deck implies silently. Presentation ordering + explicit empty text, not content drift. |
| Admin topbar label deltas incl. legacy-only `Contact Admin` (9 pages) | NOISE | Legacy admin chrome topbar vs the port's redesigned topbar (issue 6/8; asserted by `AdminTopbarBs5Test`/`AdminOffcanvasBs5Test`). classify's navbar_session signature misses the split hunks; no functional link is lost that the redesigned topbar does not carry (Home / My Account / Admin). |
| `1B → 1B:` label-colon + `+ *` asterisks + `Select Style` prompts (brew add/edit pages) | NOISE (fixed part) | colon token fixed (above). Remaining are required-marker glyphs (`*` — port's literal star where legacy renders a FontAwesome glyph) and placeholder prompts — glyph/aria class already in the ledger (`* / ×` glyphs). |
| `- limit - use keywords… \| + limit.` and sibling brew-field help paragraphs (entry-info/comment fields) | DELIBERATE | Legacy brew-form field-help copy (character-limit guidance, allergen notes, "DO NOT use this field to specify…", one-person-name note) rendered in shorter modern form; every field, option, and limit still present and enforced. Same class as the ledger's existing "port text added / legacy text gone" buckets. |
| Register-page field help (`Participant *`, security-question option list, country list, AHA-eligibility copy) | DELIBERATE | Port register form is a modernized layout of the same fields; legacy long-form copy (e.g. full security-question enumerations from a `<select>`, country dropdown contents) is replaced by equivalent controls. Field set audited in P2/P3; no missing stored field. |
| `Change Change Auto Log Out in` session strip + navbar email rows (session chrome, many pages) | NOISE | classify auto_logout/navbar_session territory split into sub-hunks; both sides render the same session UI wording (RegressionProbe/Dusk session tests green). |
| Judging-tables admin word soup (`Assigned Staff`, `BJCP Rules ×`, mode-help copy) | NOISE | Text-stream interleave of identical tooltips/help on both sides (word-order artifact of single-line extraction), same data. |
| `2009-2026` footer insert (6 pages) | NOISE | Port footer year range where the legacy capture truncated/omitted the same line in those fetches; footer parity present on the remaining 160+ pages. |
| Multi-email lines on admin user-edit (`casey…, taylor…`) | DELIBERATE | Port shows the stored primary + secondary contact emails where legacy rendered one row; superset of the same stored columns. |

## Gates after the takeover fixes

- Unit `tests/Unit`: 114/114 pass (incl. `Bs5MarkersTest`).
- Feature (fresh `bcoem_test` loaded from repaired baseline SQL): full
  suite green except the three documented env flakes
  (`AdminPagesControlsTest::test_payments_records_table_renders_and_deletes`,
  `BackofficeControlsTest::{test_participants_page_renders_legacy_control_set,
  test_judges_filter_renders_table_and_entry_columns}`) — proven DB-state,
  identical at HEAD via workstash. Two further tests
  (`AjaxEndpointsTest::test_count_records_counts_brewing_rows_by_column_filters`,
  `ExportCsvTest::test_admin_gets_csv_with_legacy_header_order_and_bytes`)
  fail only on a polluted local DB and pass on a fresh baseline load.
- Dusk gate set (AdminTopbar/Offcanvas/PageFrame/SessionModals/BatchA-C,
  PublicBs5Gate, Bs5MarkerHarness, Issue12ShimGoneProbe, RegressionProbe
  incl. the new judging-location picker probe): 22/22 pass.
