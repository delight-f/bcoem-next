<?php

declare(strict_types=1);

use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

// Public read-only surface (Phase 2). Legacy served these as ?section=
// query params; the standalone build uses clean URLs and additionally
// accepts the legacy query shape so old links keep working.
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/list', [PublicController::class, 'list'])->name('list');
// Legacy served archives as ?section=past-winners&go={suffix}; the suffix is a
// table-name fragment and is sanitized to alphanumerics in the repository.
Route::get('/past-winners/{filter}', [PublicController::class, 'pastWinners'])->name('past-winners');
