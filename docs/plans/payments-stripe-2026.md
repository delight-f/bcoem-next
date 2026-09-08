# Payment Modernization Plan — Stripe (2026)

Status: PLANNED. Decisions locked with the organizer 2026-09-06 (see
"Decisions"). Research base: current Stripe docs (connect/charges,
connect/direct-charges, payments/checkout, connect/account-capabilities)
+ legacy source (`pub/pay.pub.php`, `ppv.php`, `lib/common.lib.php:907`)
+ port ledger `.scratch/bcoem-next/ledger/payments.md`.

## Decisions (user-approved 2026-09-06)

| Decision | Choice |
|---|---|
| Merchant model | Organizer Connect Standard (already the port design) |
| PayPal | Retire entirely — remove prefs/UI/nav gating; IPN is deprecated |
| Platform fee | 0% passthrough — no `application_fee_amount` |
| Fee model | Port legacy tiered fees (`total_fees`, lib/common.lib.php:907) |
| Payment methods | Cards + wallets (Checkout hosted page); no ACH |

## Current state (audited 2026-09-06)

Built and tested (spec tickets 12–16):

- `GatewayAdapter` contract + `Checkout`/`PaymentResult`/`PaymentEvent`
  (`app/Support/Payments/`).
- `PaymentService` state machine: idempotent (`event_id` unique), fail-closed,
  refund reversal, amount reconciliation (ledger/payments.md "Port decisions").
- `StripeGateway`: Connect Standard, direct charges via `Stripe-Account`
  header, Checkout Session (one line item = batch fee), signed webhooks
  (`checkout.session.completed` → Paid, `charge.refunded` → Refunded,
  `checkout.session.expired` → Cancelled).
- `ManualGateway`: admin mark-paid (check/cash/dropoff/bank-transfer).
- Connect OAuth onboarding → `preferences.prefsStripe`
  (`StripeConnectController`, `/admin/stripe`).
- Public pay page `/pay` + batch checkout `POST /pay/checkout`.
- Tests: `PaymentGatewayContractTestCase`, `StripeGatewayContractTest`,
  `StripeWebhookTest`, `PayPageTest`, `ManualPaymentTest`,
  `PaymentServiceTest` (18 suites pass; no live Stripe calls).

## Work items

### W1 — Make Stripe live (the missing wiring) — small
`AppServiceProvider::register()`: conditionally bind
`GatewayAdapter::class` → `StripeGateway::forTenant(...)` when the tenant's
`prefsStripe.account_id` is set. PayController's
`app()->bound(GatewayAdapter::class)` gate then flips the pay page from
`unavailable` to `payable` with zero controller changes.
Success/cancel URLs: `route('pay.callback')` +
`?session_id={CHECKOUT_SESSION_ID}`, `route('pay.cancel')`.
Tests: container-binding test per tenant state (connected vs not).

### W2 — Success-return fix (correct UX; webhook stays source of truth) — small
`GET /pay/callback?session_id=cs_…` currently runs the payload through
`handleCallback()`, which (correctly) fails signature verification on query
params → entrant sees "cancelled" (msg=14) after a successful payment.
Fix: on return, retrieve the Checkout Session server-side
(`checkout.sessions->retrieve($sessionId, [], stripe_account)`);
if `payment_status === 'paid'` render success (msg=13) — do NOT flip flags
there (webhook remains the sole writer; dedup makes a double-apply safe
anyway). Anything else renders cancel. Keep `PayController::callback`'s
tamper checks for the manual/gateway-agnostic path.
Tests: PayPageTest success-return shows msg=13 when session is paid;
shows msg=14 when unpaid/cancelled; no flags flipped at return time.

### W3 — Retire PayPal — small, clean cutover
- Site-preferences Payment tab: drop Accept PayPal / PayPal Account / IPN
  rows; drop the IPN-setup help block.
- `SitePreferencesController::updatePayment()`: remove
  `prefsPaypal`, `prefsPaypalAccount`, `prefsPaypalIPN` from validation and
  the persisted map. DB columns stay (legacy schema; unused reads only).
