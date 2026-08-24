<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BrewerController;
use App\Http\Controllers\BrewerForm1Controller;
use App\Http\Controllers\BrewerForm2Controller;
use App\Http\Controllers\EntriesController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

// Public read-only surface (Phase 2). Legacy served these as ?section=
// query params; the standalone build uses clean URLs and additionally
// accepts the legacy query shape so old links keep working.
// Legacy served login as ?section=login (and the reset flow as
// ?section=login&go=password&action=forgot|reset-password); the clean
// /login URL is canonical, legacy query shapes redirect to it.
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/?section=login', [LoginController::class, 'show'])->name('login.legacy');
Route::get('/list', [PublicController::class, 'list'])->name('list');
// Legacy served archives as ?section=past-winners&go={suffix}; the suffix is a
// table-name fragment and is sanitized to alphanumerics in the repository.
Route::get('/past-winners/{filter}', [PublicController::class, 'pastWinners'])->name('past-winners');

// Auth (Phase 3 / Slice B). Login lives at clean /login (canonical); the
// legacy query shapes (?section=login, go=password/action=forgot/reset)
// are accepted so old links and the login page's own reset links work.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');

// Registration (P3.1b). Legacy: ?section=register&go={entrant|judge|steward};
// clean /register is the canonical URL.
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::get('/register/{go}', [RegisterController::class, 'show'])->name('register.go');
Route::post('/register/{go?}', [RegisterController::class, 'store'])->name('register.store');

// Entry management (P3.2d). Delete ports the legacy
// process.inc.php?dbTable=brewing&action=delete flow (POST + ownership +
// window/paid gates) and lands back on /list with the legacy msg=5 code.
Route::post('/entries/{id}', [EntriesController::class, 'destroy'])
    ->name('entries.destroy')
    ->middleware('auth');

// Brewer profile form 0 — account & contact edit (P3.2a). Legacy:
// ?section=brewer&action=edit&go=account behind a login gate.
Route::get('/list/edit-account', [BrewerController::class, 'showEdit'])
    ->name('brewer.edit')->middleware('auth');
Route::post('/list/edit-account', [BrewerController::class, 'saveEdit'])
    ->name('brewer.update')->middleware('auth');

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
