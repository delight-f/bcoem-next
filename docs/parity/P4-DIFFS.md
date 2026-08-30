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
