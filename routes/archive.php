<?php

// P5.6 archive + purge flows (spec §7; ledger/archive-purge.md).
// HIGHEST DATA-LOSS-RISK module: every mutation is admin-gated in the
// controller AND requires the confirm=yes field posted by the warning UI.

use App\Http\Controllers\Archive\ArchiveController;
use App\Http\Controllers\Archive\PurgeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/admin/archive', [ArchiveController::class, 'index'])
        ->name('admin.archive.index');
    Route::post('/admin/archive', [ArchiveController::class, 'store'])
        ->name('admin.archive.store');

    // Purge/reset dashboard + flows (data_cleanup.inc.php ports).
    Route::get('/admin/purge', [PurgeController::class, 'index'])
        ->name('admin.purge.index');

    Route::post('/admin/purge/{flow}', [PurgeController::class, 'run'])
        ->name('admin.purge.run')
        ->whereIn('flow', [
            'unpaid',
            'unconfirmed',
            'confirmed',
            'cleanup',
            'entries',
            'participants',
            'scores',
            'scoresheets',
            'tables',
            'custom',
            'judge-assignments',
            'steward-assignments',
            'availability',
            'evaluation',
            'payments',
            'purge-all',
        ]);
});
