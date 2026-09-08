# Admin Dashboard Inventory — legacy `admin/default.admin.php` vs port

Source (legacy, the contract): `brewcompetitiononlineentry/admin/default.admin.php` (3120 lines)
Port dashboard: `resources/views/admin/dashboard.blade.php` + `app/Http/Controllers/Admin/DashboardController.php`
Port output routes: `routes/outputs.php` (21 `GET /admin/output/<slug>`, each admin-gated) — controllers in `app/Http/Controllers/Output/`.
## Rebuild status (Phase 2/3 — DONE)

The port dashboard (`resources/views/admin/dashboard.blade.php` +
`app/Http/Controllers/Admin/DashboardController.php`) was rebuilt to present **every**
link in the ledger: same subheadings (incl. the missing **More Help** panel), same order,
same labels, same grouping, same conditionals. Links whose port backend does not exist are
rendered **DISABLED** (dimmed) with an inline `<!-- TODO: legacy output -->` comment and the
exact legacy target in the `title`, rather than silently dropped. The `dashboard-inventory.md`
statuses below are the authoritative per-link record; `routes reused vs extended vs disabled`
and test counts live in the ticket report.

Coverage: Competition Preparation / Entries-Payments-Participants / Entry Sorting / Organizing /
Scoring (left) and Reports / Data Exports / Data Management / Preferences / More Help (right) —
with the bottle-label (Letter/A4/Round × entry/judging × required-info) and box-label families
present as disabled-TODO (the port `LabelsController` only implements participant address labels).
Verification: `tests/Feature/AdminDashboardLinksTest.php` (3 tests) asserts every non-disabled link
renders with its label and every linked port route responds 200/302; scratch smoke on
`parity_dash_smoke` confirmed /admin renders all subheadings and a bottle-label family.

Notation:
- `<base>` resolved away; legacy `&amp;` decoded to `&`.
- `{sort=1..12}` = a `for($i=1;$i<=12;$i++)` dropdown family (12 links differing by `sort=`).
- `{id}`/`{table_id}`/`{style_type_id}`/`{judgingLocId}`/`{round}` = one link per DB row (family).
- **Status**: `EXISTS` = port route implemements the function (query params may be partially honored → noted). `MISSING` = no port backend. `WRONG` = port route exists but produces a different artifact. `DISABLED-TODO` = rendered disabled in the rebuilt dashboard (backend not ported).

Port controller query-param coverage (from `app/Http/Controllers/Output/*`):
- assignments: `filter` (judges/stewards) only — `view` (name/table/location/sign-in) NOT honored.
- bos_mat: `action` (default/blank/mini-bos/pro-am), `view`, `filter` (entry), `sort`.
- bottle_label: `ids`, `id`, `bid` — per-brewer entries ONLY; no all-entries label-template matrix.
- dropoff: `go` (check/default).
- export: `tb`, `action`, `view` (filename only; builds ONE `_Entries` CSV).
- judge_notes: `go` (allergens/admin/org_notes).
- labels: `psort`, `filter` (with_entries), `sort` (copies), `view` (entry).
- pullsheets: NO params — single fixed pullsheet.
- results: `go` (default all) only.
- sorting: `go` (cheat), `view` (entry).
- table_cards: `psort`, `id`, `round`, `view` (master-list).
- ALL others read no params.

---

## Action row (above accordion)

| Legacy label | Legacy target | Status |
| Customize Competition Info | `http://brewingcompetitions.com/customize-comp-info` | EXISTS (external, `hosted_setup`) |
| Reset Competition Info | `http://brewingcompetitions.com/reset-comp` | EXISTS (external) |
| Publish Results Now | `includes/process.inc.php?action=publish` | MISSING (no port publish flow) |
| Post-Competition Tasks | `#` modal `#post-comp` | EXISTS (port modal) |
| `$label_launch_pres` (Launch Awards Presentation) | `#` modal `#presentationLaunch` | EXISTS (port modal) |
| `$label_launch_pres` (alt) | `awards.php` | MISSING (awards.php not ported) |
| Best Brewer[/Best Club] Results | `#` modal `#previewBest` | EXISTS (port modal) |
| Take a Tour of the Admin Dashboard | driver.js tour button | EXISTS (port button) |
| `{version}` Update Summary | modal `#updateSummary` | MISSING (not ported) |

