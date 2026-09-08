# Awards Presentation — Completion Plan (PARITY-001 remainder)

Instruction file for the implementing agent. Plan made 2026-09-06; all line
numbers and schema facts verified against the repos that day. Do not re-derive;
cite these refs when reasoning.

Legacy oracle: `/home/faraaz/dev/bcoe/brewcompetitiononlineentry` (BCOE&M 3.1.0).
Port: this repo. Branch `main`.

---

## 1. Mission

Bring the port's awards presentation (`/awards`) to full legacy `awards.php`
parity (1360 lines) using 2026 conventions: PHP 8.3 typed DTOs, reveal.js 5
via npm + Vite (no CDN, no jQuery, no lightslider, no fancybox), native
`<dialog>`, CSS scroll-snap. Preserve the existing access gate, route shape,
URL contract (`?view=` `?go=`), and slide order exactly.

## 2. Current state (verified — do not redo this research)

Exists and is CORRECT (leave alone unless a task touches it):

- Route `GET /awards` → `AwardsController::show` (`routes/web.php:81-84`) and
  the 301 `/awards.php` redirect (`routes/web.php:29-31`).
- Access gate (`AwardsController::show`): public needs judging past + entry/
  registration/judge windows After + `prefsDisplayWinners=Y` + winner delay
  passed; `userLevel <= 1` admins always; else `redirect('/?msg=7')`. Matches
  legacy `awards.php:28-37`.
- Theme map `white|black|blue → white/black/moon` (legacy `:45-49`), sort
  whitelist `table-numbers|table-name-only|table-entry-count-asc|desc`.
- Title / sponsor / judges / stewards / staff / stats / thank-you slides
  (`resources/views/awards/show.blade.php`), staff roll queries
  (`AwardsController::staffRolls`), stats slide, hidden `#scoring-method`
  div, footer line.
- Per-TABLE winner slides for `prefsWinnerMethod=0` with `?go=` ordering
  (`AwardsController::tableSlides`).
- BOS-per-style-type slides and special-best slides (simplified — see gaps).
- Dashboard launch modal `#presentationLaunch` with 3 theme links
  (`resources/views/admin/dashboard.blade.php:620-647`).
- `tests/Feature/AwardsPresentationTest.php` (4 tests) — keep passing.
- Support: `App\Support\Results\ResultsRepository`, `BestBrewerPoints`
  (unit-pinned port of legacy `best_brewer_points()`, `lib/common.lib.php:2264`),
  `Place::label()`.

## 3. Gap inventory (all verified against legacy source)

Order = implementation order. "L:" = legacy `awards.php` unless noted.

