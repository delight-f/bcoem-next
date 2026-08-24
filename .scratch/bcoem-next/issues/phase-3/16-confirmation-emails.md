# P3.6 — Confirmation emails (registration, pay, admin notify)

> Part of spec §5 P3.6. Legacy surface: `includes/process_email.inc.php`
> (mail templates + send conditions), lang `email_*` strings, the legacy
> mailer config (SMTP). Transport = Laravel mailer (new); templates ported.

Status: done
Phase: 3 (Slice B)
Depends on: P3.1b (registration), P3.5d (pay success), P3.1c (reset mail —
  can land first if the mailer ships here)
Consumes: ledger/payments.md (pay-confirm content)

## Goal

Port the three email families with legacy content and send conditions:

1. **Registration confirm** — to the new user on wizard completion (pin
   whether legacy sends to admin too; check `process_brewer.inc.php`).
2. **Pay confirm** — to the entrant on payment success (legacy IPN-era
   content; the pay-confirm wording is parity surface — port text, adapt
   provider name: "payment received" not "PayPal").
3. **Admin notify** — organizer notified of new registrations/payments when
   prefs flag on (`prefsNotifyAdmin`-style pref — pin exact column).

## Scope

- Mail templates as Blade Mailable/Notification classes; HTML + plain-text
  parts; legacy placeholders (name, comp name, entry list, amounts) mapped
  to view data.
- Send conditions + recipient lists from `process_email.inc.php` exactly
  (which events, which prefs gate them, dedupe/rate-limit quirks).
- SMTP via Laravel mailer config; `MAIL_MAILER` stays `array`/`log` in tests
  (assert mailable content via `Mail::fake()` + assertSent).
- The pay-confirm must NOT reference PayPal (D7) — the ledger/payments
  decision table is the oracle for what a "payment received" mail says.

## Deliverables

1. Mailable classes + templates (3 families) + send wiring in the
   registration/pay services.
2. Tests: each mail fires on the right event with the right recipients and
   rendered content; prefs-gated admin notify; no mail on failed events.

## Acceptance

- `php artisan test` green; PHPStan 0; Pint clean.
- Registration + payment E2E (P3.8) asserts the mails hit the test inbox
  with correct rendered text.