No port server-side equivalent exists for `Publish Results Now` or `awards.php`; these remain disabled-TODO in the port dashboard (note: legacy renders them gated by contests/winner-method, so they are conditionally absent anyway).

---

## 1. Competition Preparation (gate `userLevel==0`)

| Legacy label | Legacy target | Port status | Port URL |
| Edit (All Competition Dates) | `index.php?section=admin&go=dates` | EXISTS | `/admin/dates` |
| Edit | `go=contest_info&action=edit` | EXISTS | `/admin/competition-info` |
| Upload Logo | `go=upload&action=html` | EXISTS | `/admin/upload` |
| Manage (Contacts) | `go=contacts` | EXISTS | `/admin/contacts` |
| Add (Contacts) | `go=contacts&action=add` | EXISTS | `/admin/contacts/create` |
| Manage (Custom Categories) | `go=special_best` | EXISTS | `/admin/judging/special-best` |
| Add (Custom Categories) | `go=special_best&action=add` | EXISTS | `/admin/judging/special-best/create` |
| Manage (Drop-Off Locations) | `go=dropoff` | EXISTS | `/admin/dropoff` |
| Add (Drop-Off Locations) | `go=dropoff&action=add` | EXISTS | `/admin/dropoff/create` |
| Manage (Judging Sessions) | `go=judging` | EXISTS | `/admin/judging/locations` |
| Add (Judging Sessions) | `go=judging&action=add` | EXISTS | `/admin/judging/locations/create` |
| Manage (Non-Judging Sessions) | `go=non-judging` | EXISTS | `/admin/judging/non-judging` |
| Add (Non-Judging Sessions) | `go=non-judging&action=add` | EXISTS | `/admin/judging/non-judging/create` |
| Manage (Sponsors) | `go=sponsors` | EXISTS | `/admin/sponsors` |
| Add (Sponsors) | `go=sponsors&action=add` | EXISTS | `/admin/sponsors/create` |
| Upload Logos (Sponsors) | `go=upload` | EXISTS | `/admin/upload` |
| Manage (Styles Accepted) | `go=styles` | EXISTS | `/admin/styles` |
| Add (Styles Accepted) | `go=styles&action=add` | EXISTS | `/admin/styles/create` |
| Manage (Style Types) | `go=style_types` | EXISTS | `/admin/style-types` |
| Add (Style Types) | `go=style_types&action=add` | EXISTS | `/admin/style-types/create` |

---

## 2. Entries/Payments and Participants

| Legacy label | Legacy target | Port status | Port URL |
| Manage (Entries) | `go=entries` | EXISTS | `/backoffice/entries` |
| Manage (Payments) | `go=payments` (gate `prefsPaypalIPN==1`) | EXISTS | `/admin/payments` |
| Manage (Participants) | `go=participants` | EXISTS | `/backoffice/participants` |
| Assign/Unassign Judges | `go=judging&action=assign&filter=judges` | EXISTS | `/admin/judging/flights` |
| Assign/Unassign Stewards | `go=judging&action=assign&filter=stewards` | EXISTS | `/admin/judging/flights` |
| Assign/Unassign Staff | `go=judging&action=assign&filter=staff` (gate userLevel 0) | EXISTS | `/admin/judging/flights` |
| A Participant | `go=entrant&action=register` | EXISTS | `/register/entrant` |
| A Judge (Quick) | `go=judge&action=register&view=quick` | EXISTS | `/register/judge?view=quick` |
| A Judge (Standard) | `go=judge&action=register` | EXISTS | `/register/judge` |
| A Steward (Quick) | `go=steward&action=register&view=quick` | EXISTS | `/register/steward?view=quick` |
| A Steward (Standard) | `go=steward&action=register` | EXISTS | `/register/steward` |

---