| # | Gap | Legacy ref | Severity |
|---|-----|-----------|----------|
| G1 | Best Brewer / Best Club slides missing entirely | `:588-1141`, `includes/db/scores_bestbrewer.db.php` | HIGH |
| G2 | Winner methods 1 (category) and 2 (subcategory) missing — only method 0 exists | `:241-440`, `includes/db/winners_category.db.php`, `winners_subcategory.db.php`, `styles_active()` `lib/common.lib.php:3837` | HIGH |
| G3 | Best-brewer points method pref is a phantom: callers pass `prefsStr('prefsBestBrewerPointsMethod')` but that column exists in NO database (review_scabs, bcoem_test baseline, parity_* all lack it) → always null → classic method even when `prefsScoringCOA=1`. Legacy selects method FROM `prefsScoringCOA` | callers: `app/Http/Controllers/Output/ResultsController.php:61`, `app/Http/Controllers/Admin/DashboardController.php:169-172`, `resources/views/public/partials/results.blade.php:6`; legacy: `scores_bestbrewer.db.php:3,15` | HIGH (pre-existing bug — fix as part of G1) |
| G4 | `bestBrewers()` rollup is unfaithful: no tie-breaker chain (passes `[]`), no per-pool best-place map (`Places-data`), no `prefsBestUseBOS` BOS-row inclusion, no entry-score collection (min/max/avg tiebreakers), no club aggregation, no top-N limit, includes only `brewReceived=1` winners (legacy BB query has NO received filter) | `ResultsRepository::bestBrewers()` vs `scores_bestbrewer.db.php` | HIGH |
| G5 | Special-best slides: `fh` hardcoded 1 (all winners reveal at once; legacy reveals sequentially via running count, or by place when `sbi_display_places=1`), style display dropped (legacy shows `cat.sub: style`), place label not gated on `sbi_display_places` | `:516-585` | MED |
| G6 | Co-brewer missing on all winner slides (legacy appends `& <em>coBrewer</em>` truncated at 20 on a space boundary) | `:207-209`, `truncate_string()` `lib/common.lib.php:5200` | MED |
| G7 | Head Judge marker missing on table slides; judges line dead (`tableSlides` docblock declares `judges` field, never populated, view never renders it) | `:157-170` (`assignRoles` contains `HJ`) | MED |
| G8 | BOS slides missing "Judges: …" line (`$judge_bos` = staff with `staff_judge_bos=1`) | `:473` | LOW |
| G9 | `prefsStyleSet` ignored: AABC → `1.A` dotted ltrim-zeros; BA → style name only; default → `cat.sub: name`. Port always emits `cat.sub: name` | `:181-186` | MED |
| G10 | `prefsProEdition` name display ignored: Pro shows `brewerBreweryName` instead of First Last (all slide types) | `:199-203` | MED |
| G11 | Entry name / club truncation missing (65 / 25 chars, break on space, `...` pad) | `:208-214`, `truncate_string()` | LOW |
| G12 | Sponsor slide: legacy uses lightslider carousel. Modern replacement: CSS scroll-snap, no dep. Plain `<img>` stack acceptable visually but janky with >4 sponsors | `:1228-1246,1343-1353` | LOW |
| G13 | `#scoring-method` div is rendered but unreachable (fancybox absent) and its content is incomplete: missing the tie-breaker description list (`best_brewer_text_005`-`013`) and legacy gates the div on BB/BC being enabled | `:1102-1133` | MED |
| G14 | Sidebar lacks the legacy Launch Awards Presentation button. Gate chain: `$show_presentation` (`constants.inc.php:490,528-534` = NOT judging past AND `prefsDisplayWinners=Y` AND winner delay passed) AND (live `judging_scores` OR `judging_scores_bos` has ≥1 row — `get_archive_count()`, `common.lib.php:3567`, checks the LIVE tables despite the name) AND `bcoem-admin-element` visibility (admin CSS class, `bruxellensis.css:1399`). Port's `ResultsRepository::archives()` reads the `archive` table — WRONG source for this gate; tenant `archive` is empty while `judging_scores` has rows, so the button never shows | `sections/sidebar.sec.php:319-321`, `includes/constants.inc.php:490,528-534` | MED |
| G15 | `<noscript>` alert missing (`alert_text_087`) | `:1207` | LOW |
| G16 | Verify (do not blindly change): table `count` uses distinct `judging_flights.flightEntryID`; legacy `get_table_info(1,"count_total",…)`; stats counts vs `get_entry_count()`/`get_participant_count()` (`lib/common.lib.php:2585`) | `:144,1291-1297` | VERIFY |
| G17 | Dashboard action row: legacy `default.admin.php:516-586` — when `prefsWinnerMethod=0` a modal-launch button (port HAS this); when method 1/2 a DIRECT `/awards` link button with hover popover (identical label/icon). Port renders NOTHING for methods 1/2. Wrapper gate: `$judging_started && userLevel==0` | `admin/default.admin.php:516,581-586` | MED |

Non-gaps (checked, already faithful): gate, themes, sorts, slide order
(title→sponsors→judges→stewards→staff→stats→winner slides→BOS→special-best→
BestBrewer→BestClub→thank-you), fragment-index semantics for 1st..HM
(`place_heirarchy`), medal colors, footer, date format via prefs.

## 4. Modernization decisions (fixed — do not re-litigate)

1. **reveal.js 5.x from npm**, imported through Vite. Add inputs to
   `vite.config.js`: `resources/css/awards.css`, `resources/js/awards.js`.
   `show.blade.php` switches from hand-rolled CDN `<head>` to
   `@vite(['resources/css/awards.css','resources/js/awards.js'])`.
   Reveal 5 API for our use is identical to 4.1 (`Reveal.initialize({hash:true,
   plugins:[Notes]})`, `r-fit-text`, themes white/black/moon). Keep the
   page standalone (no site layout, no Bootstrap).
