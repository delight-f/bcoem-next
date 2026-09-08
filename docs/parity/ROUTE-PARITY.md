# ROUTE-PARITY

Legacy dispatch: `index.php?section=X&go=Y&action=Z&filter=W&id=N` (GET) and
`includes/process.inc.php?...&dbTable=...&action=...` (POST). Port dispatch:
clean Laravel routes. Contract enforced by `LegacyRedirectController`
(GET_MAP 150 entries, PROCESS_MAP, dynamic() handlers) and tested by
`LegacyUrlRedirectTest` (163/163 pass at `830a0ae`).

## Method

Every legacy entry point was enumerated from: `index.legacy.php` (admin
dispatch table lines 111-163), `index.pub.php` (public sections lines
403-567), `admin/*.admin.php` (36 files), `output/*.output.php` (21 files),
`sections/*.sec.php` (31 files), `pub/*.pub.php` (45 files), plus top-level
entry scripts (`awards.php`, `qr.php`, `ppv.php`, `maintenance.php`,
`setup.php`, `update.php`, error pages `400/401/403/404/500.php`).
Port routes enumerated from `php artisan route:list` (174 GET/POST routes
across 10 route files).

Status counts (coverage matrix summary — full 777-row mapping lives in
`tools/parity/urls.txt`, machine-checkable):

```
PASS     777 mapped URLs (redirect + harness verified; 61 missing-link residuals below)
MISSING  3 legacy entry scripts with no port equivalent (awards.php, qr.php, ppv.php)
MISSING  1 admin action (results publish)
```

## Top-level legacy entry scripts

