# P3.7 — AJAX endpoints (username, valid_email, account_checks, save, count_records)

> Part of spec §5 P3.7. Legacy surface: `ajax/username.ajax.php`,
> `ajax/valid_email.ajax.php`, `ajax/account_checks.ajax.php`,
> `ajax/save.ajax.php`, `ajax/count_records.ajax.php` — port each as a
> Laravel JSON route with the same request/response contract (status codes,
> message strings are parity surface).

Status: done (P3.7 port complete; parity note below)
Phase: 3 (Slice B)
Depends on: P3.1b (registration consumes username/valid_email),
P3.1a (account_checks needs auth), P3.2a–c (save feeds brewer forms),
P3.3a (count_records feeds brew page)
Consumes: ledger/registration-rules.md (count semantics)

## Goal

The five endpoints the slice's forms call, with identical observable
behavior to the legacy `.ajax.php` files.

## Scope

| Endpoint | Legacy source | Behavior to port |
|---|---|---|
| `username` | username.ajax.php | availability check for `users.user_name` (email) — exact response shape + message |
| `valid_email` | valid_email.ajax.php | email format/domain validation as legacy defines it (the vendored `is_email` lib is NOT ported per §9 — replicate its decisions server-side or swap to Laravel validation + document deviation) |
| `account_checks` | account_checks.ajax.php | per-field checks on the brewer/entry forms (read the file — likely email/username/duplicate guards) |
| `save` | save.ajax.php | the wizard's AJAX save (draft persistence) — port the write shape |
| `count_records` | count_records.ajax.php | count queries behind the entry-status cards (received/paid/etc. — registration-rules ledger count semantics) |

- All endpoints: CSRF-protected POSTs (legacy had none — this is a port
  hardening; document), JSON responses, auth required where legacy required
  a session.
- The registration/brew forms must consume the ported endpoints (swap the
  JS), keeping legacy client-side validation messages.

## Deliverables

1. Five JSON routes + controllers; JS wiring in the slice's forms.
2. Feature tests per endpoint: success, failure, auth, CSRF.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Each endpoint's response body matches legacy for the same input on a
  corpus dump (content parity via curl + normalize, documented in the
  ticket's parity note).

## Parity note (P3.7)

Ported to `app/Http/Controllers/AjaxController.php` + five POST routes
(`/ajax/username`, `/ajax/valid-email`, `/ajax/account-checks`,
`/ajax/save`, `/ajax/count-records`). Tests:
`tests/Feature/AjaxEndpointsTest.php`.

**Envelope**: legacy's consumed output was the raw HTML fragment (its JS
injected `responseText` straight into `#username-status` / `#msg_email`);
the `action=json` envelope modes in username/valid_email were dead code
(no caller). The port wraps the same fragments byte-for-byte in JSON
`{status, message, errors}`; forms read `message` as HTML. Status codes
keep legacy semantics: 1 ok / 0 rejected input / 9 no session.

- **username** — full parity: normalize → invalid email degrades to the
  "POST variable empty" branch (legacy queried with `false` and fell
  through); ≥3-char strlen check; span fragments verbatim.
- **valid_email** — vendored `is_email` dropped per spec §9;
  `FILTER_VALIDATE_EMAIL` replicates the first half of the pipeline.
  Documented deviation: addresses is_email rejects but PHP accepts would
  differ (none observed in practice). GET → POST (hardening).
- **account_checks** — `action=email` + `action=username&go=default`
  ported with `<p>`-style fragments verbatim. `go=forgot` /
  `check_answer` superseded by the P3.1c password-reset routes;
  `go=change` feeds the change-email UI deferred with the admin account
  surface — both return an inert `{status:"0"}`.
- **save** — `action=brewing` only (the six admin inline fields);
  evaluation/sponsors/judging_staff/judging_scores are P4/P5 surface.
  Response envelope verbatim including the always-empty `query` key
  (legacy echoed `$sql` but never assigned it) and `id` as a string.
  Write shape preserved: empty→NULL (`''` when rid2=text-col), "0"→NULL,
  else sterilized input; brewUpdated stamped every save. Hardening:
  column allow-list fails fast with error_type 3 instead of letting an
  arbitrary column fail SQL; session + userLevel≤1 gates live in the
  controller so anonymous hits get legacy's status "9", not a redirect.
  Legacy's Referer check replaced by Laravel CSRF.
- **count_records** — all three sections ported (evaluation included;
  its table exists in baseline). Allow-lists verbatim; response shape
  verbatim (`updated` set on success only). total-fees/total-fees-paid
  port the bid=default/filter=default branch of common.lib.php
  `total_fees()` incl. early-entry discount, brewer special rate, cap;
  `$paidOnly` adds legacy's brewPaid='1' filter. Query-failure messages
  carry the exception text where legacy carried MysqliDb's last error.

**Auth**: only `save` required a real login in legacy (the other four
checked the always-bootstrapped `session_set_*` flag that every visitor
gets), so no auth middleware on these routes; save's gates are enforced
in-controller. Candidate future hardening: count_records exposes entry
counts publicly, exactly like legacy.

**CSRF hardening**: all five are same-site POSTs under the web group's
token check (legacy had none; save used a spoofable Referer check).
The register form consumes username + valid_email via fetch with the
form's CSRF token, keeping legacy element ids and fragment markup.