2. **No jQuery, no lightslider, no fancybox.** Sponsor carousel = CSS
   scroll-snap `<ul>` (auto-advance NOT required for parity; legacy auto
  advances — if trivially cheap add a 4s `setInterval` scrolling by one item,
   else skip and note). Scoring-methodology = native `<dialog>` +
   `showModal()` on click of the `[scoring-method]` links.
3. **Extract the deck builder**: new `app/Support/Awards/AwardDeckBuilder.php`
   — one final class, static-ish pure methods returning readonly DTOs
   (`AwardSlide`, `AwardWinner`, `BestBrewerRow` — plain readonly classes in
   the same file or `app/Support/Awards/`), no interfaces, no factories.
   Controller slims to: gate → params → delegate → `view()`. Rationale:
   BB math + method 1/2 grouping need deterministic unit tests without HTTP.
4. **Faithful data semantics beat tidy SQL.** Where legacy is janky
   (unsorted BB query → last-write-wins per pool), replicate the semantics
   but make iteration deterministic (`->orderBy('a.id')`) and leave a
   `// parity:` comment naming the deviation. Never invent behavior.
5. **Lang strings**: reuse `lang/en/site.php` keys where they exist
   (`awards`, `sponsors`); add the missing ones with legacy-exact values from
   `lang/en/en-US.lang.php` (see §6) under an `awards.*` namespace group
   (`lang/en/awards.php` returning an array). Do not rename existing keys.
6. **Blade dedup**: one `resources/views/awards/partials/medal-grid.blade.php`
   used by table/category/subcat/BOS slides (legacy repeats the same markup
   4×). Best-brewer/club table slide gets its own partial (table markup is
   different).

## 5. Work items

### Phase 1 — Fix the points-method bug + faithful Best Brewer engine (G3, G4, G1)

1. New `app/Support/Awards/BestBrewerStandings.php` (builder), porting
   `includes/db/scores_bestbrewer.db.php` EXACTLY. Read that file in full
   first. Contract:
   - Source rows: `judging_scores js JOIN brewing b ON js.eid=b.id JOIN brewer
     br ON b.brewBrewerID=br.uid WHERE js.scorePlace IS NOT NULL` (NO
     brewReceived filter — deliberate). Select `js.scorePlace, js.scoreEntry,
     js.scoreTable, b.brewCategorySort, b.brewCategory, b.brewSubCategory,
     br.uid, br.brewerFirstName, br.brewerLastName, br.brewerBreweryName,
     br.brewerClubs`. `->orderBy('js.id')`.
   - Per brewer (and per club for the club slide — see 2): integer filter
     `floor((float)place) == place && place>=1 && place<=5`; `Places[0..4]`
     counts; `Scores[]` = scoreEntry values; name per Pro edition (G10).
   - `prefsBestUseBOS=1`: same treatment for `judging_scores_bos` rows
     (`jsb.eid=b.id AND jsb.bid=br.uid`? — check legacy join: it joins `c.uid
     = a.bid`; replicate that join exactly, including its bid-vs-brewBrewerID
     difference from the regular query).
   - CoA pools (`prefsScoringCOA=1`): `Places-data[poolKey] = place` per row;
     poolKey = `scoreTable` when `prefsWinnerMethod=0` else `brewCategorySort`
     (legacy also has a subcategory variant keyed `cat^sub` style — copy the
     exact key format from `scores_bestbrewer.db.php`). Pool sizes:
     `bb_points_prefs[poolKey]` = COUNT(*) of judging_scores rows for that
     pool (all scored rows, not just placed) — copy legacy's three branches
     verbatim.
   - Points: `BestBrewerPoints::calculate($places, $scores, $poolsOrPrefs,
     $tiebreakerChain, method, $userEntries)` where method = `'1'` when
     `prefsScoringCOA=1` else `'0'` (G3), `$tiebreakerChain` =
     `[prefsTieBreakRule1..6]` values as-is (`Unused` falls through legacy's
     switch — our `BestBrewerPoints` must no-op unknown identifiers; verify
     it does, fix if not), `$userEntries` = that brewer's paid+received
     entry count (legacy `total_paid_received("", $bid)` — check port for an
     existing equivalent; `brewing where brewBrewerID AND brewPaid=1 AND
     brewReceived=1` count).
   - Sort: points DESC (legacy `bb_sorter`); ties keep legacy's tie-break
     implicit order — points equality then key order; leave stable sort.
