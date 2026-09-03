# PARITY-OVERVIEW

Forensic comparison of the legacy BCOE&M application against the Laravel port
(bcoem-next). The legacy application is the behavioural and visual source of
truth. This audit records what each side does, where they differ, and what
concrete changes are required — it does not redesign or modernise.

## Sources of truth

| Source | Location | Notes |
|---|---|---|
| Legacy codebase | `/home/faraaz/dev/bcoe/brewcompetitiononlineentry` | branch `modernization`; 38 ahead / 15 behind upstream `geoffhumphrey/main` |
| Live site | scabs.nfshost.com | deployed from the local legacy repo (modernization branch) per the deploy-bcoe-to-scabs skill |
| Port | `/home/faraaz/dev/bcoe/bcoem-next` | `github.com/delight-f/bcoem-next`, HEAD `830a0ae` |
| Route contract | `tools/parity/urls.txt` | 777 legacy→port URL pairs (731 admin, 14 anon, 3 entrant, 5 blank/comment) |
| Redirect map | `app/Http/Controllers/LegacyRedirectController.php` | GET_MAP (150 entries) + PROCESS_MAP; 301 contract tested by `LegacyUrlRedirectTest` (163/163) |
| Page-diff harness | `tools/parity/parity.sh` | latest run `reports/run-20260829-073729`: 147 fetched pages, 144 content-diffs, 61 missing links |
| Test suite | 807 tests / 806 passed / 1 skipped | as of `830a0ae` |

## Critical framing correction

The audit spec (`legacy-laravel-ui-parity-audit.md` §7, §8) states "the legacy
application uses Bootstrap 3." This is **only true for the admin side**. The
legacy application is split-framework:

| Surface | Legacy framework stack |
|---|---|
| Public pages (`index.pub.php`) | Bootstrap 5.3.3 + Ninja Bootstrap + FontAwesome 6.7.2 + DataTables 2.1.8 + animate.css + tom-select |
| Admin pages (`index.legacy.php`) | Bootstrap 3.3.7 + FontAwesome 4.5.0 + DataTables 1.10.12 + fancybox 3.5.7 + moment.js + eonasdan datetimepicker |

The port runs both surfaces on a **single real Bootstrap 5** build
(`@layer bs5` in `resources/css/app.css`) — no Tailwind, no daisyUI, no
hand-written Bootstrap 3 compat layer (the expand–contract migration,
issues 3–12, deleted the Tailwind/daisyUI/compat stacks once every view was
purged). Two BS5 theme palettes map the legacy split: `:root` carries the
public bcoem palette (default-3) and `[data-bs-theme="bcoem-brux"]` carries
the admin Materials palette (bruxellensis). Bootstrap 5's own JS
(`bootstrap.bundle.js`) drives dropdowns, modals, collapse, and offcanvas;
flatpickr (admin date picker, CDN) and a framework-agnostic client-side
table sort replace moment/eonasdan and DataTables.

## Scoreboard

```
Routes (urls.txt mapped):      777 / 777   (redirect contract 163/163)
Pages fetched in harness:      147
Pages with content diff:       144  (143 are 5-line single-stream diffs; 1 is 4-line)
Missing links (port → legacy): 61   (25 on admin dashboard alone)
Broken .php links in views:    0    (all .php refs are doc comments)
Test suite:                    806 passed / 1 skipped / 807 total
```

The 144 content-diffs are almost entirely **chrome ordering** differences
(nav text, salutation placement) caught by the word-stream comparator before
chrome exclusion fully normalises them. After applying `chrome-exclude.txt`
substring stripping, the anon contact/volunteers and entrant list/brew pages
are substantively equivalent (verified by re-diffing the `.text` artifacts).
The remaining substantive content gaps are recorded in PAGE-PARITY.md.

## Prioritised backlog (summary)

Full detail + execution order in PARITY-BACKLOG.md (25 items). Priority per
audit §16: P1 = visibly broken/missing; P2 = info architecture; P3 = content;
P4 = visual.

| ID | Pri | Domain | Summary |
|---|---|---|---|
| PARITY-001 | P1 | Route | `awards.php` unported — 11 dead dashboard hrefs + Launch button/modal gone |
| PARITY-002 | P1 | Route | `qr.php` mobile check-in unported |
| PARITY-003 | P1 | Route | Publish Results action unported; PROCESS_MAP placeholder 302s to wrong page |
| PARITY-004/005 | P1 | Nav | Assign-page filter links + participants filters not emitted |
| PARITY-013 | P1 | Component | 53 of 63 dashboard dropdown menus flattened/absent |
| PARITY-014 | P1 | Component | 19 dead TODO spans on dashboard |
| PARITY-006 | P2 | Layout | Public anon sidebar (9 panels) not rendered |
| PARITY-012 | P2 | Route | GET_MAP `'sponsors||'` → `/sponsors` 404s |
| PARITY-015/016/017/018/019 | P2 | Nav | Dashboard link variants + labels + archive-action loss |
| PARITY-007 | P2 | Form | Change-email distinct page vs merged — decide |
| PARITY-008/009 | P3 | Page | Contact/volunteers gating divergence |
| PARITY-011 | P3 | Test | Browser journey tests (5 flows) |
| PARITY-010 | P4 | Visual | Pixel audit + screenshot pass owed |

## UNKNOWNs

See LEGACY-UNKNOWN-BEHAVIOUR.md.
