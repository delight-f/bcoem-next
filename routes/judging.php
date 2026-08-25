<?php

declare(strict_types=1);

use App\Http\Controllers\Judging\BarcodeCheckinController;

// Phase 4 / Slice C — judging. Populated per-ticket:
// P4.1 config, P4.2 engine (no routes), P4.3 assignments UI,
// P4.4 scores/BOS, P4.5 barcode check-in, P4.7 judging AJAX.

// Barcode check-in (P4.5). Admin-only (userLevel<=1, gated in the
// controller like ManualPaymentController): scan → lookup by judging
// number / entry id → flip brewReceived to '1' (legacy
// process_barcode_check_in.inc.php semantics; no undo path existed).
Route::get('/admin/judging/checkin', [BarcodeCheckinController::class, 'show'])
    ->name('admin.judging.checkin.show')->middleware('auth');
Route::post('/admin/judging/checkin', [BarcodeCheckinController::class, 'store'])
    ->name('admin.judging.checkin.store')->middleware('auth');

// ── P4.1 admin judging config (ticket 01). Admin-only (userLevel<=1,
// gated in each controller like ManualPaymentController) CRUD for the
// five legacy config screens: judging sessions, non-judging sessions
// (same table, judgingLocType=2), drop-off locations, tables, and
// judging preferences.
use App\Http\Controllers\Judging\DropOffController;
use App\Http\Controllers\Judging\JudgingPreferenceController;
use App\Http\Controllers\Judging\LocationController;
use App\Http\Controllers\Judging\TableController;

$locationActions = ['index', 'create', 'store', 'edit', 'update', 'destroy'];

Route::get('/admin/judging/locations', [LocationController::class, 'index'])
    ->name('admin.judging.locations.index')->middleware('auth');
Route::get('/admin/judging/locations/create', [LocationController::class, 'create'])
    ->name('admin.judging.locations.create')->middleware('auth');
Route::post('/admin/judging/locations', [LocationController::class, 'store'])
    ->name('admin.judging.locations.store')->middleware('auth');
Route::get('/admin/judging/locations/{id}/edit', [LocationController::class, 'edit'])
    ->name('admin.judging.locations.edit')->middleware('auth');
Route::put('/admin/judging/locations/{id}', [LocationController::class, 'update'])
    ->name('admin.judging.locations.update')->middleware('auth');
Route::delete('/admin/judging/locations/{id}', [LocationController::class, 'destroy'])
    ->name('admin.judging.locations.destroy')->middleware('auth');

// Same controller/table; non-judging rows are judgingLocType=2 and the
// form drops type/rounds. kind selects list filter + validation shape.
Route::get('/admin/judging/non-judging', [LocationController::class, 'index'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.index')->middleware('auth');
Route::get('/admin/judging/non-judging/create', [LocationController::class, 'create'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.create')->middleware('auth');
Route::post('/admin/judging/non-judging', [LocationController::class, 'store'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.store')->middleware('auth');
Route::get('/admin/judging/non-judging/{id}/edit', [LocationController::class, 'edit'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.edit')->middleware('auth');
Route::put('/admin/judging/non-judging/{id}', [LocationController::class, 'update'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.update')->middleware('auth');
Route::delete('/admin/judging/non-judging/{id}', [LocationController::class, 'destroy'])
    ->defaults('kind', 'non-judging')->name('admin.judging.non_judging.destroy')->middleware('auth');

Route::get('/admin/dropoff', [DropOffController::class, 'index'])
    ->name('admin.judging.dropoff.index')->middleware('auth');
Route::get('/admin/dropoff/create', [DropOffController::class, 'create'])
    ->name('admin.judging.dropoff.create')->middleware('auth');
Route::post('/admin/dropoff', [DropOffController::class, 'store'])
    ->name('admin.judging.dropoff.store')->middleware('auth');
Route::get('/admin/dropoff/{id}/edit', [DropOffController::class, 'edit'])
    ->name('admin.judging.dropoff.edit')->middleware('auth');
Route::put('/admin/dropoff/{id}', [DropOffController::class, 'update'])
    ->name('admin.judging.dropoff.update')->middleware('auth');
Route::delete('/admin/dropoff/{id}', [DropOffController::class, 'destroy'])
    ->name('admin.judging.dropoff.destroy')->middleware('auth');

Route::get('/admin/judging/tables', [TableController::class, 'index'])
    ->name('admin.judging.tables.index')->middleware('auth');
Route::get('/admin/judging/tables/create', [TableController::class, 'create'])
    ->name('admin.judging.tables.create')->middleware('auth');
Route::post('/admin/judging/tables', [TableController::class, 'store'])
    ->name('admin.judging.tables.store')->middleware('auth');
Route::get('/admin/judging/tables/{id}/edit', [TableController::class, 'edit'])
    ->name('admin.judging.tables.edit')->middleware('auth');
Route::put('/admin/judging/tables/{id}', [TableController::class, 'update'])
    ->name('admin.judging.tables.update')->middleware('auth');
Route::delete('/admin/judging/tables/{id}', [TableController::class, 'destroy'])
    ->name('admin.judging.tables.destroy')->middleware('auth');

Route::get('/admin/judging/preferences', [JudgingPreferenceController::class, 'show'])
    ->name('admin.judging.preferences.show')->middleware('auth');
Route::post('/admin/judging/preferences', [JudgingPreferenceController::class, 'store'])
    ->name('admin.judging.preferences.store')->middleware('auth');

// ── P4.3 assignments UI (ticket 03). Admin screens gated userLevel<=1 in
// the controllers (ManualPaymentController pattern); judge signup is any
// authenticated participant.
use App\Http\Controllers\Judging\AssignController;
use App\Http\Controllers\Judging\FlightController;
use App\Http\Controllers\Judging\JudgeSignupController;

// Public judge signup (legacy pub/judge.pub.php + judge_info/judge_closed).
Route::get('/judge', [JudgeSignupController::class, 'show'])
    ->name('judge.signup')->middleware('auth');
Route::post('/judge', [JudgeSignupController::class, 'store'])
    ->name('judge.signup.store')->middleware('auth');

// Flight definition grid: manual radio per entry (ledger #7).
Route::get('/admin/judging/flights', [FlightController::class, 'index'])
    ->name('admin.judging.flights.index')->middleware('auth');
Route::get('/admin/judging/flights/{id}', [FlightController::class, 'show'])
    ->name('admin.judging.flights.show')->middleware('auth');
Route::post('/admin/judging/flights/{id}', [FlightController::class, 'store'])
    ->name('admin.judging.flights.store')->middleware('auth');

// Judge/steward → table/flight assignment with preference/conflict surfacing.
Route::get('/admin/judging/flights/{id}/assign/{role}', [AssignController::class, 'show'])
    ->name('admin.judging.assign.show')->middleware('auth');
Route::post('/admin/judging/flights/{id}/assign/{role}', [AssignController::class, 'store'])
    ->name('admin.judging.assign.store')->middleware('auth');
