# LEGACY-UNKNOWN-BEHAVIOUR

Audit rule §19.10: record UNKNOWNs rather than guessing.

| # | Question | Why unknown | How to resolve |
|---|---|---|---|
| U1 | `ppv.php` purpose + whether scabs uses it | 12.5KB standalone not linked from audited nav | read ppv.php; grep scabs deploy config |
| U2 | Maintenance-mode UX parity (MAINT flag) | port equivalent (Laravel `down`?) not audited | exercise MAINT=1 legacy vs port maintenance route |
| U3 | Numeric error pages visual parity | no screenshot pass yet | screenshot 404 both sides |
| U4 | Which legacy admin pages used jQuery `sortable()` and does port replicate drag ordering | 28 grep hits; port side not audited per page | per-page check (biggest hidden surface) |
| U5 | DataTables on styles + judging_scores_bos: does port reproduce client-side sort? | port renders plain tables | verify sort click behaviour parity on those two pages |
| U6 | Mods rendering (`prefsUseMods=Y`) on public pages | port CRUD exists; include-rendering not verified | enable pref on corpus, compare |
| U7 | Tooltips on public side (BS5 tooltip init) | legacy loads BS bundle; port has no BS JS on public | verify any `data-toggle=tooltip` on port public views |
| U8 | Loader overlay + no-JS alert parity | not observed in port markup | compare submit flows in browser |
| U9 | Required-fields modal + disabled submit gate on port forms | not found in port views | submit empty brew/register form on both |
| U10 | tom-select multiselect fields (clubs/styles filters) — behaviour parity | port uses native/daisyUI selects | exercise multiselect fields both sides |
| U11 | Delete confirmations per CRUD page (confirm() vs modal) | not inventoried | click through deletes on both |
| U12 | Help icon (`bcoem_help()`) per admin section | not found in port admin navbar | verify legacy rendering conditions |
| U13 | Sticky home + scroll-indicator + smooth anchor offset behaviour | CSS partially migrated | browser compare |
| U14 | Archive offcanvas trigger conditions on live scabs data (archiveSuffix values) | corpus has demoarchive only | run against real scabs DB copy |
| U15 | Language toggle scope for scabs (non-English users?) | pref-driven; port has single lang file | ask organiser / check scabs prefs |
| U16 | DataTables **public** side: 10 `pub/*.php` files init dataTable (bestbrewer ×2, bos, …) — port must reproduce client-side sort/pagination on those surfaces | found via grep; port side not audited for these files | per-file check |
| U17 | `maps.output.php` port controller binding (route name showed `..`) | route exists, controller unnamed in dump | `php artisan route:list | grep maps` detail |
| U18 | Session countdown on public side (dropdown footer) vs admin-only | legacy renders on public too (nav.pub.php:189) | port missing — confirm intended |
| U19 | Judging Dashboard dropdown link conditions (prefsEval + assignment + window) exact gates | complex compound condition | trace `brewer_assignment()` for corpus |
| U20 | `custom_competition_info.pub.php` — does scabs install have one? | file-based drop-in | check scabs deploy bundle |
