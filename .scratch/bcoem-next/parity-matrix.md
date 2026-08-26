# Parity DIFF triage matrix — run-20260826-213414

Source run: `tools/parity/reports/run-20260826-213414/` (5 PASS, 45 DIFF, 18 skipped).
Classes: **A** broken function · **B** structural (section/control missing) · **C** styling/copy · **H** harness-noise or bad pairing.

## Counts

| Role | A | B | C | H | total |
|---|---|---|---|---|---|
| anon | 0 | 0 | 2 | 0 | 2 |
| entrant | 0 | 4 | 0 | 0 | 4 |
| admin | 12 | 12 | 12 | 3 | 39 |
| **total** | **12** | **16** | **14** | **3** | **45** |

Ignored as universal noise everywhere: hostnames `127.0.0.1:8091` vs `:8092`, CSRF tokens, session ids, rotating hero images.

## Anon

| legacy URL | port URL | class | what's missing/wrong | legacy source |
|---|---|---|---|---|
| index.php?section=register&go=judge | /register/judge | C | Heading "Judge Registration" vs port "Register"; body identical ("Registration has closed…", no form either side) | sections/register.sec.php |
| index.php?section=register&go=steward | /register/steward | C | Heading "Steward Registration" vs port "Register"; body identical | sections/register.sec.php |

## Entrant

| legacy URL | port URL | class | what's missing/wrong | legacy source |
|---|---|---|---|---|
| index.php?section=brew&action=add | /brew | B | FIXED (commit "fix(public): entrant pages parity — brew gate, list buttons/cards, pay account surface"): closed-window render gate — entrants get only the "Adding and editing of entries is not available." lead (no form), admins keep the form (brew.sec.php:112) | sections/brew.sec.php |
| index.php?section=brewer&action=account | /list/edit-account | B | FIXED (commit "fix(public): entrant pages parity — brew gate, list buttons/cards, pay account surface"): ownership gate (brewer.sec.php:86/:370) — non-owners get only the "You can only edit your own profile." lead, no form | sections/brewer.sec.php |
| index.php?section=list | /list | B | FIXED (commit "fix(public): entrant pages parity — brew gate, list buttons/cards, pay account surface"): Add Entry + Change Password buttons, Entries info cards (Bottles Required Per Entry, Entry Edit Deadline, Confirmed/Unpaid counts), legacy labels (Address, AHA Member Number, Entry Delivery), long-style date with timezone | sections/brewer_entries.sec.php |
| index.php?section=pay | /pay | B | FIXED (commit "fix(public): entrant pages parity — brew gate, list buttons/cards, pay account surface"): full account/entries surface shared with /list (account-main partial) + PayPal "Return to Merchant"/"About to Leave" confirmation modal | sections/pay.sec.php |

## Admin