2. Club slide: same engine keyed by `normalizeClubs(brewerClubs)`
   (`strtolower` + strip non-alnum; `lib/common.lib.php:5230`), displaying
   the FIRST original club string seen per key (legacy stores `['Clubs']` on
   first init). Gate: `prefsProEdition=0` AND `prefsShowBestClub != 0`.
3. Top-N: `prefsShowBestBrewer` / `prefsShowBestClub`: `-1` = all, `N>0` =
   first N rows. `0` = slide suppressed.
4. `show_HM` column logic: HM column rendered only when any displayed row has
   `Places[4] > 0`; 4th-place column only when `prefsFourthPlacePts > 0`
   (verify exact conditionals at legacy `:940-958` / `:1060-1080` — read
   them; do not trust this line alone).
5. Fix G3 at its roots: replace every `prefsStr('prefsBestBrewerPointsMethod')`
   call with the `prefsScoringCOA`-derived method via the new builder
   (`ResultsController.php:61`, `DashboardController.php:169-172`,
   `resources/views/public/partials/results.blade.php:6`). The phantom pref
   name must no longer appear anywhere (`grep -rn prefsBestBrewerPointsMethod app/ resources/`
   → 0 hits after). Keep `bestBrewers()` on `ResultsRepository` if the
   faithful builder covers its callers, migrate them; otherwise delete
   `bestBrewers()` — clean cutover, no shim.
6. Slide markup (legacy `:962-979` brewer, `:1083-1100` club):
   `<h1 class="r-fit-text tight">{prefsBestBrewerTitle|prefsBestClubTitle}</h1>`
   then `<p class="entry-count"> N participating brewers|clubs
   [<a data-target="scoring-method">Scoring Methodology</a>]</p>` then the
   ranked `<table>` (font-size .55em, position/name/club/place-counts/points;
   points rendered per legacy — read `:940-958` for rounding/exact columns).
   N = `get_participant_count('received-entrant'|'received-club')` — port
   equivalents: distinct brewer with ≥1 received entry; distinct non-empty
   normalized club among received entries (verify vs legacy
   `get_participant_count`, `lib/common.lib.php:2585`).
7. Render the BB/BC slides between special-best and thank-you (order in §2).
   Gate the whole block on `(prefsShowBestBrewer|prefsShowBestClub) != 0` AND
   at least one scored entry (legacy `:589` + `$bb_totalRows_scores > 0`).

Acceptance: with seeded data, `/awards` HTML contains BB slide when
`prefsShowBestBrewer != 0`, honors top-N, BOS inclusion toggles change
points, CoA vs classic produce the pinned fixture values,
`grep -rn prefsBestBrewerPointsMethod app/ resources/` is empty.

### Phase 2 — Winner methods 1 and 2 (G2)

1. Read `awards.php:241-440` (category) and `:400-560` region for
   subcategory, plus `includes/db/winners_category.db.php` and
   `winners_subcategory.db.php` in full.
2. Grouping source: legacy `styles_active($method)` returns active
   category/subcategory keys from the `styles` table. Port the minimal
   equivalent inside the builder (query `styles` filtered like legacy —
   read `lib/common.lib.php:3837-3900` for the exact filters; do NOT port
   its archive branches).
3. Slide shape: same medal-grid; title = category line
   (`Category {cat}{sub}: {name}` with AABC/BA variants per G9);
   `<p class="entry-count">N entries</p>`; winners from judging_scores for
   that category (method 1) or category+subcategory (method 2), places 1-5,
   place order asc. Method 1/2 slides IGNORE `?go=` (legacy does too).
4. Keep method 0 path exactly as is.
5. With methods 1/2 active, add the dashboard direct-link button per
   Phase 5 (G17) — or defer both to Phase 5, but ship them together.

