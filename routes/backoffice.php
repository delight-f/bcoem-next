<?php

// P5.5 back-office: participants, payments, entries admin + style reports
// (spec §7). Owned by the P5.5 ticket.

use App\Http\Controllers\Admin\EntriesByStyleController;
use App\Http\Controllers\Admin\EntriesBySubstyleController;
use App\Http\Controllers\Admin\EntriesController;
use App\Http\Controllers\Admin\ParticipantsController;
use App\Http\Controllers\Admin\PaymentsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'admin'])->group(function () {
    // Participants
    Route::get('/backoffice/participants', [ParticipantsController::class, 'index'])
        ->name('backoffice.participants.index');
    Route::get('/backoffice/participants/{uid}/edit', [ParticipantsController::class, 'edit'])
        ->name('backoffice.participants.edit');
    Route::put('/backoffice/participants/{uid}', [ParticipantsController::class, 'update'])
        ->name('backoffice.participants.update');
    Route::delete('/backoffice/participants/{uid}', [ParticipantsController::class, 'destroy'])
        ->name('backoffice.participants.destroy');

    // Payments ledger (legacy go=payments): the transaction-records page
    // lives at the legacy-correct /admin/payments URL; manual marking is a
    // port-only function relocated to /admin/payments/mark.
    Route::get('/admin/payments', [PaymentsController::class, 'index'])
        ->name('admin.payments.index');
    Route::delete('/admin/payments/{id}', [PaymentsController::class, 'destroy'])
        ->name('admin.payments.destroy');
    // Refund (payments plan W6): verified Stripe refund via the gateway,
    // flag reversal through PaymentService::markRefunded (#8).
    Route::post('/admin/payments/{id}/refund', [PaymentsController::class, 'refund'])
        ->name('admin.payments.refund');

    // Entries admin
    Route::get('/backoffice/entries', [EntriesController::class, 'index'])
        ->name('backoffice.entries.index');
    Route::post('/backoffice/entries/mark-all', [EntriesController::class, 'markAll'])
        ->name('backoffice.entries.mark_all');
    // Legacy data_cleanup.inc.php purge flows (Admin Actions menu):
    // go=unconfirmed / go=unpaid, level-0 only (data_cleanup guard).
    Route::post('/backoffice/entries/purge', [EntriesController::class, 'purge'])
        ->name('backoffice.entries.purge')->middleware('admin.top');
    // Legacy entries.admin.php single form wrapping the table: inline
    // judging-number / paid / received / box / notes edits POST together
    // (legacy saved each via AJAX save_column; the port saves the form).
    Route::put('/backoffice/entries', [EntriesController::class, 'updateForm'])
        ->name('backoffice.entries.update_form');
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