| legacy URL | port URL | class | what's missing/wrong | legacy source |
|---|---|---|---|---|
| index.php?section=admin | /admin | A | Dashboard omits "Publish Results Now" + "Launch Awards Presentation" controls and Best Brewer/Club "View Scoring Methodology / Print Results / Edit Settings" block (renders only "No results are available yet.") | admin/default.admin.php |
| index.php?section=admin&go=dates | /admin/dates | B | Missing Non-Judging Sessions section and "Awards Ceremony Date and Time" field; "Results Display Date" renamed "Winners Display Date" | admin/all_dates.admin.php |
| index.php?section=admin&go=contest_info&action=edit | /admin/competition-info | C | "Additional Club Names" search/add helper replaced by plain "Homebrew Clubs" semicolon list; QR Code Log On shown as "Entry Check-In Password"; legacy "Entry Information has moved to Entry Preferences" note absent | admin/competition_info.admin.php |
| index.php?section=admin&go=contacts | /admin/contacts | C | Rows/Edit/Delete/"Add a Contact" present; "Contact Help" modal text missing | admin/contacts.admin.php |
| index.php?section=admin&go=contacts&action=add | /admin/contacts/create | C | Add form intact; "Contact Help" modal missing; "View All Contacts" rendered as breadcrumb not link | admin/contacts.admin.php |
| index.php?section=admin&go=dropoff | /admin/dropoff | C | Button + empty state present; "Drop-Off Locations Help" modal missing; "specified" vs "defined" wording | admin/dropoff.admin.php |
| index.php?section=admin&go=sponsors | /admin/sponsors | A | "Upload Sponsor Logo Images" action missing (only "Add a Sponsor" shown) | admin/sponsors.admin.php |
| index.php?section=admin&go=styles | /admin/styles | C | Full checklist present; port adds Requirements column values (ReqSpec/Carb Sweet/Strength) + "Accept" column; legacy "Restrict Entries … override any table-level restriction" note absent | admin/styles.admin.php |
| index.php?section=admin&go=style_types | /admin/style-types | C | "BOS Enabled?" renders escaped literal markup (`<span class="text-success">Yes</span>`) instead of styled Yes/No — double-escaping bug | admin/style_types.admin.php |
| index.php?section=admin&go=mods | /admin/mods | C | "Add a Custom Module" present; explanatory description block missing | admin/mods.admin.php |
| index.php?section=admin&go=archive | /admin/archive | C | Archive form exposed inline vs legacy "Archive Current Data" button + "Archives Help" modal; layout/wording | admin/archive.admin.php |
| index.php?section=admin&go=user | /admin/purge | H | Bad pairing: legacy go=user renders sections/user.sec.php ("You can only edit your own account information"), not a purge page; port /admin/purge is the data-purge feature. urls.txt pairs unlike functions | sections/user.sec.php |
| index.php?section=admin&go=send_test_email | /admin/send-test-email | H | Legacy go=send_test_email has no dispatch branch (empty admin body); real feature lives in admin/send_test_email.admin.php (fancybox iframe), which port implements correctly — blank legacy capture is harness-side | admin/send_test_email.admin.php |
| index.php?section=admin&go=entries | /backoffice/entries | A | FIXED (commit "feat(backoffice): participants + entries legacy control set"): participant jump select ("Add an Entry For...", navigates to the bid-filtered entries view — legacy opened an admin add-entry form the port does not have), Print dropdown rendered with items disabled pending a port output route, Admin Actions mark-all dropdown live as POSTs to /backoffice/entries/mark-all (paid msg=20 / unpaid 34 / received 21 / not-received 35 / confirmed 22, whole-table update like process_brewing.inc.php:991), All Entry Status modal with confirmed/unconfirmed/paid/unpaid/received counts and fee totals, and all three copy/paste email modals populated from brewing⋈brewer | admin/entries.admin.php |
| index.php?section=admin&go=participants | /backoffice/participants | A | FIXED (commit "feat(backoffice): participants + entries legacy control set"): Register... dropdown (A Participant / Judge / Steward standard + quick → /register/{go}?view=quick per 1deb35c mapping), Assign/Unassign... dropdown (locations?action=assign&filter=… and tables?action=assign), Print Current View menu rendered disabled pending port output route, All Participants Email Addresses modal (real deduped addresses), Participant Status modal (live counts), Updated column from users.userCreated | admin/participants.admin.php |
| index.php?section=admin&go=participants&filter=judges | /backoffice/participants?filter=judges | A | FIXED (commit "feat(backoffice): participants + entries legacy control set"): same control set as base participants plus Assigned to Table(s) (judging_assignments⋈judging_tables, "N - Name") and Has Entries In… (distinct category+subcategory linked to the bid/category-filtered entries view) and Updated columns; stewards filter row identical | admin/participants.admin.php |
| index.php?section=admin&go=participants&filter=stewards | /backoffice/participants?filter=stewards | A | FIXED (commit "feat(backoffice): participants + entries legacy control set"): same fix as the judges filter row (controls + Assigned to Table(s)/Has Entries In…/Updated columns, steward assignments keyed assignment=S) | admin/participants.admin.php |
| index.php?section=admin&go=payments | /admin/payments | A | Port page is "Mark entries paid", not legacy's PayPal transaction-records page (Name/Item/Amount/Status/Txn/Entries/Date table with per-record Delete); payment-record management not ported | admin/payments.admin.php |
| index.php?section=admin&go=count_by_style | /backoffice/count-by-style | B | Missing style-type summary table (Style Type / Logged / Paid & Received, "Beer 3 2" + Totals) and "Breakdown By Style" heading; wording Style→Category renamed | admin/entries_by_style.admin.php |
| index.php?section=admin&go=count_by_substyle | /backoffice/count-by-substyle | B | Missing style-type summary table only; breakdown heading/table present | admin/entries_by_substyle.admin.php |
| index.php?section=admin&go=checkin | /admin/judging/checkin | A | Port has one Entry-or-Judging-number input; legacy barcode_check-in has 15-row batch form, barcode-scanner modal, "Switch View to Entry/Judging Numbers, Box, and Paid" toggle, usage instructions | admin/barcode_check-in.admin.php |
| index.php?section=admin&go=judging | /admin/judging/locations | B | Whole informational block missing (BJCP judge-points paragraphs + "Distributed Judging" h4); date column drops "AEST" timezone | admin/judging_locations.admin.php |
| index.php?section=admin&go=non-judging | /admin/judging/non-judging | B | Both explanatory paragraphs missing (non-judging session definition + staff availability) | admin/non-judging_locations.admin.php |
| index.php?section=admin&go=judging_tables | /admin/judging/tables | B | "View…" dropdown (Judge/Steward Assignments By Last Name/Table, Judges/Stewards Not Assigned to a Table), "Print…" dropdown (Pullsheets by Table, Assignments) and "Caution! … Un-Assigned" modal missing | admin/judging_tables.admin.php |
| index.php?section=admin&go=judging_flights | /admin/judging/flights | A | "Assign Flights to Rounds" action button/route not ported (ledger #8 out-of-scope); legacy "All Tables" button + "Choose a Table" dropdown replaced by flat list | admin/judging_flights.admin.php |
| index.php?section=admin&go=judging_preferences | /admin/judging/preferences | B | Sibling preference-tab buttons, "Queued Judging Info"/"Electronic Scoresheets Info" help modals, Scoresheet Unique Identifier help text missing; title/lead changed | admin/judging_preferences.admin.php |
| index.php?section=admin&go=judging_scores | /admin/judging/scores | A | FIXED (commit "feat(judging): scores + BOS index control set"): "Add or Update Scores For…" dropdown, "All Tables", "View BOS Entries and Places", Print pullsheet menu (disabled pending per-type output route) and "Scores entered for X of Y entries…" status now render; edit route reachable from UI | admin/judging_scores.admin.php |
| index.php?section=admin&go=judging_scores_bos | /admin/judging/bos | A | FIXED (commit "feat(judging): scores + BOS index control set"): "Add or Update…" dropdown (BOS Places per styleType), All Scores/All Tables buttons, Print menu with Cup Mats links live (per-type pullsheet items disabled pending output route) | admin/judging_scores_bos.admin.php |
| index.php?section=admin&go=special_best | /admin/judging/special-best | B | Explanatory paragraph ("Custom categories are useful if your competition features unique 'best of show' categories…"), "View…" and "Add/Edit Entries For…" dropdowns missing | admin/special_best.admin.php |
| index.php?section=admin&go=special_best_data | /admin/judging/special-best-data | B | "View…" dropdown, "Add a Custom Category" button, "Add/Edit Entries For…" dropdown, explanatory paragraph all missing | admin/special_best_data.admin.php |
| index.php?section=admin&go=preferences | /admin/site-preferences | C | Same General/Results/Localization/Sponsors/Drop-Off fields but re-widgeted: "Winner Place Distribution Method" options differ (legacy By Table-Medal Group/By Style/By Sub-Style); Language + Time Zone became free-text inputs instead of selects; "Available Languages" checkbox group dropped | admin/site_preferences.admin.php |
| index.php?section=admin&go=preferences&action=entries | /admin/site-preferences/entries | B | Per-style entry-limit grid dropped (legacy server-renders hundreds of styleEntryLimit-* BJCP2025 inputs; port has only "Method / No per-style limits") and "Discount Multiple Entries" toggle dropped; labels renamed (Fee Cap→Competition Entry Cap etc.) | admin/site_preferences.admin.php |
| index.php?section=admin&go=preferences&action=email | /admin/site-preferences/email | B | Contact Form option set reduced 3→2 (drops "Disable Contact Form - List Contacts"); legacy "SMTP Settings Test" section removed; labels renamed (Allow BCOE&M to Send Emails→Use SMTP Email?, Contact Form CC→CC Admin on Emails) | admin/site_preferences.admin.php |
| index.php?section=admin&go=preferences&action=payment | /admin/site-preferences/payment | C | All fields present; defaults/wording differ: "Accept Cash?" default No vs legacy Enable; currency options bare symbols vs "$ Dollar - U.S."; Pay to Print Paperwork→Pay to Print?, Checks Payee→Checks Payable To | admin/site_preferences.admin.php |
| index.php?section=admin&go=preferences&action=best | /admin/site-preferences/best | C | Best Brewer/Club fields all match; only help-text/button wording differs (Set Preferences→Save Best Brewer/Club Preferences) + COA info dialog | admin/site_preferences.admin.php |
| index.php?section=admin&go=upload&action=html | /admin/hero-images | A | Mismatched mapping: legacy go=upload&action=html is "Upload Logo Image" + "Files in the Directory" table with per-file delete (user_images); port route renders banner rotation instead — logo upload function unported at this URL | admin/upload.admin.php |
| index.php?section=admin&go=hero_images | /admin/hero-images | C | Same banner-rotation function and image set with Upload/Save/Delete; layout differs (legacy thumbnails + Select/Deselect All per category + per-image delete modal vs port checkbox fieldsets) | admin/hero_images.admin.php |
| index.php?section=admin&go=upload_scoresheets | /admin/upload-scoresheets | B | Upload form present but "Files in the Directory" listing dropped; Dropzone drag-and-drop replaced by plain file input; naming instruction changed from judging-number (01-234.pdf) to entry-number | admin/upload_scoresheets.admin.php |