Acceptance: feature tests for all three methods; `prefsWinnerMethod=1`
renders category slides in `styles` order; `=2` renders subcategory slides.
Dashboard shows the direct-link launch button when method ≠ 0 (G17).

### Phase 3 — Slide-fidelity fixes (G5-G11, G13, G15)

1. `medal-grid.blade.php` partial; props: place, fh, name (Pro-aware),
   club (Pro-gated, 'Other' stripped, truncated 25), entry (truncated 65),
   style (style-set-aware per G9), coBrewer (truncated 20, `& <em>…</em>`).
2. Add `brewCoBrewer` to every winner query (table/BOS/category/subcat/
   special-best); `brewerBreweryName` too.
3. Special-best: `fh` = `placeHierarchy(sbd_place)` when
   `sbi_display_places=1` and place non-empty, else sequential 1..N over
   winners; place label only when `sbi_display_places=1`; style line from
   the brewing join (legacy `:520-560`).
4. Table slides: judges line `Judges: A, B (Head Judge), C` from
   `judging_assignments assignment='J'` joined brewer, HJ when
   `assignRoles` contains `HJ` (legacy `:157-170`); populate the declared
   `judges` field and render it in the partial.
5. BOS slides: prepend `Judges: {staff_judge_bos roll}` (G8) using the
   existing staffRolls query's bos list.
6. `#scoring-method` → native `<dialog>`; content completed with the
   tie-breaker ordered list (`best_brewer_text_005`-`013` mapped through
   the 6 configured rules — render descriptions for rules 1..N in order,
   skipping `Unused`) and the COA branch; render the dialog only when the
   BB/BC block renders (legacy gates it inside that block); wire the two
   `[scoring-method]` links with 3 lines of JS in `resources/js/awards.js`.
7. `<noscript>` with the alert text (G15).
8. Verify G16 counts; correct only with a legacy-cited diff.

Acceptance: feature tests assert co-brewer, HJ marker, style-set variants
(seed `prefsStyleSet` AABC/BA/default), Pro-edition brewery names,
special-best sequential fh (count `data-fragment-index` values in HTML).

### Phase 4 — Front-end modernization (G12 + Vite/reveal 5)

1. `npm i reveal.js` (5.x). Delete CDN tags in `show.blade.php`; new
   `resources/js/awards.js`: `import Reveal from 'reveal.js'; import Notes
   from 'reveal.js/plugin/notes/notes';` init with `{hash:true,
   plugins:[Notes]}`; dialog wiring; optional sponsor auto-advance.
   `resources/css/awards.css`: move the inline `<style>` block (footer,
   tight, entry-count, medal-grid, medal colors, logo-image) + scroll-snap
   sponsor styles + minimal theme-font overrides. Import reveal's theme CSS
   (`reveal.js/dist/theme/white|black|moon.css`) — theme must stay
   switchable via `?view=`: easiest is rendering all three `<link>`s with
   only the active one enabled, or a tiny inline style tag swapping
   `data-theme` class — pick the boring one: keep theme CSS as Vite entries
   per theme? NO — simplest: keep theme `<link>` static per request
   (server knows `$theme`) by conditionally rendering one of three
   Blade `@if(theme===...)` blocks importing the CSS file path from the
   built manifest is fragile — instead `@vite` the shared awards.css and
   link reveal theme CSS from `public/` copied at build time
   (`vite-plugin-static-copy` is already available? check; if adding a
   plugin feels heavy, `cp node_modules/reveal.js/dist/theme/*.css
   public/vendor/reveal/` as a composer/post-autoload or npm `postbuild`
   script). Choose and document the choice in the commit body.
2. `npm run build` MUST pass and the built page must not reference
   cdnjs/jsdelivr/jquery/lightslider/fancybox (`grep` the rendered HTML).
3. Sponsor slide: `<ul class="sponsor-slider">` scroll-snap row, `height:200`
   images, `data-thumb` semantics dropped (thumbs were lightslider-only).

