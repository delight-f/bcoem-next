# Payment Interface Expansion — PayPal (issue #24)

Status: **APPROVED — executing.** Decisions locked 2026-09-12.
Supersedes the PayPal half of `docs/plans/payments-stripe-2026.md`
(2026-09-06 decision "PayPal — retire entirely"). This plan reinstates PayPal
as an additional provider and explicitly keeps the existing Stripe stack.

Locked decisions: **D1** = use `srmklive/paypal` (compatibility verified — see
D1); **D2–D6** = implemented as recommended.

## Scope decision

- PayPal is **back in scope** as a second provider (user decision, 2026-09-12).
- **Reuse the existing abstractions** — do not restructure to the literal
  design in issue #24. No `App\Contracts\PaymentProvider` rename, no
  `payment_webhook_events` table, no enum rename, no polymorphic `payable_*`.
- Issue #24's Stripe-side and architecture requirements are, with one
  exception (CSRF, below), **already implemented and tested**. Only the
  genuinely missing work is scheduled here.

The issue body reads as a greenfield brief written without knowledge of the
current tree; most of it converges with code that already shipped. Where the
issue's proposed shape differs from the shipped ledger, the shipped ledger
wins and the deviation is recorded at the bottom.

## Gap analysis — issue #24 task by task

| # | Issue task | Existing equivalent | Real gap |
|---|---|---|---|
| 1 | Migrations: `payments` + `payment_webhook_events` | `2026_08_24_000000_create_payments_table.php` (`entry_ids`, `method`, `provider_ref`, `event_id` UNIQUE, `status`, `currency`, `note`, `admin_uid`, `pay_method`, `reference`) | None required. Dedup already lives on `payments.event_id` UNIQUE (ledger #7) — no separate events table. `method` is a plain string, so `'paypal'` needs no migration. Optional additive columns only (see D5). |
| 2 | `App\Contracts\PaymentProvider` + `WebhookResult` + enums | `GatewayAdapter`, `Checkout`, `PaymentResult`, `PaymentEvent` + `SessionCheckout` | No provider registry. No provider-key enum (`method(): string` instead). Add `PaymentProviderRegistry` (P3). |
| 3 | Stripe provider impl | `StripeGateway` — Connect Standard, Checkout Session, signed webhooks, `async_payment_succeeded/failed`, `expired`, `charge.refunded`, refunds | None functional. Already covers the issue's Task 3 and 5/6/7 acceptance criteria. |
| 4 | PayPal provider impl | — | **Entire adapter missing.** The main work. `srmklive/paypal` not installed. |
| 5 | `PaymentProviderRegistry` + `enabled()` | Single conditional bind in `AppServiceProvider::register()` keyed on `prefsStripe.account_id` | Registry + `enabled()` surface missing; UI and server-side validation both need it. |
| 6 | Controller flow + routes | `PayController@show/checkout/callback`, `StripeWebhookController`, `routes/web.php:167-199` | No provider selection/validation; no PayPal webhook route; no PayPal capture-on-return; **webhooks are not CSRF-exempt (production bug — see below)**. Route paths deliberately stay `/pay/*`, not the issue's `/entries/{entry}/payments/*`. |
| 7 | Server-derived amount; reject double payment | `FeeCalculator::forEntrant()` (full legacy tier/cap/special model); `unpaidEntries()` excludes paid entries, so a second checkout is a no-op `→ /pay` | Amount is already never taken from the request. No explicit 409 — the app redirects. Low value; not scheduled unless requested. |
| 8 | Provider-picker UI (BS5) | Single Pay button, `resources/views/public/pay.blade.php` | Provider picker missing. |
| 9 | `PaymentSucceeded` event → listener | `PaymentService::markPaid()` flips `brewing` flags and sends `PaymentConfirmMail` inline | Deliberate deviation: keep inline. Already testable via `PaymentServiceTest`; an event/listener adds indirection without changing behaviour. |

## Confirmed bugs / correctness gaps

**B1 — Webhook endpoints are CSRF-protected in production.**
`/webhooks/stripe` is declared in `routes/web.php` and therefore runs in the
`web` group. `bootstrap/app.php` exempts only `includes/process.inc.php`.
`ValidateCsrfToken` (→ `PreventRequestForgery::handle`) bypasses CSRF only when
`runningInConsole() && runningUnitTests()`.
Consequence: the current Stripe webhook test suite passes while the real
endpoint would answer **419** to Stripe in production — payment flags would
never flip. This is exactly the issue's "webhook routes excluded from CSRF"
acceptance criterion. Fix is part of P5 and applies to both providers.

## Implementation plan (reuse; smallest diffs)

Ordered. Each step is independently reviewable.

**P1 — PayPal transport (decided).** Use `srmklive/paypal` `^3.1`. Verified
compatible (see D1). Everything below assumes a `PayPalGateway` wrapping it.

**P2 — `App\Support\Payments\PayPalGateway implements GatewayAdapter`.**
Mirrors `StripeGateway`'s shape so `PaymentService` consumes it unchanged.

- Config: extend `config/services.php` with a `paypal` block
  (`client_id`, `client_secret`, `mode`, `webhook_id`) + `.env.example` keys.
- `createCheckout()`: create an Order (`intent: CAPTURE`) for
  `amount = $feeTotal` in the tenant currency; set
  `application_context.return_url` → `route('pay.callback').'?provider=paypal'`,
  `.cancel_url` → `route('pay.cancel')`; stamp attribution (`custom_id`) from
  `entrant_uid` + `entry_ids` so the webhook can find the entries — the PayPal
  analogue of Stripe's Checkout Session `metadata`. Return the
  `rel: approve` HATEOAS link as `Checkout::$redirectUrl`.
- `handleCallback()`: verify via `/v1/notifications/verify-webhook-signature`
  (headers + webhook id); anything not `SUCCESS` → `Failed` with empty event
  id (fail closed, matching `StripeGateway`). Map
  `PAYMENT.CAPTURE.COMPLETED` → `Paid` with `providerRef = capture id`;
  `PAYMENT.CAPTURE.REFUNDED` → `Refunded`; everything else → `Failed`
  (acked, never marks).
- `refund()`: `/v2/payments/captures/{provider_ref}/refund`.
- New capability interface for the return leg (see D4), because PayPal needs
  an explicit capture the read-only `SessionCheckout` cannot express.

**P3 — `App\Support\Payments\PaymentProviderRegistry`.**
`enabled(): array<string, GatewayAdapter>` (Stripe when
`prefsStripe.account_id` set; PayPal when client id/secret/webhook id present)
and `get(string $key): GatewayAdapter`. `enabled()` drives the UI **and** the
server-side re-validation in `PayController@checkout` — a crafted
`provider=paypal` on an unconfigured install must be rejected. Preserve the
existing `GatewayAdapter::class` bind as the default/selected-provider seam so
the current test suites keep passing (see D2).

**P4 — Provider selection in the pay flow.**
- `public/pay.blade.php`: replace the single Pay button with a provider list
  rendered from `enabled()` (BS5 buttons/modal, full-page handoff — no iframe).
- `PayController@checkout`: accept optional `provider`, default to the
  registry default; reject a non-enabled provider server-side.
- `PayController@callback`: branch on `provider`; PayPal return triggers the
  capture, then renders msg=13/14. Capture is a provider-side side effect only
  — **flags still flip solely in the signature-verified webhook** (existing
  single-writer rule).

**P5 — PayPal webhook endpoint + CSRF fix.**
- `POST /webhooks/paypal` → a controller mirroring `StripeWebhookController`
  (verify → attribute → `PaymentService::apply`, 2xx ack, 4xx on bad
  signature/unattributed paid event).
- Add `webhooks/stripe` and `webhooks/paypal` to
  `validateCsrfTokens(except: [...])` in `bootstrap/app.php` (fixes B1).
- No auth middleware; signature verification is the authenticity boundary.

**P6 — Refund dispatch by method.**
`Admin\PaymentsController@refund` currently hardcodes
`StripeGateway::forTenant()` and refuses `method !== 'stripe'`. Dispatch
through the registry by `$row->method`; add `PaymentService::METHOD_PAYPAL`.

**P7 — Tests** (mirror the existing suites, no live provider calls):
- `PayPalGatewayContractTest` — implements the shared
  `PaymentGatewayContractTestCase`; verify-webhook-signature stubbed.
- `PayPalWebhookTest` — copy of `StripeWebhookTest`: paid writes ledger +
  flags, duplicate `event_id` deduped, bad signature 400 changes nothing,
  unattributed paid event 400, refund reverses flags.
- `PayPageTest` additions — provider list reflects `enabled()`; disabled
  provider rejected server-side; PayPal return shows msg=13/14 and flips
  nothing locally.
- Registry unit test — `enabled()` empty/partial/full config.
- Keep `StripeWebhookTest` green; add one assertion that a webhook route is
  CSRF-exempt in a production-like (non-console) request.

## Locked decisions

**D1 — PayPal transport: `srmklive/paypal` ^3.1.** Compatibility verified
2026-09-12 against Packagist and the tagged source (3.1.1):

- Requires `php ^8.2` (8.4 OK), `illuminate/support ^12.0|^13.0` (Laravel 13
  OK), `guzzlehttp/guzzle ^7.9`, `guzzlehttp/psr7 ^2.0`, `nesbot/carbon ^3.0`,
  `psr/http-client ^1.0`. All four transitives are already in our lockfile
  (Guzzle 7.15.5), so this adds one direct dependency and zero new transitives.
- MIT, no security advisories, actively maintained (`blendbyte/laravel-paypal`);
  its own dev deps target Laravel 13 + Larastan 3.
- Test seams: `Testing\MockPayPalClient` implements PSR-18 `ClientInterface`
  and the provider exposes `setClient()`; the provider is a bindable
  `paypal_client` singleton; `verifyWebHookLocally()` performs offline
  RSA-SHA256 verification (SSRF allowlist, cached cert, `fetchCert()` protected
  for test override) so webhook tests need no network.
- Caveats: `refundCapturedPayment()` takes a `float` amount (convert from our
  decimal string deliberately); provider methods return loose
  `array|StreamInterface|string` unions (our wrapper narrows with `is_array()`).
  `phpstan.neon` scans only `app/` and `tests/`, so vendor types cannot break
  the empty level-8 baseline.

**D2 — Default provider when `provider` is omitted.** Approved: omit = default
enabled provider, so `PayPageTest`/`ManualPaymentTest` and bookmarked
`/pay/checkout` keep working.

**D3 — PayPal enablement surface.** *Superseded 2026-09-12 (follow-up).*
Credentials are now entered in-app on `/admin/payments/setup` and stored
encrypted at rest (Laravel Crypt, APP_KEY) in `preferences.prefsPaypalConfig`,
falling back to the `PAYPAL_MODE/CLIENT_ID/SECRET/WEBHOOK_ID` env block when
nothing has been saved. The original env-only decision made non-technical setup
impossible. Stripe stays per-tenant through its Connect OAuth flow.

**D4 — Capture-on-return interface.** Approved: new narrow interface
implemented only by `PayPalGateway`; `SessionCheckout` untouched.

**D5 — Optional additive columns.** Approved: **skip** `paid_at`/`metadata`;
`created_at` + provider payload already cover debugging.

**D6 — Task 9 event/listener.** Approved: keep side effects inline in
`PaymentService` (no behaviour change, no new indirection).

## Deliberate deviations from issue #24

- No `payment_webhook_events` table — dedup stays on `payments.event_id`
  UNIQUE, which already satisfies the double-processing requirement.
- No polymorphic `payable_type/payable_id` — entries are `brewing.id` values
  in a legacy schema; the `entry_ids` JSON batch is the shipped contract.
- No `App\Contracts\PaymentProvider` rename or status/provider enums — reuse
  `GatewayAdapter` / `PaymentEvent`.
- No `PaymentProviderRegistry` inside `App\Contracts`; placed under
  `App\Support\Payments` to match the existing namespace.
- Routes stay `/pay/*` and `/webhooks/{stripe,paypal}` rather than
  `/entries/{entry}/payments/*`; the legacy URL contract is a port invariant.

## Out of scope

- ACH/async UX, platform fees/application fees (0% passthrough, unchanged).
- Any change to the Stripe Connect merchant model or fee calculator.
- Reworking the existing `payments` ledger or its characterization tests.
