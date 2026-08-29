# PARITY-PLAN-P2

Implementation plan for the P2 backlog batch (PARITY-006, 007, 012, 015 and
the P1 residuals surfaced by the run-20260829-194856 parity harness:
39 unique missing links). Each slice is one commit, ordered by dependency:
harness-blocking href fixes first (controller-only), then new routes/pages,
then the layout-scale work (sidebar) last.

User constraint (2026-08-29): **do not make assumptions, do not use
snapshots to assume things about pages — ground everything in code.**
Every slice below names the exact legacy file:line and the exact port
file:line it changes.

## Architecture context (verified in code this session)

- `DashboardController::sections()` (app/Http/Controllers/Admin/
  DashboardController.php:193) builds left/right panels; item shapes:
  `$l()` link / `$todo()` dead span / `$family()` dropdown / `['label',
  'href', 'target']` / `['label','modal'=>id]`.
- `LegacyRedirectController` GET_MAP (`'sponsors||' => ['/sponsors']`,
  line 47) and PROCESS_MAP. `LegacyUrlRedirectTest` treats every row as a
  contract.
- Public pages: `PublicController` (routes/web.php:28-37) — home, list,
  pastWinners, volunteers, contact. No `/sponsors` route exists.
  `x-public-layout` component (resources/views/components/
  public-layout.blade.php) renders nav/hero/salutation + `{{ $slot }}`;
  nav Sponsors link is `#sponsors` anchor (line 285) but **no view emits
  an `id="sponsors"` section today** (verified: grep across
  resources/views).
- Legacy sponsors standalone page = `sections/sponsors.sec.php` (89
  lines, verified read): `sponsors.db.php` query, per-sponsor
  `bcoem-sponsor-container` columns with name/URL link, location,
  optional logo (prefsSponsorLogos=Y, no_image.png fallback), text; only
  `sponsorEnable == "1"` rows; padding columns when row not full.
  Reached via `?section=sponsors` (index.legacy.php:206) with sidebar
  layout (index.legacy.php:225).
- Legacy anon sidebar = `sections/sidebar.sec.php` (488 lines): rendered
  on every `section != "admin"` page (index.legacy.php:223-225).
  Panels (emit order, lines 452-470): 600 Past Winners (archive rows,
  contestWinnerLink) → 400 Judging Locations (prefsJudgingLocations) →
  700 (mods?) → 100 Account Registration (anon only, line 80 `if
  (!$logged_in)`) → 200 Entry Window (show_entries) → 300 Drop-Off
  (prefsDropOff==1) → 500 Shipping (prefsShipping==1). Plus logged-in
  account summary (list/pay sections) and competition logo. Panel colors
  driven by window open/closed state (lines 54-77).
- Import Scores: port already has `EvalImportController` (57 lines,
  consensus->import(), JSON response) — the dashboard `$todo()` at
  DashboardController:390 is the only gap.

## Verification gates (same as P1)

1. Full suite green (baseline 822/821/1 skipped).
2. Parity harness missing-links must drop below 39 unique (run
   `LEGACY_DIR=~/dev/bcoe/brewcompetitiononlineentry
   DUMP_SQL=~/dev/bcoe/corpus/derived/anon-base.sql PARITY_DB_PREFIX=
   PARITY_DB_PASS=root tools/parity/parity.sh`; kill stale
   `php -S 127.0.0.1:809*` first).
3. Export byte-parity PASS (fetch_export.sh, same env).
4. Tag-balance check (python HTMLParser) on any blade touched — zero
   new errors vs the 15 pre-existing legal optional-close patterns.

---

## Slice 1 — Sponsors public page (PARITY-012)

**Target:** routes/web.php, PublicController, new view, nav link.

Legacy `sections/sponsors.sec.php` renders a 4-col grid of enabled
sponsors. The port's nav (public-layout.blade.php:284-286) already
renders the Sponsors link but points at `#sponsors`, which no view emits
— on `/` it dead-anchors, on other pages it goes `/#sponsors` which also
dead-anchors.

