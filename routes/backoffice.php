<?php

// P5.5 back-office: participants, payments, entries admin + style reports
// (spec §7). Owned by the P5.5 ticket.

use App\Http\Controllers\Admin\EntriesByStyleController;
use App\Http\Controllers\Admin\EntriesBySubstyleController;
use App\Http\Controllers\Admin\EntriesController;
use App\Http\Controllers\Admin\ParticipantsController;
use App\Http\Controllers\Admin\PaymentsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Participants
    Route::get('/backoffice/participants', [ParticipantsController::class, 'index'])
        ->name('backoffice.participants.index');
    Route::get('/backoffice/participants/{uid}/edit', [ParticipantsController::class, 'edit'])
        ->name('backoffice.participants.edit');
    Route::put('/backoffice/participants/{uid}', [ParticipantsController::class, 'update'])
        ->name('backoffice.participants.update');
    Route::delete('/backoffice/participants/{uid}', [ParticipantsController::class, 'destroy'])
        ->name('backoffice.participants.destroy');

    // Payments ledger (legacy go=payments). Manual MARKING lives at
    // /admin/payments (ManualPaymentController, P3.5c).
    Route::get('/backoffice/payments', [PaymentsController::class, 'index'])
        ->name('backoffice.payments.index');
    Route::delete('/backoffice/payments/{id}', [PaymentsController::class, 'destroy'])
        ->name('backoffice.payments.destroy');

    // Entries admin
    Route::get('/backoffice/entries', [EntriesController::class, 'index'])
        ->name('backoffice.entries.index');
    Route::post('/backoffice/entries/mark-all', [EntriesController::class, 'markAll'])
        ->name('backoffice.entries.mark_all');
    Route::get('/backoffice/entries/{id}/edit', [EntriesController::class, 'edit'])
        ->name('backoffice.entries.edit');
    Route::put('/backoffice/entries/{id}', [EntriesController::class, 'update'])
        ->name('backoffice.entries.update');
    Route::delete('/backoffice/entries/{id}', [EntriesController::class, 'destroy'])
        ->name('backoffice.entries.destroy');

    // Style-aggregation reports (legacy go=count_by_style / count_by_substyle)
    Route::get('/backoffice/count-by-style', EntriesByStyleController::class)
        ->name('backoffice.count_by_style');
    Route::get('/backoffice/count-by-substyle', EntriesBySubstyleController::class)
        ->name('backoffice.count_by_substyle');
});
