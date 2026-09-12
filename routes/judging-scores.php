<?php

declare(strict_types=1);

// ── P4.4 score entry / BOS / special best (ticket 04). Admin-only
// (userLevel<=1, gated in each controller like ManualPaymentController).

use App\Http\Controllers\Judging\BosController;
use App\Http\Controllers\Judging\ScoreController;
use App\Http\Controllers\Judging\SpecialBestController;
use App\Http\Controllers\Judging\SpecialBestDataController;

Route::get('/admin/judging/scores', [ScoreController::class, 'index'])
    ->name('admin.judging.scores.index')->middleware('auth');
Route::get('/admin/judging/scores/{table}/edit', [ScoreController::class, 'edit'])
    ->name('admin.judging.scores.edit')->middleware('auth');
Route::put('/admin/judging/scores/{table}', [ScoreController::class, 'update'])
    ->name('admin.judging.scores.update')->middleware('auth');
Route::delete('/admin/judging/scores/{id}', [ScoreController::class, 'destroy'])
    ->name('admin.judging.scores.destroy')->middleware('auth');

Route::get('/admin/judging/bos', [BosController::class, 'index'])
    ->name('admin.judging.bos.index')->middleware('auth');
Route::get('/admin/judging/bos/{styleType}/edit', [BosController::class, 'edit'])
    ->name('admin.judging.bos.edit')->middleware('auth');
Route::put('/admin/judging/bos/{styleType}', [BosController::class, 'update'])
    ->name('admin.judging.bos.update')->middleware('auth');
Route::put('/admin/judging/bos/{styleType}/panels', [BosController::class, 'updatePanels'])
    ->name('admin.judging.bos.panels')->middleware('auth');

Route::get('/admin/judging/special-best', [SpecialBestController::class, 'index'])
    ->name('admin.specialbest.index')->middleware('auth');
Route::get('/admin/judging/special-best/create', [SpecialBestController::class, 'create'])
    ->name('admin.specialbest.create')->middleware('auth');
Route::post('/admin/judging/special-best', [SpecialBestController::class, 'store'])
    ->name('admin.specialbest.store')->middleware('auth');
Route::get('/admin/judging/special-best/{id}/edit', [SpecialBestController::class, 'edit'])
    ->name('admin.specialbest.edit')->middleware('auth');
Route::put('/admin/judging/special-best/{id}', [SpecialBestController::class, 'update'])
    ->name('admin.specialbest.update')->middleware('auth');
Route::delete('/admin/judging/special-best/{id}', [SpecialBestController::class, 'destroy'])
    ->name('admin.specialbest.destroy')->middleware('auth');

Route::get('/admin/judging/special-best-data', [SpecialBestDataController::class, 'index'])
    ->name('admin.specialbest.data.index')->middleware('auth');
Route::get('/admin/judging/special-best/{id}/entries', [SpecialBestDataController::class, 'edit'])
    ->name('admin.specialbest.data.edit')->middleware('auth');
Route::put('/admin/judging/special-best/{id}/entries', [SpecialBestDataController::class, 'update'])
    ->name('admin.specialbest.data.update')->middleware('auth');
Route::delete('/admin/judging/special-best-data/{id}', [SpecialBestDataController::class, 'destroy'])
    ->name('admin.specialbest.data.destroy')->middleware('auth');