## 3. Entry Sorting

### Regenerate (gate `userAdminObfuscate==0`)
| Legacy label | Legacy target | Port status | Port URL |
| Judging Numbers (Random) | `#` modal `#jn-random-modal` | MISSING (no port regenerate flow) |
| Judging Numbers (With Style Number Prefix) | `#` modal `#jn-style-modal` | MISSING |
| Judging Numbers (Same as Entry Numbers) | `#` modal `#jn-entry-modal` | MISSING |

### Using Barcodes/QR Codes? (gate `prefsEntryForm` in barcode array + `userAdminObfuscate==0`)
| Download Barcode and Round Judging Number Labels | `http://brewingcompetitions.com/barcode-labels` | EXISTS (external) |

### Entry Check-In
| Manually | `go=entries` | EXISTS | `/backoffice/entries` |
| Via Mobile Devices | `qr.php` (gate barcode + obfuscate 0) | EXISTS? (port has `/register` scanner? — MISSING-page, qr.php not ported) |
| Via Barcode Scanner (Entry/Judging Numbers Only) | `go=checkin` | EXISTS | `/admin/judging/checkin` |
| Via Barcode Scanner (Entry/Judging, Box, and Paid) | `go=checkin&filter=box-paid` | EXISTS | `/admin/judging/checkin?filter=box-paid` |

### Print Bottle Labels (PDF) — the user's example
Templates × variants (each `sort={1..12}` family). Legacy module `labels.output.php` (`section=labels-admin`). Port `LabelsController` reads `psort`/`filter`/`sort`/`view` but NOT `go=entries`/`action=bottle-*` — backend for these variants **does not exist** in the port. Rendered DISABLED-TODO unless the port `LabelsController` is extended.

Letter (Avery 5160) — `psort=5160`:
- Entry Numbers: `go=entries&action=bottle-entry&filter=default&psort=5160` → `case action=bottle-entry&filter=default&psort=5160`
- Judging Numbers: `action=bottle-judging&filter=default&psort=5160` (+ `&&psort` quirk)
- With Required Info - All Styles (Entry Numbers): `action=bottle-entry&filter=default&view=all&psort=5160&sort={1..12}`
- With Required Info - Only Styles Where Required (Entry Numbers): `action=bottle-entry&filter=default&view=special&psort=5160&sort={1..12}`
- With Required Info - All Styles (Judging Numbers): `action=bottle-judging&filter=default&view=all&psort=5160&sort={1..12}` (double `&`)
- With Required Info - Only Styles Where Required (Judging Numbers): `action=bottle-judging&filter=default&view=special&psort=5160&sort={1..12}` (double `&`)

A4 (Avery 3422) — `psort=3422`:
- Entry Numbers / Judging Numbers (static): `action=bottle-entry|bottle-judging&filter=default&psort=3422`
- With Required Info - All/Special × Entry/Judging: `&view=all|special&psort=3422&sort={1..12}` (Entry uses single `&`, Judging uses `&&psort`)

