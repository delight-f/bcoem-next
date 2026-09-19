<?php

declare(strict_types=1);

use App\Http\Controllers\Eval\EvalDashboardController;
use App\Http\Controllers\Eval\EvalImportController;
use App\Http\Controllers\Eval\EvalMyAccountController;
use App\Http\Controllers\Eval\EvalProcessController;
use App\Http\Controllers\Eval\EvalScoresheetController;
use Illuminate\Support\Facades\Route;

// Phase 4 / P4.6 — evaluation sub-app under /eval (spec §7).
//
// The eval app SHARES the tenant DB (legacy eval/db.eval.php used the
// main $prefix tables); there is no second connection and no separate
// session guard — the standard web session + auth middleware is the
// "separate session model", with the /eval prefix as its namespace.
// Archived-competition reads are supported via ?archive=<suffix>, which
// extends the brewer/brewing table names (baseline_brewing_<suffix>);
// evaluation/judging_scores stay unsuffixed, matching legacy.
//
// Ported surfaces: dashboard, my_account, warnings, process and
// import_scores; the full, checklist, structured and NW Cider structured
// scoresheets (selected by jPrefsScoresheet and the entry's style type);
// descriptors; and the judging_dashboard/judging_admin surfaces folded
// into the dashboard view. Deliberately not ported: the per-competition
// `install_eval_db` action, because the evaluation schema ships with the
// baseline SQL instead.
//
// Admin gating is in-controller (userLevel<=1) like every admin surface.

Route::middleware(['auth', 'eval.enabled'])->prefix('eval')->name('eval.')->group(function (): void {
    Route::get('/', [EvalDashboardController::class, 'show'])->name('dashboard');
    Route::get('/my-account', [EvalMyAccountController::class, 'show'])->name('my_account');

    Route::get('/import-scores', [EvalImportController::class, 'show'])->name('import');
    Route::post('/import-scores', [EvalImportController::class, 'import'])->name('import.run');

    Route::get('/scoresheet/{entryId}', [EvalScoresheetController::class, 'show'])->whereNumber('entryId')->name('scoresheet');
    Route::get('/scoresheet/{entryId}/output', [EvalScoresheetController::class, 'output'])->whereNumber('entryId')->name('output');

    Route::post('/process', [EvalProcessController::class, 'store'])->name('process');
    Route::post('/process/{evaluationId}', [EvalProcessController::class, 'update'])->whereNumber('evaluationId')->name('process.update');
});