**Change:**
1. `PublicController::sponsors()` — gate: `prefsSponsors === 'Y'` and
   `sponsors` count > 0 (mirror home's `$sponsorsVisible`); else
   redirect `/` (legacy renders empty grid; the redirect matches the
   port's other gate patterns like pastWinners).
2. Blade `resources/views/public/sponsors.blade.php` — port the grid:
   `@foreach ($sponsors as $s)` where `sponsorEnable == '1'`: name
   (linked to sponsorURL when set), location, logo (`prefsSponsorLogos
   === 'Y'`, `no_image.png` fallback when sponsorImage empty/missing),
   sponsorText. Tailwind grid (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`)
   matching legacy col-lg-3 col-md-6 col-sm-9 intent. Page heading uses
   the same `landing-page-section-header` pattern as contact.blade.php.
3. Route `Route::get('/sponsors', ...)` in routes/web.php.
4. Nav link: keep `#sponsors` on landing (add the `id="sponsors"`
   section to home only if legacy's landing sponsors section is
   verified as index.pub.php:440-446 — it is, so home blade must also
   render the sponsors section with `id="sponsors"` **on the landing**;
   the nav Sponsors link on non-landing pages points at `/sponsors`).

Wait — legacy `index.pub.php:446` includes `sponsors.pub.php` **on the
landing**, and `?section=sponsors` renders `sponsors.sec.php` as a
standalone. Both surfaces must exist. Port has neither.

**Home landing section:** add to home.blade.php (before the contact
section, mirroring index.pub.php order — volunteers, then sponsors,
then contact):
```blade
@if ($sponsorsVisible)
    <section id="sponsors" class="landing-page-section pb-4 print:hidden">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.sponsors') }}</h1></header>
        {{-- sponsor cards — shared partial, see below --}}
    </section>
@endif
```
The card markup differs between landing (`sponsors.pub.php` — BS5 cards
with footer/visit button) and standalone (`sponsors.sec.php` — older
section-based markup). Port both from their files, not from each other.

**Standalone page:** `/sponsors` with `x-public-layout` (hero shown,
salutation), page header "Sponsors", grid from sponsors.sec.php markup
ported to Tailwind.

**Shared data:** `DB::table('sponsors')->where('sponsorEnable', 1)` —
sponsorName, sponsorURL, sponsorLocation, sponsorImage, sponsorText;
`prefsSponsorLogos` from ctx.

**Test:** extend a PublicSurface-based test: `/sponsors` 200 with
seeded enabled sponsor (name visible), 404/redirect when prefsSponsors=N;
landing contains `id="sponsors"` section when visible.

---

## Slice 2 — qr.php link residuals (PARITY-002 tail)

Four legacy surfaces link qr.php; the port has the page (`/qr`) but
three blades still miss the link:

| Legacy site | Legacy line | Port file | Port gap |
|---|---|--- residuals|---|
| competition_info.admin.php:443 help-block | help text with qr.php link | resources/views/admin/competition-info.blade.php:55-59 | help-block says only "Leave blank to clear (stored hashed)." |
| site_preferences.admin.php:1803 | QR paragraph with qr.php link | resources/views/admin/site-preferences.blade.php:327+ label-options block | paragraph absent |
| admin-nav.pub.php:230 | "Entry Check-in Via Mobile Devices" | public-layout.blade.php:200-201 | link present but points at `/admin/judging/checkin` not `/qr` |

**Change:**
1. competition-info.blade.php: add `<a href="{{ url('/qr') }}" target="_blank" rel="noopener">QR Code Entry Check-In</a>` sentence to the help-block (legacy text: "For use with the {QR Code Entry Check-In} function.").
2. site-preferences.blade.php: add the legacy paragraph after the label-options help-block: "The QR code options are intended to be used with a mobile device and {QR code entry check-in function} (requires a QR code reading app)." with link to `/qr` target _blank.
3. public-layout.blade.php:201: change `href="{{ url('/admin/judging/checkin') }}"` → `url('/qr')` on the Mobile Devices row only (line 201); line 200 (Barcode Scanner) keeps `/admin/judging/checkin` (that is the port's barcode checkin controller).

**Test:** AdminScreens test asserting the three blades contain
`/qr` links (competition-info 200 + href, site-preferences 200 + href,
admin nav as admin contains `/qr` target _blank).

---

## Slice 3 — Results card matrix (PARITY-015)

**Target:** DashboardController:601-627.

Current port collapses the legacy results matrix into mechanical
`go|tb|filter|psort|view` pipe-labels (e.g. "judging_scores | scores |
winners (filter)"). Legacy (default.admin.php:1998-2110, verified read)
emits:

- Two categories when `prefsWinnerMethod == 0` (table-based):
  - "Results (Table)" — 4 dropdown families: "All with Scores...",
    "Winners Only with Scores...", "All without Scores...", "Winners
    Only without Scores..." each with 3 children (By Table Number /
    table-entry-count-asc / table-entry-count-desc).
  - "All Results (Table - Single Report)" — same 4 families but `go=all`.
- When `prefsWinnerMethod != 0`: flat links "All with Scores" /
  "Winners Only with Scores" (tb=scores), "All without Scores" /
  "Winners Only without Scores" (no tb).
- BOS Results row (already ported, lines 590-594) sits before this.

**Change:** rewrite the `$reportLinks` loop (611-620) as a
`$resultsFamily(label, view, psort)` helper emitting the 4 × 3 children,
gate on `$prefs['winnerMethod']`:
```php
$rf = fn (string $label, string $view, string $go = 'judging_scores', string $tb = 'scores') => $family($label, [
    $l('/admin/output/results?go='.$go.'&action=print'.($tb ? '&tb='.$tb : '').'&view='.$view, 'By Table Number'),
    $l('...&psort=table-entry-count-asc', 'By Table/Medal Group Entry Count - Ascending'),
    $l('...&psort=table-entry-count-desc', 'By Table/Medal Group Entry Count - Descending'),
]);
```
Two categories: `['Results ('.methodLabel.')', [4 × $rf]]` and
`['All Results ('.methodLabel.' - Single Report)', [4 × $rf(go=all)]]`.
Non-zero winnerMethod: flat 2×2 links. Keep existing 'PDF report'/
'HTML report' rows only if a legacy equivalent exists — verify before
keeping; if absent from legacy default.admin.php, delete.

**Test:** AdminDashboardLinksTest additions: when winnerMethod=0 →
'All with Scores...' family present with 3 children labels; when
winnerMethod=1 → flat 'All with Scores' link present.

---

## Slice 4 — Sponsors GET_MAP audit row (012 tail)

ROUTE-PARITY.md:64 flags `'sponsors||' => ['/sponsors']` as UNKNOWN.
After Slice 1 the route exists → the redirect target no longer 404s.
No code change beyond Slice 1; audit note only.

---

## Slice 5 — Change-email decision (PARITY-007)

Legacy `user.pub.php:35-80` (verified read): distinct page
`?section=user&action=username` — lead "You are changing {name}'s Email
Address (User Name)." (admin variant) / "{user_name}: {muted current}",
form: new email input + ajax availability check + "sure" checkbox +
Change Email button; POSTs to process.inc.php section=user go=username
action=edit dbTable=users filter={admin|default} id={uid}.

Port status: merged into `/list/edit-account` (BrewerController
showEdit/saveEdit, verified: saveEdit writes user_name + brewerEmail
atomically). FORM-PARITY.md:20 documents the merge as deliberate.

**Decision to execute: restore the distinct page** — the merge loses
legacy's lead messaging, ajax availability feedback, and the admin-side
"changing X's email" flow (filter=admin handles other-user email
change). Route `GET/POST /user/username` (+ `?filter=admin&id=N` for
admins), blade `resources/views/public/account-username.blade.php`
porting user.pub.php:35-80 form + `/ajax/username` check (AjaxController
already exposes it — verify endpoint path before wiring). GET_MAP rows
`user|account|username` → `/user/username`.

**Test:** GET /user/username shows lead + form; POST with unused email
+ sure=Y updates users.user_name + brewer.brewerEmail; POST with
conflicting email → error; admin filter=admin variant loads target
user's data.

---

## Slice 6 — Import Scores un-stub (014 tail)

`DashboardController:390` `$todo('Import Scores', ...)` → real link to
the eval import surface. `EvalImportController` exists (verified:
show()/import() with consensus->import()). Verify its route path (eval
routes file) and link `$l('{path}', 'Import Scores')`. If the route is
admin-gated appropriately, done; else add gate.

**Test:** link present on dashboard; GET of the route returns 200 for
admin.

---

## Slice 7 — Public anon sidebar (PARITY-006)

**Target:** public-layout component + a new `public.partials.sidebar`
partial + a data builder (PublicController private or View composer —
decide by fewest files: View composer for `public-layout` component is
not idiomatic; instead extend `publicSalutation()` pattern — add
`sidebarData(TenantContext $ctx, Windows $w): array` in
PublicController and pass `:sidebar="$sidebar"` on every public view...
but contact/volunteers/list/pastWinners blades each pass their own
props; threading a new prop through five controllers is churn. Better:
`@once`-free anonymous blade component `<x-public-sidebar :ctx="$ctx">`
that queries its own data (sponsors count, judging locations, dropoff
windows, archives) — self-contained, no controller changes.

**Panels to port (sidebar.sec.php verified read):**
1. Past Winners (600): archive rows via ResultsRepository::archives()
   (home already uses it) + contestWinnerLink when set. Trophy icons,
   current archive bold.
2. Judging Locations (400): rows from `judging_locations` where
   prefsJudgingLocations=Y; empty → "No judging locations have been
   defined." (sidebar_text_024).
3. Account Registration (100, anon-only): open/closed state from
   Windows->registration + judge window + caps (judgeCapReached/
   stewardCapReached); "Accounts open {dates}." text, register links
   when only one role capped.
4. Entry Window (200, show_entries): totals (entries, paid) when
   ProEdition=0 + entry open, open/closed dates, limit warnings.
5. Drop-Off (300, prefsDropOff==1): link to entry-info#drop-off-locations,
   open/closed dates.
6. Shipping (500, prefsShipping==1): same pattern.
7. Competition logo img (when contestLogo set) — top of sidebar.
8. Awards launch button (sidebar.sec.php:320): when archives exist →
   `awards.php` link target _blank → port `/awards` target _blank.

**Layout change:** public-layout gets an optional `:with-sidebar`
prop; landing/list/pay/brew/user sections render the 9/3 row (legacy
index.legacy.php:217-226: col-lg-9 main + col-lg-3 sidebar). The port's
public pages are hero + full-width sections — wrap `{{ $slot }}` in the
row/column when withSidebar=true, default true for the section pages
that legacy gave a sidebar (all non-admin, non-landing pages? verify:
index.legacy.php renders sidebar for ALL public sections incl. default
→ but the port's landing is a distinct design (index.pub.php). Legacy
modernization landing (index.pub.php) has NO sidebar. So: sidebar on
contact/volunteers/sponsors/entry_info/register/login/custom_competition_info
sections only (index.legacy.php:199-210 list, verified).

**Test:** anon GET /contact contains "Account Registration" panel;
judging-locations panel when prefs on; past-winners panel when archive
rows exist. Logged-in /list shows account summary variant.

---

## Slice 8 — Judging-preferences field-matrix audit (evidence gap)

Read-only comparison: `resources/views/judging/preferences.blade.php`
option values vs legacy `admin/judging_preferences.admin.php` option
values, field by field. Record divergences in PAGE-PARITY.md. No code
change unless a wrong value pair is found (then fix + test).

---

## Slice 9 — Dashboard residual links (P1 leftovers from harness)

The run-20260829-194856 linkmaps show these MISSING on the dashboard
(admin_index.php_section_admin__admin):

- `/admin/judging/locations/create` (legacy judging&action=add)
- `/admin/output/assignments?filter=judges` (legacy staff section
  print variant) — the port's Assignments category links
  `/admin/output/assignments?filter=judges&view=name` etc.; legacy also
  emits a bare `section=staff&go=judging_assignments&action=download&
  filter=default&view=default` (staff print). Add `$l(...&action=download&view=default, 'Print')`.
- `/admin/output/assignments?filter=stewards` (legacy export-staff
  download pdf) → add `$l('...&action=download&view=pdf', 'Download')`.
- `/admin/output/labels?...psort=5160` (legacy labels-admin
  participants address labels psort=5160) — add the legacy psort value
  link (participants address labels with 5160 layout).
- `/admin/judging/flights/rounds` (legacy judging_flights
  action=assign filter=rounds) — verify port flights surface supports
  `filter=rounds` before linking.
- `/admin/results?action=publish` MISSING is EXPECTED (publish is a
  POST route; the dashboard button is a modal, not a link) — document,
  don't "fix".
- `/admin/judging/checkin` on contest-info + prefs pages — Slice 2.
- `/login` on site-preferences — verify legacy link target
  (index.php?section=login) is rendered somewhere on port prefs page
  (probably the "Log In" help link); check legacy
  site_preferences.admin.php for a section=login link and port it.

**Test:** extend AdminDashboardLinksTest activeLinks() with the new
rows.

---

## Slice 10 — Assign/entry page link residuals (harness)

MISSING on assign pages (judging/tables assign + flights):

- `/backoffice/participants?filter=judges|stewards` nav links on the
  assign blades (all four assign variants: judges/stewards/staff/
  staff&view=yes + bos).
- `/admin/judging/tables?action=assign&filter=staff` (+view=yes)
  cross-links between assign variants.
- `/backoffice/entries?filter=15` (legacy entries filter by table 15?)
  — verify semantic in legacy assign page code before adding; only
  add if the port assign blade context matches (entries filtered by
  the table being assigned).
- flights define edit link `flights/1?filter=define` and
  `UNMAPPED:/index.php?...judging_flights...flight=1` — the port flights
  blade likely needs the per-flight define/edit links.
- `UNMAPPED:/index.php?action=add&go=participants&section=admin` —
  participants Add link on staff assign page → port
  `/backoffice/participants` create surface (verify exists; else this
  is a missing surface, document).
- `UNMAPPED:/index.php?action=assign&filter=bos&view=ranked` on bos
  assign page → port `/admin/judging/tables?action=assign&filter=bos`
  (verify view=ranked param support).
- `UNMAPPED:/index.php?dbTable=default&go=judging_scores` on scores
  add page → verify legacy semantic (judging_scores default table
  view) and port equivalent.

**Rule:** every link added only after reading the legacy emit site +
port target route. No assumed semantics.

---

## Slice 11 — send_test_email + image-delete UNMAPPED (harness)

- `UNMAPPED:/admin/send_test_email.admin.php?csrf=...` (site-prefs
  email tab): legacy posts to that admin file. Port has
  `/admin/send-test-email` (parity row PASS earlier). The UNMAPPED is
  the harness seeing the legacy direct-file URL with a csrf param —
  the port blade must emit its own form/button POSTing to the port
  route. Verify port site-preferences email tab has the send button
  wired to the port route (grep send-test-email in blade).
- `UNMAPPED:/includes/process.inc.php?action=delete&go=image...` on
  upload page: legacy image-delete POST. Verify port upload blade has
  delete-image action wired to a port route (grep upload blade for
  delete).
- `UNMAPPED:/index.php?section=brew&go=entries&action=add&id=2` on
  entrant list page: add-entry link → port `/brew?...` add surface
  (verify route).
- `UNMAPPED:/index.php?section=brewer&go=admin&action=edit&filter=1`
  on (admin viewing) entrant list → port brewer edit surface.

Each: read legacy emit site, read port blade, wire the port route.
Where the port route doesn't exist, STOP and record in
MISSING-FUNCTIONALITY instead of inventing.

---

## Slice 12 — BOS pullsheet + entries/participants output residuals (harness)

- `/admin/output/pullsheets?go=judging_scores_bos&id={1,2,3}` on bos
  enter/edit pages (3 pages) — legacy emits per-BOS-style-type pullsheet
  links. Add to the BOS entry/edit blade(s) where legacy does.
- `/admin/output/participants?filter=with_entries&view=default` on
  backoffice participants page — add link to participants blade where
  legacy emits it (verify legacy participants.admin.php emit site).

**Test:** the pages render with the links (extend the relevant
AdminScreens test).

---

## Slice 13 — Verification

1. Full suite (`php artisan test`) — green.
2. Parity harness — missing links < 39 unique; record count.
3. Export byte-parity — PASS.
4. HTML tag-balance on dashboard + sponsors + sidebar blades — no new
   errors vs baseline 15.
5. Update PARITY-BACKLOG statuses (006/007/012/015 + residuals) and
   commit docs/parity/ if user approves (standing offer).

## Slice ordering (dependencies)

1 → 2 → 3 (independent, controller/blade-only) can go in any order;
7 depends on nothing but touches layout — do after 1 (sponsors partial
shared). 5 restores a page, independent. 9/10/11/12 are residual-link
batches keyed to harness evidence; each preceded by reading the legacy
emit site. 13 last.