- Nav gate (`components/public-layout.blade.php:247,253` + line 256 heading):
  replace `prefsPaypalIPN === 1` with "Stripe connected"
  (`prefsStripe.account_id` non-empty); then "Manage Payments" appears
  exactly when there is something to manage.
- Grep-audit for remaining `prefsPaypal*` reads (pay page, emails).
Tests: settings round-trip no longer requires the fields; nav shows/hides
on Stripe state.

### W4 — Legacy fee tiers (correctness: right amount charged) — the big one
Port `total_fees()` per-entrant branch (`lib/common.lib.php:907+`,
"bid != default" path): first N entries at `contestEntryFee`, entries
N+1… at `contestEntryFee2` (discount fee), `brewer.brewerDiscount='Y'`
entrants pay `contestEntryFeeDiscount` per entry
(`contestEntryFeeDiscountNum` semantics), capped at `contestEntryCap`.
Extract to `App\Support\Payments\FeeCalculator` (pure function of
entry rows + competition prefs — unit-testable, no container).
Consume in: PayController (display total + checkout `feeTotal`),
PaymentService::reconcileAmount (owed = calculator, not flat fee),
ManualPaymentController (admin mark-paid amount),
DashboardController::status (the ponytail note there already flags this).
Careful: `total_fees()` counts CONFIRMED entries for per-entrant totals —
match that. Pin edge cases: cap boundary (==), discount flag off, discount
flag on but `contestEntryFeeDiscountNum` empty, 0 entries.
Tests: FeeCalculatorTest table-driven from legacy truth + reconciliation
mismatch still blocks.

### W5 — Webhook hardening (Stripe-recommended events) — small
Add `checkout.session.async_payment_succeeded` → Paid and
`checkout.session.async_payment_failed` → Failed to
`StripeGateway::handleCallback()` (Stripe docs list all three as
must-handle for Checkout). No-op for cards today, correct if a bank-
settlement method is ever enabled — zero-risk addition behind the existing
enum. Also assert the session's `metadata.entry_ids` non-empty on
`completed` (a completed session without our metadata must not mark).
Tests: extend StripeWebhookTest (3 new signed-event cases).

### W6 — Refund admin surface — medium
`StripeGateway::refund()` exists; add the UI + policy:
- `/admin/payments` list (payments table) with per-row refund action,
- admin confirm dialog; after judging starts require a second confirm
  (policy: organizer's call, default allowed),
- `refund_application_fee: true` is a no-op at 0% fee (passthrough),
- refund flow: call gateway → on Paid-event `PaymentService::apply()`
  flips flags back (already implemented + tested).
Tests: admin-gated refund; refund reverses only entries not covered by
another paid row (already pinned in PaymentServiceTest — wire HTTP test).

### Non-goals (per decisions)
- ACH/async payment methods UX (W5 covers the events; no 'pending' state).
- Application fee / platform billing (0% passthrough).
- PayPal adapter of any kind.
- Stripe Tax / invoices / subscriptions.

## Order & sizing
W1 → W2 → W3 (one reviewable unit: "Stripe live + PayPal retired"),
then W4 (isolated, pure function + consumers), then W5, then W6.
W1-W3 ≈ half-day; W4 ≈ a day incl. edge-case tests; W5 trivial; W6 half-day.

## Risks / notes
- W4 changes charged amounts for competitions with tiers configured —
  feature-flag not needed: correct math is strictly better than flat, and
  reconciliation blocks mismatches either way.
- Webhook endpoint URL is per-competition (each connected account has its
  own signing secret, pasted at `/admin/stripe`). Organizer docs must say:
  point the webhook at `https://<host>/webhooks/stripe` on the COMPETITION
  account. Consider (later) the Stripe API to register the endpoint during
  OAuth callback — skipped now (extra API surface, manual paste works).
- `ppv.php` / legacy IPN files stay untouched in the legacy repo; the port
  simply has no IPN route.
