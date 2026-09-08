# BROKEN-LINKS

Systematic search per audit §9. Method: extracted every `<a href>` from both
dashboard raw artifacts (760 legacy / 684 port `<a>` tags), matched via
urls.txt contract + LegacyRedirectController dynamic handlers + param-set
normalization. Redirect contract itself: 163/163 (`LegacyUrlRedirectTest`).

## Static search (port codebase)

- `href="…php"` / `action="…php"` in `resources/views/`: **0** live links.
  All `.php` string hits are source-location comments (e.g.
  `index.legacy.php:90-104`) documenting provenance.
- `window.location` / `location.href|replace` in views+`resources/js/app.js`:
  all route through `route()`/`url()` helpers — clean.
- Controllers redirect via named routes — no hard-coded legacy paths in `app/`.

## GET_MAP dead target

| Source | Target | Cause | Fix | Status |
|---|---|---|---|---|
| LegacyRedirectController GET_MAP `'sponsors\|\|'` | `/sponsors` | route never created; legacy sponsors is a home anchor + `section=sponsors` sidebar page | create `/sponsors` page or retarget map to `/` `#sponsors` | **OPEN (PARITY-012)** |

## Dashboard true missing links (23 + 1 behavioural)

After contract/dynamic/param-set matching, these legacy dashboard links have
no port counterpart:

| # | Legacy link | Count | Notes |
|---|---|---|---|
| 1 | `awards.php?view={light,dark,blue}&go=table-*` | 11 | PARITY-001 — page unported |
| 2 | `includes/process.inc.php?action=publish` | 1 | PARITY-003 — action unported; PROCESS_MAP 302s to /admin/archive placeholder |
| 3 | `output.inc.php?section=assignments&go=judging_assignments&filter={judges,stewards}&location=1&view={name,table}` | 4 | per-session assignment views |
| 4 | `output.inc.php?section=assignments&go=judging_assignments&filter={judges,stewards}&view=location` | 2 | by-location view |
| 5 | `output.inc.php?section=table-cards&go=judging_tables&psort=sorting-tables{,&view=master-list}` | 2 | sorting tables cards |
| 6 | `output.inc.php?section=table-cards&go=judging_tables&action=default&filter=default&view=default&id=1` | 1 | per-table variant |
| 7 | `output.inc.php?section=export-results&go=judging_scores_bos&action=download&filter=default&view={pdf,html}` | 2 | BOS results download |
| 8 | `output.inc.php?section=export-staff&go=judging_assignments&action=download&filter=default&view=pdf` | 1 | staff download |
| 9 | `output.inc.php?section=staff&go=judging_assignments&action=download&filter=default&view=default` | 1 | staff print variant |
| 10 | `output.inc.php?section=inventory&go=scores` | 1 | inventory with-scores variant |
| 11 | `includes/process.inc.php?section=logout&action=logout` | 1 | port logs out via POST route (CSRF) — behavioural replacement, not broken |

(Note: `index.php?section=list#entries` — port account-main has
`<section id="entries">`; link emitted and valid. False positive, verified
in view source.)

(The labels matrix — 306 legacy label-count hrefs — matches fully by
param-set; harness MISSING rows for it are param-order noise, fixed by
matching on the (go,action,filter,psort,sort) tuple.)

## Harness missing links (run-20260829-073729: 61 raw MISSING rows)

Raw rows overcount for the same param-order reason. Grouped real issues:

- Admin dashboard: 25 raw rows → 24 true misses (table above).
- Judging assign pages: 4 rows each — `participants?filter=judges/stewards`,
  `entries?filter=N`, `tables assign staff` not emitted on port assign
  pages (PARITY-004/005).
- Remaining ~16 single rows on brewer-edit/change-password/quick-register
  pages: same class (admin-chrome action links not emitted).

## Legacy-side broken links (informational)

Legacy "Report an Issue" links to upstream GitHub — kept verbatim in port
(correct). Legacy dashboard also links avery.com/onlinelabels.com product
pages — port keeps them.