Acceptance: Dusk DOM probe confirms `window.Reveal` (or module-initialized
`.reveal-viewport` class on body) and `Reveal.isReady()===true`; zero external
(non-same-origin) stylesheet/script requests except fonts you consciously
keep — league-gothic/source-sans-pro: npm `@fontsource` them or keep the two
CDN `<link>`s; state which in the commit body.

### Phase 5 — Launch buttons: sidebar (G14) + dashboard methods 1/2 (G17)

Sidebar (`resources/views/components/public-sidebar.blade.php`, Past Winners
panel — legacy puts the button in `$header1_600`, the SAME panel the port
renders): add

```blade
<a class="btn btn-primary btn-sm w-100" href="{{ url('/awards') }}"
   target="_blank" rel="noopener">{{ __('awards.launch_presentation') }}
   <span class="fa fa-award"></span></a>
```

gated on the legacy chain, each piece cheap:
1. `! $judgingPast` equivalent — reuse `Windows::derive($ctx, now())` as in
   `AwardsController::show` (`futureJudgingSessions === 0` is judging past,
   so the gate is `futureJudgingSessions > 0`).
2. `prefsDisplayWinners === 'Y'` AND `now > (int) prefsWinnerDelay`.
3. `DB::table('judging_scores')->exists() || DB::table('judging_scores_bos')->exists()`
   — the LIVE tables (`get_archive_count` checks live despite its name).
   Do NOT use `ResultsRepository::archives()` — the `archive` table is a
   different feature (past comps) and is empty here.
4. Element class `bcoem-admin-element` (admin-only visibility, same mechanism
   the port already uses elsewhere — check how the port hides admin-only
   sidebar elements and mirror it; if the port's sidebar is not rendered for
   anon users at all, gate server-side instead and keep the CSS class for
   parity).

Dashboard (`resources/views/admin/dashboard.blade.php` action row ~line 80):
currently gated `$status['judgingStarted'] && $status['winnerMethodTable']`.
Change to mirror legacy `default.admin.php:516-586`:
- method 0 (current modal button): gate stays `judgingStarted && winnerMethodTable`.
- methods 1/2 (NEW, `! $status['winnerMethodTable']`): plain
  `<a class="btn btn-info btn-sm d-block w-100" href="{{ url('/awards') }}"
  target="_blank" rel="noopener">Launch Awards Presentation …fa-award</a>`
  with the legacy hover popover text (BS5: `data-bs-toggle="popover"`
  + a one-line popover initializer or drop the popover — popover is
  cosmetic; state choice in commit). Same wrapper gate `judgingStarted`
  (level-0 gating is already implicit — the port dashboard is admin-only).

Acceptance: DOM probe of `/` anon + logged-in admin — button present exactly
when the chain holds; with `prefsWinnerMethod=1` the dashboard shows the
direct link (no modal), with `=0` the modal button; neither renders before
judging starts.

## 6. Legacy constants (exact strings — from `lang/en/en-US.lang.php`)

```
label_awards="Awards" label_sponsors="Sponsors" label_judges="Judges"
label_stewards="Stewards" label_staff="Staff" label_organizer="Organizer"
label_table="Table" label_category="Category" label_bos="Best of Show"
label_entries="Entries" label_entry="Entry" label_entrants="Entrants"
label_thank_you="Thank You" label_congrats_winners="Congratulations to All Medal Winners"
label_by_the_numbers="By the Numbers" label_placing_entries="Placing Entries"
label_launch_pres="Launch Awards Presentation" label_head_judge="Head Judge"
winners_text_007="There are no winning entries at this table."
best_brewer_text_000="participating brewers" best_brewer_text_001="HM"
best_brewer_text_003="Scoring Methodology" best_brewer_text_014="participating clubs"
best_brewer_text_015= (COA lead — read at :1108)
alert_text_087="For an optimal experience and so that all features and functions execute properly, please enable JavaScript to continue using this site. Otherwise, unexpected behavior will occur."
```
Tie-breaker descriptions 005-013 read directly from the lang file. When a
port translation key already covers a string (e.g. `site.awards`), reuse it.

## 7. Schema facts (verified on review_scabs; bcoem_test `baseline_` prefix)

