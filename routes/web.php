<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PaymentSetupController;
use App\Http\Controllers\Admin\PublishResultsController;
use App\Http\Controllers\AjaxController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\AwardsController;
use App\Http\Controllers\BrewController;
use App\Http\Controllers\BrewerController;
use App\Http\Controllers\BrewerForm1Controller;
use App\Http\Controllers\BrewerForm2Controller;
use App\Http\Controllers\ChangeEmailController;
use App\Http\Controllers\EntriesController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\QrCheckinController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

// Email verification gate (Task 4): applied only to the actions that should
// require a confirmed address (adding/paying for entries), and only when the
// installation has deliberately turned the feature on. A data-heavy
// conditional would otherwise have to be repeated on every route below.
$emailVerified = config('services.email_verification.enabled') ? ['verified'] : [];

// Home (Phase 2) doubles as the legacy URL entry point: old bookmarks hit
// index.php?section=… and LegacyRedirectController 301s them onto the
// clean port URLs per the HANDOVER §4.3 contract; anything without a
// recognized legacy section renders home as before.
Route::get('/', LegacyRedirectController::class)->name('home.legacy');
Route::get('/index.php', LegacyRedirectController::class)->name('home.index');
// Legacy awards.php → /awards (301, query preserved) — the awards
// presentation shipped as a top-level legacy file, so old links land here.
Route::get('/awards.php', fn (Request $r) => new RedirectResponse('/awards'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::get('/list', [PublicController::class, 'list'])->name('list');
// Legacy served archives as ?section=past-winners&go={suffix}; the suffix is a
// table-name fragment and is sanitized to alphanumerics in the repository.
Route::get('/past-winners/{filter}', [PublicController::class, 'pastWinners'])->name('past-winners');
// Legacy top-nav pages (volunteers.sec.php / contact.sec.php). The public
// nav renders these as standalone pages; contact also accepts the form
// POST (legacy includes/process.inc.php?dbTable=contacts&action=email).
Route::get('/volunteers', [PublicController::class, 'volunteers'])->name('volunteers');
Route::get('/contact', [PublicController::class, 'contact'])->name('contact');
Route::get('/sponsors', [PublicController::class, 'sponsors'])->name('sponsors');
Route::post('/contact', [PublicController::class, 'contactStore'])->name('contact.store');

// Auth (Phase 3 / Slice B). Login lives at clean /login (canonical); the
// legacy query shapes (?section=login, go=password/action=forgot/reset)
// are accepted so old links and the login page's own reset links work.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');

// Email verification (Task 4). Registered unconditionally so the `verified`
// middleware always has a destination; the feature itself only matters when
// EMAIL_VERIFICATION_ENABLED is on.
Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');
});

// Legacy ?section=user&action=username: the distinct change-email page
// (restored per PARITY-007 — the merged /list/edit-account form keeps
// working, but legacy deep links and the admin-side flow target this).
Route::get('/user/username', [ChangeEmailController::class, 'show'])->middleware('auth')->name('user.username');
Route::post('/user/username', [ChangeEmailController::class, 'store'])->middleware('auth');

// Registration (P3.1b). Legacy: ?section=register&go={entrant|judge|steward};
// clean /register is the canonical URL.
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::get('/register/{go}', [RegisterController::class, 'show'])->name('register.go');
Route::post('/register/{go?}', [RegisterController::class, 'store'])
    ->name('register.store')
    ->middleware(['throttle:signup', ProtectAgainstSpam::class]);

// Entry management (P3.2d). Delete ports the legacy
// process.inc.php?dbTable=brewing&action=delete flow (POST + ownership +
// window/paid gates) and lands back on /list with the legacy msg=5 code.
Route::post('/entries/{id}', [EntriesController::class, 'destroy'])
    ->name('entries.destroy')
    ->middleware('auth');

// Publish Results (legacy process.inc.php?action=publish): releases winners
// publicly and forces all future deadlines closed. Lands /admin?msg=36.
Route::post('/admin/results/publish', [PublishResultsController::class, 'store'])
    ->name('admin.results.publish')->middleware('auth');

// QR mobile check-in (legacy qr.php, PARITY-002). Public, password-gated
// via contest_info.contestCheckInPassword; msg codes 1-7 mirror legacy,
// msg=8 is the port's no-password-configured state (issue #30).
Route::get('/qr', [QrCheckinController::class, 'show'])->name('qr.show');
Route::post('/qr/password-check', [QrCheckinController::class, 'authenticate'])->name('qr.authenticate');
Route::post('/qr/checkin', [QrCheckinController::class, 'store'])->name('qr.checkin');