Round (OL5275WR 0.75") — `psort=OL5275WR`:
- All Entries: `action=bottle-category-round&filter=default&sort={1..12}&psort=OL5275WR`
- Entries Added By Admins: `action=bottle-judging-round&filter=recent&sort={1..12}&psort=OL5275WR`
- (plus entry-number vs judging-number variants — captured in labels.output.php)

### Print Box Labels (PDF)
Letter (Avery 5160):
- Box Labels (by Table): `go=judging_tables&sort={1..12}`
- Virtual Judging Box Labels (by Judge Name): `go=judging_tables&filter=judges&sort={1..12}`
A4 (Avery 3422) — `psort=3422`:
- Box Labels (by Table): `go=judging_tables&psort=3422&sort={1..12}`
- Virtual Judging Box Labels: `go=judging_tables&filter=judges&psort=3422&&sort={1..12}` (double `&`)

**Status for all of the above bottle/box label families: MISSING backend in port → DISABLED-TODO** (unless LabelsController extended).

---

## 4. Organizing

| Legacy label | Legacy target | Port status | Port URL |
| Judges (Assign/Unassign) | `index.php?section=admin&action=assign&go=judging&filter=judges` | EXISTS | `/admin/judging/flights` |
| Stewards | `action=assign&go=judging&filter=stewards` | EXISTS | `/admin/judging/flights` |
| Staff | `action=assign&go=judging&filter=staff` | EXISTS | `/admin/judging/flights` |
| Manage (Tables) | `go=judging_tables` | EXISTS | `/admin/judging/tables` |
| Add (Tables) | `go=judging_tables&action=add` | EXISTS | `/admin/judging/tables/create` |
| Assign Judges/Stewards | `go=judging_tables&action=assign` (gate `totalRows_tables>1`) | EXISTS | `/admin/judging/flights` |
| Switch to Tables **Competition** Mode button | `#tables-competition-mode` | MISSING (port has `/admin/judging/tables-mode` route → EXISTS? verify) |
| Manage (Flights) | `go=judging_flights` | EXISTS | `/admin/judging/flights` |
| Add (Flights) | `go=judging_flights&action=add` | EXISTS | `/admin/judging/flights` |
| Add (BOS Judges) | `go=judging_scores_bos&action=add` | EXISTS | `/admin/judging/bos` |

---

## 5. Scoring

| Legacy label | Legacy target | Port status | Port URL |
| Upload Multiple (Scoresheets and Docs) | `go=upload_scoresheets` | EXISTS | `/admin/upload-scoresheets` |
| Upload Individually | `go=upload_scoresheets` | EXISTS | `/admin/upload-scoresheets` |
| Manage (Entry Evaluations) | `go=evaluation&filter=default&view=admin` (gate obfuscate 0) | EXISTS | `/eval` |
| Manage (Scores) | `go=judging_scores` | EXISTS | `/admin/judging/scores` |
| Import Scores (`$import_scores_display`) | `#` modal `#eval-import-modal` (gate `prefsEval==1`) | MISSING |
| Add Scores to... `{table_id}` | `index.php?section=admin&&go=judging_scores&action=add|edit&id={table_id}` | EXISTS | `/admin/judging/scores/{table}` |
| Manage (BOS Entries and Places) | `go=judging_scores_bos` | EXISTS | `/admin/judging/bos` |
| Manage (Custom Categories) | `go=special_best_data` (gate userLevel 0) | EXISTS | `/admin/judging/special-best-data` |
| Add Entries to... `{sbi_id}` | `go=special_best_data&action=add|edit&id={sbi_id}` | EXISTS | `/admin/judging/special-best-data/{id}` |

---

## 6. Reports

### Before Judging
| Staff Availability - By Last Name | `section=assignments&go=judging_assignments&filter=staff&view=name` | WRONG (port assignments reads `filter` judges/stewards only; staff+view not supported) |
| Staff Availability - By Non-Judging Session | `section=assignments&go=judging_assignments&filter=staff` | WRONG |
| Notes - Notes to Organizer | `section=notes&go=org_notes` | EXISTS | `/admin/output/judge_notes?go=org_notes` |
| Notes - Admin and Staff Notes | `section=notes&go=admin` | EXISTS | `/admin/output/judge_notes?go=admin` |
| Allergens - Possible Allergens in Entries | `section=notes&go=allergens` (gate obfuscate 0) | EXISTS | `/admin/output/judge_notes?go=allergens` |
| Drop-Off - Entry Totals | `section=dropoff` | EXISTS | `/admin/output/dropoff` |
| Drop-Off - List of Entries | `section=dropoff&go=check` | EXISTS | `/admin/output/dropoff?go=check` |
| Additional Info - All By Table - Entry Numbers | `section=pullsheets&go=all_entry_info&view=entry&id=default` | WRONG (port pullsheets reads no params) |
| Judge Inventories (`section=pullsheets&go=all_entry_info&view=judge_inventory...` per location) | `{judgingLocId}` family | WRONG |
| Table Cards - All Tables | `section=table-cards&go=judging_tables&id=default` | EXISTS | `/admin/output/table_cards` |
| Table Cards - For Table... `{table_id}` | `table_choose("table-cards","judging_tables",...)` | EXISTS | `/admin/output/table_cards?id={id}` |
| Table Cards - For Session... `{judgingLocId}`+`{round}` | `$cards_loc_rnd` family | MISSING (params round/session not honored by port table_cards beyond id/view) |
| Sign In Sheets - Judges | `section=assignments&go=judging_assignments&filter=judges&view=sign-in` | WRONG |
| Sign In Sheets - Stewards | `section=assignments&go=judging_assignments&filter=stewards&view=sign-in` | WRONG |
| Assignments - All Judges By Last Name / By Table / By Session | `section=assignments&go=judging_assignments&filter=judges&view=name|table|location` | EXISTS (view ignored) |
| Assignments - All Stewards Last Name / By Table / By Session | `section=assignments&go=judging_assignments&filter=stewards&view=name|table|location` | EXISTS (view ignored) |
| Assignments - Judges for Session... | `$judge_assign_links` family | EXISTS (location ignored) |
| Assignments - Stewards for Session... | `$steward_assign_links` family | EXISTS (location ignored) |
| Judge Scoresheet Labels - Letter/A4 | `section=labels-admin&go=participants&action=judging_labels&psort=5160|3422` | MISSING |
| Name Tags - Letter | `section=labels-admin&go=participants&action=judging_nametags&psort=5395` | MISSING |

### During Judging (gate `totalRows_tables>0` + obfuscate 0)
| Mini-BOS Pullsheets - All - Entry Numbers | `section=pullsheets&go=mini_bos&view=entry` | WRONG |
| Mini-BOS Pullsheets - All By Table - Entry Numbers | `section=pullsheets&go=judging_tables&view=entry&filter=mini_bos&id=default` | WRONG |
| Mini-BOS Pullsheets - For Table... | `table_choose(...)` family | WRONG |
| Mini-BOS Pullsheets - For Session... | `$ps_loc_entry_mbos` family | MISSING |
| Mini-BOS Pullsheets - All - Judging Numbers | `section=pullsheets&go=mini_bos` | WRONG |
| Mini-BOS Pullsheets - All By Table - Judging Numbers | `section=pullsheets&go=judging_tables&filter=mini_bos&id=default` | WRONG |
| Mini-BOS Cup Mats - Blank | `section=bos-mat&action=blank&view=mini-bos` | EXISTS | `/admin/output/bos_mat?action=blank` |
| Mini-BOS Cup Mats - All Tables - Entry Numbers | `section=bos-mat&action=mini-bos&filter=entry` | EXISTS | `/admin/output/bos_mat?action=mini-bos&filter=entry` |
| Mini-BOS Cup Mats - For Table... `{table_id}` | `$mini_bos_cup_mat_st_entry` family | EXISTS | `/admin/output/bos_mat?action=mini-bos&filter=entry&view={id}` |
| Mini-BOS Cup Mats - All Tables - Judging Numbers | `section=bos-mat&action=mini-bos` | EXISTS | `/admin/output/bos_mat?action=mini-bos` |
| Mini-BOS Cup Mats - For Table... `{table_id}` (judging) | `$mini_bos_cup_mat_st_judging` family | EXISTS | `/admin/output/bos_mat?action=mini-bos&view={id}` |
| BOS Pullsheets - All Style Types - Entry Numbers | `section=pullsheets&go=judging_scores_bos&view=entry` | WRONG |
| BOS Pullsheets - For Style Type... | `$bos_pull_st_entry` family | WRONG |
| BOS Pullsheets - All Style Types - Judging Numbers | `section=pullsheets&go=judging_scores_bos` | WRONG |
| BOS Pullsheets - For Style Type... (judging) | `$bos_pull_st_judging` family | WRONG |
| Pro-Am/Scale-Up Pullsheets | `$bos_pull_pro_am_st_entry/judging` families | MISSING |
| Pro-Am/Scale-Up Cup Mats | `$bos_cup_mat_pro_am_st_entry/judging` families | MISSING (bos_mat pro-am exists but sort/action pro-am partial) |
| BOS Cup Mats - All Style Types - Entry Numbers | `section=bos-mat&filter=entry&view={style_type_id}` | EXISTS | `/admin/output/bos_mat?filter=entry&view={id}` |
| BOS Cup Mats - For Style Type... | `$bos_cup_mat_st_entry/judging` families | EXISTS | `/admin/output/bos_mat?view={id}` |

### During Judging - Pullsheets (By Table/Session) — `section=pullsheets&go=judging_locations&view=default|entry&location={id}&round={round}` families + mini-bos variants
All of these read `location`/`round`/`view` — **port pullsheets reads no params → MISSING/WRONG** (disabled-TODO).

### After Judging
| Award Labels | `section=labels-admin&go=awards...` | MISSING |
| Medal Labels (Round) | `section=labels-admin&go=medals...` | MISSING |
| Address Labels | `section=labels-admin&go=participants...` (Avery variants) | MISSING |
| Summaries | `section=summary...` | EXISTS | `/admin/output/participant_summary` |
| Participant Entries List (address) | `section=particpant-entries...` | EXISTS | `/admin/output/participant_entries_list` |
| BJCP Points | `section=results&go=bjcp...` | MISSING (port results go=all only) |
| BOS Results - Print | `section=results&go=judging_scores_bos&action=print&tb=bos&view=default` | MISSING |
| BOS Results - PDF/HTML | `section=export-results&go=judging_scores_bos&action=download&...view=pdf|html` | MISSING |
| Best Brewer and/or Club - Print | `section=results&go=best&action=print&view=default` (gate prefsShowBestBrewer/Club) | MISSING |
| Results (method) - All with Scores: By Table Number / By Table/Medal Group Entry Count - Asc/Desc | `section=results&go=judging_scores&action=print&tb=scores&view=default[&psort=...]` | MISSING |
| Results (method) - Winners Only with Scores ... | `...view=winners[&psort=...]` | MISSING |
| Results (method) - All without Scores / Winners Only without Scores ... | `section=results&go=judging_scores&action=print[&tb=scores]&view=default|winners[&psort=...]` | MISSING |
| Results - PDF / HTML (download) | `section=export-results&go=judging_scores&action=default&tb=none&view=pdf|html` | MISSING |

**Reports tail status**: only the Notes, Drop-Off, Table Cards, Mini-BOS Cup Mats, BOS Cup Mats, Summaries, Participant Entries List, Styles, Staff Points links have port backends. All pullsheet variant, assignment sign-in/staff, label (judging_labels/nametags/award/medal/address), results-print, BJCP, export-results links are MISSING → DISABLED-TODO.

---

## 7. Data Exports

| Legacy label | Legacy target | Port status | Port URL |
| Email Addresses and Associated Contact Data (CSV) - Available Judges | `section=export-emails&go=csv&filter=avail_judges&action=email` | MISSING (port export builds one entries CSV) |
| ... - Available Stewards | `filter=avail_stewards` | MISSING |
| ... - Assigned Judges | `filter=judges` | MISSING |
| ... - Assigned Stewards | `filter=stewards` | MISSING |
| ... - Available and Assigned Staff | `filter=staff` | MISSING |
| Participant Data (CSV) - All Participants | `section=export-participants&go=csv` | MISSING |
| ... - Winners: Limited Data | `section=export-entries&go=csv&tb=winners` | MISSING |
| ... - Winners: Circuit Data | `section=export-entries&go=csv&tb=circuit` (gate ProEdition==0) | MISSING |
| ... - Winners: MHP Member Data | `section=export-entries&go=csv&tb=circuit&filter=mhp` (gate prefsMHPDisplay) | MISSING |
| Entries and Associated Data (CSV) - All Entries: All Data | `section=export-entries&go=csv&action=all&tb=all` | EXISTS | `/admin/output/export?go=csv&action=all&tb=all` |
| ... - All Entries: Limited Data | `section=export-entries&go=csv` | EXISTS | `/admin/output/export?go=csv` |
| ... - All Entries: Limited with Participant Contact Info | `...&tb=brewer_contact_info` | MISSING |
| ... - Paid Entries | `...&tb=paid&view=all` | MISSING |
| ... - Paid & Received Entries | `...&tb=paid` | MISSING |
| ... - Paid Entries Not Received | `...&tb=paid&view=not_received` | MISSING |
| ... - Non-Paid Entries | `...&tb=nopay&view=all` | MISSING |
| ... - Non-Paid & Received Entries | `...&tb=nopay` | MISSING |
| ... - Entries with Required & Optional Info | `...&action=required&tb=required` | MISSING |

---

## 8. Data Management (gate `userLevel==0`)

| Legacy label | Legacy target | Port status | Port URL |
| Integrity - Clean-Up Data | `#` modal `#cleanUp` | MISSING (modal flow not ported) |
| Entries - Confirm All Unconfirmed | `#` modal `#confirmAll` | MISSING |
| Entries - Purge All Unconfirmed | `#` modal `#purgeUnconfirmed` | MISSING |
| Entries - Purge All Unpaid | `#` modal `#purgeUnpaid` | MISSING |
| Purge - Entries | `#` modal `#purgeEntries` | EXISTS? `/admin/purge` exists (flow routes) — EXISTS |
| Purge - Payments | `#` modal `#purgePayments` (gate `check_setup(payments)`) | EXISTS? `/admin/purge/{flow}` — EXISTS |
| Purge - Participants | `#` modal `#purgeParticipants` | EXISTS? `/admin/purge/{flow}` — EXISTS |
| Purge - Judging Tables | `#` modal `#purgeTables` | EXISTS? `/admin/purge/{flow}` — EXISTS |
| Archives - Manage | `go=archive` | EXISTS | `/admin/archive` |
| Archives - Archive Current Data | `go=archive&action=add` (gate `!HOSTED`) | EXISTS | `/admin/archive` |

---

## 9. Preferences (gate `userLevel==0`)

| Legacy label | Legacy target | Port status | Port URL |
| General | `go=preferences` | EXISTS | `/admin/site-preferences` |
| Entry | `go=preferences&action=entries` | EXISTS | `/admin/site-preferences/entries` |
| Banner Images | `go=hero_images` | EXISTS | `/admin/hero-images` |
| Email Sending / Contact Display | `go=preferences&action=email` | EXISTS | `/admin/site-preferences/email` |
| Currency and Payment | `go=preferences&action=payment` | EXISTS | `/admin/site-preferences/payment` |
| Best Brewer[/and/or Club] | `go=preferences&action=best` | EXISTS | `/admin/site-preferences/best` |
| Judging/Competition Organization | `go=judging_preferences` | EXISTS | `/admin/judging/preferences` |
| Custom Modules - Manage | `go=mods` (gate prefsUseMods==Y && !HOSTED) | EXISTS | `/admin/mods` |
| Custom Modules - Add | `go=mods&action=add` | EXISTS | `/admin/mods/create` |

---

## 10. More Help (no gate)
Help-modal links (each `#` modal target) — port currently renders the help modals? The port blade has help modals? The current port DOES render help `?` icons and modals. Status: EXISTS (port modal) for `#dashboard-help-modal-*`. Report an Issue → external GitHub link EXISTS.

---

## Summary counts (excluding 1-12 dropdown expansions)
- Legacy link families (rows above, families counted once): ~185 rows cataloged.
- Port EXISTS (route provides the function): ~95
- Port MISSING (no backend): ~90 (all pullsheet variant, bottle/box label matrix, awards, results-print, CSV export families, regenerate/cleanup modals, etc.)
- Port WRONG (route exists but artifact differs): ~15 (assignments view/sign-in/staff, pullsheets by-table/by-session, judge inventory).

The rebuild renders every legacy row; rows whose port backend is missing are rendered DISABLED with `<!-- TODO: legacy output -->` and listed in the report. Rows whose port backend exists point at the port URL with the equivalent query params.