- `preferences` HAS: `prefsWinnerMethod, prefsShowBestBrewer,
  prefsBestBrewerTitle, prefsShowBestClub, prefsBestClubTitle,
  prefsBestUseBOS, prefsScoringCOA, prefsFirstPlacePts, prefsSecondPlacePts,
  prefsThirdPlacePts, prefsFourthPlacePts, prefsHMPts,
  prefsTieBreakRule1..6`. DOES NOT HAVE: `prefsBestBrewerPointsMethod`
  (phantom — see G3).
- `judging_scores`: `id eid bid scoreTable scoreEntry scorePlace scoreType
  scoreMiniBOS`. `judging_scores_bos`: `id eid bid scoreEntry scorePlace
  scoreType`. `judging_assignments.assignRoles` (string, contains `HJ`).
  `special_best_info.sbi_display_places`. `special_best_data.sbd_place`.
- Current tenant values: `prefsWinnerMethod=0, prefsShowBestBrewer=2`,
  place points 4/3/2/0/? — always seed your own values in tests; never
  assert against tenant data.

## 8. Test plan

Extend `tests/Feature/AwardsPresentationTest.php` (keep its seeding/teardown
pattern) and add `tests/Unit/BestBrewerStandingsTest.php` pinning the
builder: classic+tiebreakers, CoA per pool method, BOS inclusion, top-N,
club aggregation, HM/4th column flips. Seed minimal rows per test like the
existing `seedWinners()`; restore prefs in `tearDown()`.

Dusk verification (REQUIRED, and NO SCREENSHOTS — explicit user rule):
temp `tests/Browser/Temp*.php` probe: login via `#login-modal`
(`smoke.p57@brewingcompetitions.com` / `bcoem`), visit `/awards`, run
`$browser->script(...)` returning `JSON.stringify({ready:
Reveal.isReady(), slides: document.querySelectorAll('.slides>section').length,
fragments: [...new Set([...document.querySelectorAll('[data-fragment-index]')].map(e=>e.dataset.fragmentIndex))].length})`,
`file_put_contents('/tmp/awards_probe.json', …)`, run
`timeout 240 php artisan dusk --filter=Temp…Test`, parse the JSON, delete the
temp test. Probe BB slide table present when enabled, `<dialog>` opens on
methodology click, deck slide count changes with `?go=`.

Gates after each phase: `php artisan dusk --filter="AdminBatchABs5Test|PublicBs5GateTest"`
plus full `php artisan test` (feature suite runs against `bcoem_test`).

## 9. Commit protocol

Conventional Commits, one commit per phase (fix/feat), push after each:
e.g. `fix(awards): best brewer points method derived from prefsScoringCOA`
(G3/G4), `feat(awards): best brewer and best club slides` (G1),
`feat(awards): category and subcategory winner slides` (G2),
`fix(awards): slide fidelity — co-brewers, head judge, style sets, special-best reveals`
(G5-G11,G13,G15), `feat(awards): self-hosted reveal 5 via vite, native dialog, scroll-snap sponsors`
(G12+), `feat(awards): launch buttons — sidebar presentation launch, dashboard direct link for winner methods 1/2`
(G14, G17).

## 10. Repo pitfalls (hard rules)

- **NEVER take screenshots.** DOM probes / page source only (user rule).
- `GLOB_BRACE` is undefined on this PHP (musl) — never use it.
- BS3 marker gate (`AdminBatchABs5Test`) forbids BS3-only classes on BS5
  pages (`btn-xs` → `btn-sm`). The awards page is standalone (no Bootstrap),
  so this applies to the sidebar button, not the deck.
- After blade edits: `rm -f storage/framework/views/*.php && php artisan view:clear`.
  After CSS/JS changes: `npm run build`.
- Never commit or delete untracked `tests/Browser/VisualScreenshotPassTest.php`
  and `phpunit.dusk.xml`; never re-run mods feature tests against
  review_scabs (re-seeds a junk `mods` row).
- Edit tool: after any large block rewrite, re-read the region and
  `php -l` it; prefer targeted line edits.
- `artisan serve` is on 127.0.0.1:8000 (tenant `review_scabs`); if geckodriver
  is dead: `nohup geckodriver --port=4444 --host=127.0.0.1 >/tmp/gecko.log 2>&1 & disown`.
