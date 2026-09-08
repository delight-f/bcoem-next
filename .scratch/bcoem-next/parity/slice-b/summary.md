# Slice B parity summary (Phase 3)

Gate: ticket 18 (`.scratch/bcoem-next/issues/phase-3/18-slice-b-gate.md`)
Date: 2026-08-25
Harness: `tools/parity/parity.sh` (bcoem-parity-harness skill)

## Verdict

**6/6 URLs PASS, zero content diffs** on all three dumps:

| Dump | Result |
|------|--------|
| anon-base.sql | 6 pass / 0 diff |
| synth-100-winners-shown.sql | 6 pass / 0 diff |
| sql/bcoem_baseline_3.0.X.sql (CI baseline) | 6 pass / 0 diff |

## URL set (was 3 in Slice A, now 6)

Existing Slice A URLs (all still PASS):
- `/` (landing composition)
- `/past-winners/demoarchive` (archives)
- `/list` (anonymous nudge — redirects `?msg=99`)

New Slice B URLs (anonymous-diffable auth surfaces):
- `/login` (was `index.php?section=login`)
- `/register/entrant` (was `index.php?section=register&go=entrant`)
- `/forgot-password` (was `index.php?section=login&go=password&action=forgot`)

## Diffs found and fixed during the gate

1. **Section salutation** — the port rendered the landing's "Thank you for
   your interest…" salutation on section pages; legacy (index.pub.php:106)
   renders only the contest name as the section salutation h1. Fixed all
   three auth controllers to pass the bare contest name.

2. **Hero band** — the port rendered the hero on all pages; legacy renders
   the hero h1 only on the landing (`default` section). Auth pages now
   render `show-hero="false"`.

3. **Print-only h1** — legacy emits a print h1 with the contest name on
   every page (headers.inc.php), positioned AFTER the salutation (L4 DOM
   order). Moved from `home.blade.php` into the layout so all pages match.

4. **Page-title h1s** — legacy page h1s carry the `«contestName» - «Section»`
   prefix (e.g. "Synthetic Competition - Log In"). Port h1s updated to
   match; the section title is stripped by chrome-exclude on both sides.

5. **Form chrome** — legacy login/reset forms are modal-driven; the parity
   extractor strips the modal fields. The port renders real inline forms,
   so its labels (Email/Password/Forgot/Submit/Back to) were added to
   `chrome-exclude.txt` as the equivalent page chrome.

6. **Login view trailing period** after the reset link — removed (chrome).

## Auth-gated surfaces (NOT page-diffed)

Per spec §8.3 (payment exception) + the same reasoning for all
authenticated surfaces: /list (authed), /brew, /brew/{id}/edit, /pay,
/admin/payments/mark-paid cannot be compared anonymously. They are covered
by the Slice B E2E test (`tests/Feature/SliceBE2ETest.php`) which asserts
DB-state convergence (users/brewer/brewing flags/payments rows) through a
full register → create → edit → manual-pay season leg.

## Ledger addenda

- `ledger/registration-rules.md` — section-page salutation/hero chrome
  semantics pinned (L4 addendum).
- `ledger/payments.md` — payments table DDL, brewConfirmed flag addition,
  refund semantics, amount reconciliation (from ticket 12 close).
