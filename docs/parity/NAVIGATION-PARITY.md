# NAVIGATION-PARITY

Navigation audit: what legacy renders, what the port renders, and the deltas.
Evidence: legacy `pub/nav.pub.php` (public navbar), `sections/nav.sec.php`
(admin off-canvas "Admin Essentials" menu + admin navbar), `sections/sidebar.sec.php`
(public sidebar), `admin/default.admin.php` + `pub/admin-nav.pub.php`
(dashboard widgets); port `resources/views/components/public-layout.blade.php`
(both navs + sidebar slot).

## Legacy navigation structure (source of truth)

### Public navbar (all non-admin pages, `nav.pub.php`)

Fixed-top dark navbar, brand = home icon. Items right-aligned (`ms-auto`):

```
[home icon]  …spacer…  Rules*  Volunteers*  Entry Info**  Other Info***  Sponsors*  Contact  [Past Winners****]  [Admin]  [globe lang]  [user ▾]  [logout icon]
*  only while judging not started/past (window-dependent)
** only while judging past
*** only if custom_competition_info.pub.php exists
**** only if archives exist — opens offcanvas, not a page
[Admin] only for admin users
[user ▾]: My Account, Entries, Add Entry*, Pay, [Judging Dashboard‡], ─, Change Email, Change Password, ─, Auto Log Out countdown
‡ prefsEval==1 + judge assignment + judging open
anon: "Log In" opens #login-modal (shell modal, not a page)
```

### Public sidebar (`sidebar.sec.php`, anon section pages via index.legacy.php:224)

Right column (col-lg-3) on sections rendered through index.legacy.php:
`default, entry, contact, volunteers, sponsors, register, login, past-winners,
competition`. Panels in render order:

1. **Launch Awards Presentation** button (awards.php) — only when judging_scores rows exist
2. **Judging Location(s)** panel (info) — per-location name, map-marker link, dates
3. **Non-Judging Location(s)** panel (info)
4. **Account Registration** panel (success/danger by window state) + judge/steward register links
5. **Entry Registration** panel (success/danger) + entry counts
6. (300 block) drop-off locations panel
7. **Entry Shipping** panel (prefsShipping==1)
8. **Past Winners** panel (archive trophy links / external winner link)
9. Competition logo at top when set

### Admin off-canvas menu (`nav.sec.php:158-274`, admin users only)

Right-fixed inverse "Admin Essentials Menu":

```
Admin Dashboard
├── Competition Preparation          (userLevel 0 only)
│   ├── Edit All Competition Dates      → go=dates
│   ├── Edit Competition Info           → go=contest_info&action=edit
│   ├── Manage Contacts                 → go=contacts
│   ├── Manage Custom Categories        → go=special_best
│   ├── Manage Drop-Off Locations       → go=dropoff
│   ├── Manage Judging Sessions         → go=judging
│   ├── Manage Non-Judging Sessions     → go=non-judging
│   ├── Manage Sponsors                 → go=sponsors
│   ├── Manage Styles Accepted          → go=styles
│   ├── Manage Style Types              → go=style_types
│   └── Upload Logo Images              → go=upload
├── Entries[, Payments,] and Participants
│   ├── Manage Entries                  → go=entries
│   ├── Entry Count By Style            → go=count_by_style
│   ├── Entry Count By Sub-Style        → go=count_by_substyle
│   ├── [Manage Payments]               → go=payments (PayPal IPN pref)
│   ├── Manage Participants             → go=participants
│   ├── Assign Judges                   → go=judging&action=assign&filter=judges
│   ├── Assign Stewards                 → go=judging&action=assign&filter=stewards
│   ├── Quick Register a Judge          → go=judge&action=register&view=quick
│   └── Quick Register Steward          → go=steward&action=register&view=quick
├── Sorting
│   ├── Manually                        → go=entries
│   ├── Entry Check-in Via Barcode Scanner → go=checkin (barcode pref + !obfuscate)
│   └── Entry Check-in Via Mobile Devices  → qr.php (target=_blank)
├── Organizing
│   ├── Manage Tables                   → go=judging_tables
│   ├── Assign Judges/Stewards to Tables → go=judging_tables&action=assign
│   └── Add BOS Judges                  → go=judging&action=assign&filter=bos
├── Scoring
│   ├── Upload Scoresheets              → go=upload_scoresheets
│   ├── Manage Entry Evaluations        → go=evaluation (prefsEval)
│   ├── Manage Scores                   → go=judging_scores
│   └── Manage BOS Entries and Places   → go=judging_scores_bos
├── Reports                            (fancybox iframes!)
│   ├── Table Cards                     → output.inc.php?section=table-cards
│   ├── Pullsheets (Entry/Judging Nos.) → output.inc.php?section=pullsheets
│   ├── BOS Pullsheets                  (judging started)
│   ├── BOS Cup Mats (both number kinds)
│   ├── Winners with/without Scores     (judging started)
├── Data Management                    (userLevel 0)
│   ├── Manage Archives                 → go=archive
│   └── Archive Current Data            → go=archive&action=add
├── Preferences                        (userLevel 0)
│   ├── General / Entry / Email Sending / Currency and Payment / Best Brewer and/or Club
│   └── Judging/Competition Organization → go=judging_preferences
└── Report an Issue                    (external GitHub link)
```

