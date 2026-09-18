<?php

declare(strict_types=1);

use App\Http\Controllers\Judging\CustomStyleController;
use App\Http\Controllers\Judging\PoolAssignController;
use App\Http\Controllers\Judging\PracticeSessionController;
use App\Http\Controllers\Judging\TablesModeController;
use Illuminate\Support\Facades\Route;

// Phase 4 / P4.7 — judging-related AJAX endpoints
// (tables_mode, import_scores, practice_session, custom_style).
//
// import_scores is NOT routed here: P4.6 already shipped it as
// POST /eval/import-scores (route eval.import.run, EvalImportController,
// backed by App\Support\Eval\EvalConsensus) with its own legacy-envelope
// fixtures in tests/Feature/EvalSubAppTest.php.
//
// Gates stay in-controller per legacy behavior (tables_mode userLevel<=2,
// custom_style <=1, practice_session ==0); CSRF-protected POSTs are port
// hardening — these routes inherit the web group from routes/web.php.

Route::post('/admin/judging/tables-mode', [TablesModeController::class, 'store'])
    ->name('admin.judging.tables_mode');

// Pool assignment checkbox/organizer save (legacy ajax/save.ajax.php
// action=judging_staff). Admin gate stays in-controller like tables_mode.
Route::post('/admin/judging/pool-assign/staff', [PoolAssignController::class, 'toggle'])
    ->name('admin.judging.pool_assign.staff');

// Inline table allocation from the pool screen (issue #56).
Route::post('/admin/judging/pool-assign/table', [PoolAssignController::class, 'assignTable'])
    ->name('admin.judging.pool_assign.table');

Route::post('/admin/judging/practice-session', [PracticeSessionController::class, 'store'])
    ->name('admin.judging.practice_session');

Route::get('/ajax/custom-style', [CustomStyleController::class, 'show'])
    ->name('ajax.custom_style');