| Legacy | Port | Status | Notes |
|---|---|---|---|
| `index.php?section=…` | `/`, `/index.php` → LegacyRedirectController → clean URLs | PASS | 150-entry GET_MAP; `?section=default` renders home |
| `includes/process.inc.php` | POST/GET `/includes/process.inc.php` → LegacyRedirectController::process | PASS | PROCESS_MAP (login/logout/delete/etc.) |
| `includes/output.inc.php` | `/admin/output/{doc}` (22 routes) | PASS | PDF/HTML outputs; excluded from page-diff (binary), validated by content tests + export byte-parity leg |
| `awards.php` (+`?view=black/blue`, `?go=table-*)` | — | **MISSING** | Awards presentation screen (reveal.js themes white/black/blue). 12 dashboard links unmapped. Admin-gated or public-after-publish (`msg=7` redirect otherwise) |
| `qr.php` | — | **MISSING** | Mobile entry check-in standalone page (`target=_blank` from admin nav) |
| `ppv.php` | — | **MISSING** (UNKNOWN scope) | 12.5KB; not linked from audited nav surfaces; possibly pay-per-view legacy |
| `maintenance.php` | — | MISSING (deliberate?) | Legacy `?section=maintenance` renders through index; port has no maintenance mode surface — UNKNOWN whether in scope |
| `400/401/403/404/500.php` | Laravel error views | PARTIAL | Legacy renders numeric sections through `index.pub.php`; port has `welcome.blade.php` fallback — visual parity unverified |
| `setup.php` / `update.php` | — | N/A | Installer/updater; one-time tooling, not user-facing screens |

## Public sections (index.pub.php dispatch)

| Legacy section | Port route | Status |
|---|---|---|
| `sponsors` (section) | GET_MAP → `/sponsors` | **FAIL** — legacy renders sponsors as a home anchor (`#sponsors`, nav.pub.php:113) AND as standalone `section=sponsors` via index.legacy.php:206 (sidebar layout); port renders anchor only and **has no `/sponsors` route** — the GET_MAP redirect target 404s. Legacy `sections/sponsors.sec.php` sidebar page has no port equivalent |
| `past-winners&go={suffix}` | `/past-winners/{filter}` | PASS (suffix sanitized alnum) |
| `login` | `/login` | PASS |
| `login&go=password&action=forgot/verify/reset-password` | `/forgot-password`, `/forgot-password/verify`, `/reset-password` | PASS |
| `register&go={entrant,judge,steward}` | `/register/{go}` | PASS |
| `list` | `/list` (auth) | PASS |
| `brew&action=add` | `/brew` (auth) | PASS |
| `brew&action=edit&id=N` | `/brew/{entry}/edit` | PASS |
| `entries` delete | POST `/entries/{id}` | PASS (legacy msg=5 code kept) |
| `brewer&go=account&action=edit` | `/list/edit-account` | PASS (merged email change) |
| `brewer&go=profile` | `/list/edit-clubs` | PASS |
| `list&go=account` (form 2) | `/list/edit-judging` | PASS |
| `user&go=account&action=password` | `/user/password` | PASS |
| `user&go=account&action=username` | `/list/edit-account` | **PARTIAL** — legacy had a distinct change-email page; port folds into account form. Deliberate merge, documented in GET_MAP comment |
| `pay` | `/pay`, `/pay/cancel`, `/pay/checkout`, `/pay/callback` | PASS |
| `contact` | `/contact` (+POST store) | PASS (content divergence — see PAGE-PARITY) |
| `volunteers` | `/volunteers` | PASS (content divergence — see PAGE-PARITY) |
| `sponsors` | GET_MAP `sponsors||` → `/sponsors` | **UNKNOWN** — port has no `/sponsors` route in route:list; GET_MAP entry would 404. Verify |
| `entry` (entry_info) | GET_MAP → `/` (anchor) | PASS |
| `competition` | GET_MAP → `/` | PARTIAL — legacy includes `custom_competition_info.pub.php` if present; port anchors home. Custom file has no port equivalent |
| `evaluation` | `/eval` (+5 subroutes) | PASS |
| `judge` (quick signup) | `/judge` | PASS |
| numeric error sections (400-500) | — | MISSING (see top-level table) |

## Admin pages (index.legacy.php dispatch, go=…)

All 36 `*.admin.php` pages have port equivalents — verified through the
GET_MAP + harness coverage (admin corpus: 731 URL pairs, all fetch 200).
Residuals:

| Legacy go= | Port | Status | Notes |
|---|---|---|---|
| `checkin` | `/admin/judging/checkin` | PASS | barcode check-in ported |
| `entries&action=add&filter=N` | `/brew?filter=N` | PASS | admin entry-add reuses brew form |
| `brewer&action=edit` | `/backoffice/participants` | PASS | |
| `user` (purge) | `/admin/purge` | PASS | |
| `make_admin` | folded into participants edit | PASS | |
| `change_user_password` | `/admin/users/{id}/password` | PASS | |
| `archive&action=add` | `/admin/archive?action=add` | PASS | |
| `preferences&action={entries,email,payment,best}` | `/admin/site-preferences/{go}` | PASS | |
| **(dashboard) results publish** | `/admin/results?action=publish` | **MISSING** | legacy `process.inc.php?action=publish`; harness MISSING link |
| `judging&action=assign&filter={judges,stewards,staff,bos}` | `/admin/judging/pool-assign?filter=…` | PASS | pool role assignment screen (legacy judging_locations.admin.php action=assign); per-table links use `admin.judging.assign.show` route |

## Output documents (includes/output.inc.php)

22 port routes `/admin/output/{doc}` cover all 21 legacy `.output.php` files
(+export). Byte-parity for CSV export verified by `fetch_export.sh` leg;
PDF/label outputs validated by Feature tests (`%PDF` magic + payload asserts).
`maps.output.php` maps to `outputs.maps` — route exists but shows `..` name in
route:list dump; verify controller binding.

## AJAX endpoints

Legacy `ajax/*.ajax.php` → port `/ajax/{username,valid-email,account-checks,save,count-records,custom-style}`
(POST, CSRF-hardened; legacy status=9 envelopes preserved). PASS.

## Stripe (port-only)

`/webhooks/stripe`, `/admin/stripe*` are port additions replacing legacy
PayPal IPN + config-file payment gateway wiring. Legacy `pay.sec.php` PayPal
flow maps to `/pay/checkout`. Documented replacement, not a parity gap.