### Admin dashboard (`admin/default.admin.php`)

Widget grid expanding the off-canvas menu with all options + direct output
links (labels, bottle labels, judge labels, participant summaries, staff
points, export…) + **Awards Presentation launch links (Light/Dark/Blue-Green ×
3 sort orders = 9+ links to awards.php)** + results publish button.

## Port navigation (public-layout.blade.php)

- Admin side: inverse fixed navbar (Home, print icon, user dropdown, admin
  off-canvas) + off-canvas menu — structure matches legacy; 58 `<li>` items.
  Gating conditions reimplemented from nav.sec.php (`adminNavLevel0`,
  `adminNavObfuscate`, `adminNavJudgingStarted`, `adminNavEval`,
  `adminNavBarcode`).
- Public side: fixed dark navbar with Rules/Volunteers/Entry Info/Sponsors/
  Contact anchors, Admin link, user dropdown, logout — matches legacy shape.
- Login/reset forms render as `<dialog>` modals opened via `data-open-modal`
  (legacy: Bootstrap modal + shell modal). Behavioural equivalent.

## Deltas

| # | Nav element | Legacy behaviour | Port behaviour | Status |
|---|---|---|---|---|
| N1 | Past Winners offcanvas | navbar button opens `#archive-list` offcanvas with archive links | **MISSING** — no Past Winners nav item at all in public-layout | FAIL |
| N2 | Language toggle (globe) | dropdown when prefsLanguageToggle + >1 language | **MISSING** — no language dropdown | FAIL (scope: port has lang/en/site.php only; legacy has de/es/fr/hu/pt…) |
| N3 | User ▾ → Add Entry | shown when window open + limits not reached; link `brew`+user id | present, gates match (`navWindows`) | PASS |
| N4 | User ▾ → Judging Dashboard | prefsEval + judge assignment + window | **MISSING** from port user dropdown (exists on account page only) | PARTIAL |
| N5 | User ▾ → Change Email | distinct page `user&action=username` | links `/list/edit-account` (merged form) | PARTIAL — deliberate, documented |
| N6 | User ▾ → Auto Log Out countdown | dropdown footer with live `#session-end` | **MISSING** in dropdown (session modals exist on admin side only) | PARTIAL |
| N7 | Admin off-canvas → Reports rows | open `output.inc.php` URLs in **fancybox iframes** | link to `/admin/output/*` routes directly (same tab) | PARTIAL — behaviour differs (no lightbox); content equivalent |
| N8 | Admin nav → Sorting → mobile check-in | `qr.php` target=_blank | **MISSING** (qr.php unported) — nav row omitted | FAIL |
| N9 | Dashboard → Awards Presentation links | `awards.php` × view/sort variants, target=_blank | **MISSING** (PARITY-001) | FAIL |
| N10 | Sidebar (anon section pages) | 9 panels per sidebar.sec.php | **MISSING** — port anon pages render hero/salutation only, no sidebar column | FAIL (PARITY-006) |
| N11 | Admin navbar print icon | legacy print icon (window.print) | present? admin navbar in port — verify `print` handler exists | UNKNOWN |
| N12 | Admin navbar Help icon | legacy `bcoem_help()` per section | not found in port layout | UNKNOWN — verify |
| N13 | Dashboard assign-page filter links | `participants&filter=judges/stewards`, `entries&filter=N` | harness MISSING: port links `/backoffice/participants?filter=judges` etc. not emitted on assign pages | FAIL (PARITY-004/005) |
| N14 | Salutation "Welcome {first}!" | shows on non-landing pages when logged in | port renders salutation band; entrant list diff shows wording/order deltas ("My Account" vs "Account Info", "Edit Account Info" label placement) | PARTIAL — residual content diffs |

## Active states

Legacy marks current section by replacing the nav link with `#` + disabled
(`class="disabled"` on dropdown items; `$link_list = "#"` pattern).
Port: `request()->routeIs('list') ? 'disabled' : ''` — equivalent pattern
present for My Account/Add Entry. Not exhaustively verified per item —
UNKNOWN for Contact/Sponsors anchors (legacy toggles `#` only on section
pages, which the port doesn't have for sponsors).
