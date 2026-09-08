# FORM-PARITY

Form inventory + comparison. Field-level contract is enforced by the harness
word-stream diff (labels are content) and the Feature suite (806 passing),
so this doc records structural findings and remaining deltas rather than
re-listing every field.

## Form inventory (legacy → port)

| Form | Legacy source | Port | Field parity | Validation | Submit target |
|---|---|---|---|---|---|
| Login | `login.pub.php` modal + `?section=login` | `<dialog>` modal + `/login` page | PASS (email+password, floating labels) | inline invalid-feedback text ported | POST `/login` (legacy: process.inc.php?section=login&action=login) |
| Forgot password (3-step) | login modal cards | `forgot-modal` dialog → `/forgot-password` verify → `/reset-password` | PASS | ajax email checks (`/ajax/valid-email`, `/ajax/username`) ported, envelopes kept | PASS |
| Register (entrant/judge/steward) | `register.pub.php` (71KB) | `/register/{go}` | PASS (harness corpus: entrant clean; judge/steward chrome residual) | server+ajax username/email checks | POST `/register/{go?}` |
| Add/edit entry | `brew.sec.php` | `/brew`, `/brew/{id}/edit` (+`?filter=N` admin variant) | PASS | required-style-field gate (msg=1-style), caps msg=8/9 | PASS |
| Edit account (form 0) | `brewer_form_0` | `/list/edit-account` | PASS-with-residual | PASS | PASS |
| Clubs/profile (form 1) | `brewer_form_1` | `/list/edit-clubs` | PASS | PASS | PASS |
| Judging prefs (form 2) | `brewer_form_2` | `/list/edit-judging` | PASS | PASS | PASS |
| Change password | `user.sec.php` | `/user/password` | PASS | PASS | PASS |
| Change email | `user.sec.php` action=username | **MERGED into /list/edit-account** | PARTIAL — distinct page gone (documented) | PASS | PASS |
| Pay | `pay.sec.php` (PayPal + confirm modal) | `/pay` + `/pay/checkout` (Stripe) | PARTIAL — gateway swap is the documented replacement; confirm modal (`#confirm-submit`) semantics = Stripe redirect | PASS | PASS |
| Contact email | process.inc.php dbTable=contacts&action=email | POST `/contact` | PASS (ContactMail) | PASS | PASS |
| Judge/steward quick signup | `judge.sec.php` | `/judge` | PASS | PASS | PASS |
| Eval scoresheet | `evals/*` | `/eval/scoresheet/{id}` + POST `/eval/process` | PASS (structured/full/NW-cider variants ported) | PASS | PASS |
| Admin CRUD forms (contacts, dropoff, locations, tables, styles, style_types, sponsors, special_best, mods, archive, preferences ×5, dates, contest_info, purge, make_admin, change_user_password, send_test_email) | `admin/*.admin.php` | `/admin/*` routes | PASS (all in corpus, fetch clean) | Feature-tested | PASS |
| Entries admin (mark paid, edit, delete, mark-all) | entries.admin.php | `/backoffice/entries*` | PASS | PASS | PASS |
| Participants admin | participants.admin.php | `/backoffice/participants*` | PASS (make_admin folded into edit) | PASS | PASS |

## Structural notes

- Legacy posts go to `includes/process.inc.php?dbTable=X&action=Y`; the port
  maps each to RESTful POST/PUT/DELETE on the clean route and keeps the
  legacy redirect targets + `msg=` codes (redirect contract test).
- CSRF: legacy had none; port adds Laravel tokens (documented hardening, not
  a parity break).
- Required-field UX: legacy uses the `#form-submit-button-disabled-msg-required`
  modal + red-star marking (chrome-excluded from diffs). Port: verify the
  equivalent disabled-submit + modal behaviour exists — **UNKNOWN**.
- Client-side date/time pickers on admin forms: legacy eonasdan; port
  flatpickr. Field names/format strings (prefsDateFormat/prefsTimeFormat)
  must keep producing identical stored values — covered by tests for dates
  page; spot-check others.

## Gaps → backlog

| ID | Gap |
|---|---|
| PARITY-007 | Change-email distinct page (merged) — decide: restore `/list/edit-email` page or keep merge (current GET_MAP documents it) |
| FORM-1 | Required-info modal + disabled submit on port public forms — verify/implement |
