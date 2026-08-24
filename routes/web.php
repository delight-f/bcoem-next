<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
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