// Awards reveal.js presentation (legacy awards.php, PARITY-001).
// Public gate: judging past + all windows closed + prefsDisplayWinners=Y +
// delay passed; admins always. ?view= white|black|blue, ?go= table-*.
Route::get('/awards', [AwardsController::class, 'show'])->name('awards.show');

// Brewer profile form 0 — account & contact edit (P3.2a). Legacy:
// ?section=brewer&action=edit&go=account behind a login gate.
Route::get('/list/edit-account', [BrewerController::class, 'showEdit'])
    ->name('brewer.edit')->middleware('auth');
Route::post('/list/edit-account', [BrewerController::class, 'saveEdit'])
    ->name('brewer.update')->middleware('auth');

// Authenticated password change. Legacy: ?section=user&go=account&action=password
// (form) + process.inc.php go=password (save).
Route::get('/user/password', [ChangePasswordController::class, 'show'])
    ->name('user.password')->middleware('auth');
Route::post('/user/password', [ChangePasswordController::class, 'update'])
    ->name('user.password.update')->middleware('auth');

// Password reset (P3.1c). Legacy: ?section=login&go=password&action=
// forgot|verify|reset-password; clean URLs are canonical.
Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])->name('password.forgot');
Route::post('/forgot-password', [ForgotPasswordController::class, 'forgot'])->name('password.forgot.post');
Route::get('/forgot-password/verify', [ForgotPasswordController::class, 'verifyForm'])->name('password.verify');
Route::post('/forgot-password/verify', [ForgotPasswordController::class, 'verify'])->name('password.verify.post');
Route::get('/reset-password', [ForgotPasswordController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('password.reset.post');

// Brewer profile form 2 (P3.2c). Legacy: ?section=list&go=account edit of
// the judge/steward/staff preference fields; clean URL is canonical. Save
// completes the registration wizard → /list (brewer_info landing, msg=2).
Route::get('/list/edit-judging', [BrewerForm2Controller::class, 'show'])
    ->name('brewer.judging')->middleware('auth');
Route::post('/list/edit-judging', [BrewerForm2Controller::class, 'store'])
    ->name('brewer.judging.store')->middleware('auth');

// Brewer profile wizard step 2 (P3.2b). Legacy: ?section=brewer&go=profile;
// clean /list/edit-clubs is canonical.
Route::get('/list/edit-clubs', [BrewerForm1Controller::class, 'show'])->name('brewer.clubs')->middleware('auth');
Route::post('/list/edit-clubs', [BrewerForm1Controller::class, 'store'])->name('brewer.clubs.store')->middleware('auth');

// Entry creation (P3.3a). Legacy: ?section=brew&action=add behind a login
// gate; clean /brew is canonical. Save lands on /list?msg=1 (legacy msg
// codes; msg=8/9 are the user/subcategory cap rejections).
Route::get('/brew', [BrewController::class, 'showCreate'])->name('brew.create')->middleware(['auth', ...$emailVerified]);
Route::post('/brew', [BrewController::class, 'storeCreate'])->name('brew.store')->middleware(['auth', ...$emailVerified]);

// Entry edit (P3.3b). Legacy: ?section=brew&action=edit&id=N — the same
// brew form in edit mode, posted back to its own URL; clean
// /brew/{id}/edit is canonical and matches the /list edit links.
// Save lands on /list?msg=2 (legacy msg codes; msg=1-<style> is the
// missing-required-style-field rejection served back at the edit form).
Route::get('/brew/{entry}/edit', [BrewController::class, 'showEdit'])->name('brew.edit')->middleware(['auth', ...$emailVerified]);
Route::post('/brew/{entry}/edit', [BrewController::class, 'storeEdit'])->name('brew.update')->middleware(['auth', ...$emailVerified]);

// Public pay page (P3.5d). Legacy served ?section=pay behind a login gate.
// Success lands back on the page with the legacy confirmation alert
// (msg=13); cancel renders msg=14 — legacy used section=list&msg=13/14,
// the port keeps the post-payment state on /pay itself.
Route::get('/pay', [PayController::class, 'show'])->name('pay')->middleware(['auth', ...$emailVerified]);
// Named alias for gateway cancel_url builders: renders the legacy
// "payment cancelled" state (alerts.pub.php msg=14).
Route::get('/pay/cancel', fn () => redirect()->to('/pay?msg=14'))->name('pay.cancel');
Route::post('/pay/checkout', [PayController::class, 'checkout'])->name('pay.checkout')->middleware(['auth', ...$emailVerified]);
Route::get('/pay/callback', [PayController::class, 'callback'])->name('pay.callback')->middleware('auth');

// Stripe webhook (P3.5b). No auth middleware — authenticity comes from
// signature verification in the controller; 2xx acks verified deliveries,
// invalid signatures get 4xx so Stripe retries.
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// PayPal webhook (issue #24 P5). Same contract as the Stripe endpoint: no auth
// middleware — authenticity is the locally verified signature inside
// PayPalGateway; 2xx acks verified deliveries, invalid signatures get 4xx.
Route::post('/webhooks/paypal', PayPalWebhookController::class)->name('webhooks.paypal');

// Stripe Connect onboarding (P3.5b). Admin-only: settings page, OAuth
// start/callback against the organizer's own Stripe account, and pasting
// the webhook endpoint's signing secret. Stored per competition in
// preferences.prefsStripe.
Route::get('/admin/stripe', [StripeConnectController::class, 'show'])
    ->name('admin.stripe')->middleware('auth');
Route::get('/admin/stripe/connect', [StripeConnectController::class, 'connect'])
    ->name('admin.stripe.connect')->middleware('auth');
Route::get('/admin/stripe/callback', [StripeConnectController::class, 'callback'])
    ->name('admin.stripe.callback')->middleware('auth');
Route::post('/admin/stripe/webhook-secret', [StripeConnectController::class, 'saveSecret'])
    ->name('admin.stripe.secret')->middleware('auth');

// Manual payment marking (P3.5c). Admin-only (userLevel<=1, gated in the
// controller): minimal surface listing unpaid confirmed entries; marking
// routes through ManualGateway + PaymentService so the rows converge with
// any gateway path. The full admin entries view is P5.5 scope.
Route::get('/admin/payments/mark', [ManualPaymentController::class, 'show'])
    ->name('admin.payments')->middleware('auth');
Route::post('/admin/payments/mark', [ManualPaymentController::class, 'markPaid'])
    ->name('admin.payments.mark')->middleware('auth');

// Payment provider setup (issue #24 follow-up). One plain-language screen
// where the organizer switches on Stripe and/or PayPal; PayPal credentials
// are stored encrypted (PayPalSettings). Admin-gated in-controller.
Route::get('/admin/payments/setup', [PaymentSetupController::class, 'show'])
    ->name('admin.payments.setup')->middleware('auth');
Route::post('/admin/payments/setup/paypal', [PaymentSetupController::class, 'savePayPal'])
    ->name('admin.payments.setup.paypal')->middleware('auth');
Route::post('/admin/payments/setup/paypal/remove', [PaymentSetupController::class, 'removePayPal'])
    ->name('admin.payments.setup.paypal.remove')->middleware('auth');

// AJAX endpoints (P3.7). Port the legacy ajax/*.ajax.php files; response
// envelopes carry the legacy HTML fragments verbatim (see AjaxController).
// CSRF-protected POSTs are port hardening — legacy sent these as bare
// GET/POST with no token. Only save required a real login in legacy (the
// other files checked the always-bootstrapped session flag), so its
// session/userLevel gates live in the controller to keep the legacy
// status=9 envelope instead of an auth redirect.
Route::post('/ajax/username', [AjaxController::class, 'username'])->name('ajax.username');
Route::post('/ajax/valid-email', [AjaxController::class, 'validEmail'])->name('ajax.valid_email');
Route::post('/ajax/account-checks', [AjaxController::class, 'accountChecks'])->name('ajax.account_checks');
Route::post('/ajax/save', [AjaxController::class, 'save'])->name('ajax.save');
Route::post('/ajax/count-records', [AjaxController::class, 'countRecords'])->name('ajax.count_records');
// Session resync (upstream ajax/heartbeat.ajax.php): a GET so the client can
// poll it repeatedly on activity without a CSRF token; it only reads the
// effective timeout, so there is nothing to protect beyond the login gate.
Route::get('/ajax/heartbeat', [AjaxController::class, 'heartbeat'])->name('ajax.heartbeat');

require __DIR__.'/judging.php';
require __DIR__.'/eval.php';

require __DIR__.'/judging-scores.php';

require __DIR__.'/judging-ajax.php';

require __DIR__.'/outputs.php';
require __DIR__.'/admin.php';
require __DIR__.'/backoffice.php';
require __DIR__.'/archive.php';

// Legacy URL redirect contract (HANDOVER §4.3): the bcoem query-string
// dispatch (GET index.php?section=… / POST includes/process.inc.php)
// maps onto the clean port URLs; see LegacyRedirectController for the map.
// /index.php and / both reach the controller (the front controller strips
// its own script name from the path).
Route::post('/includes/process.inc.php', [LegacyRedirectController::class, 'process'])
    ->name('legacy.process');
Route::get('/includes/process.inc.php', [LegacyRedirectController::class, 'process']);
