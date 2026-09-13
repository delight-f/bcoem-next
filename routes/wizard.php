<?php

declare(strict_types=1);

// Install / upgrade wizard. These routes are the one surface that stays
// reachable on an uninstalled site — see App\Http\Middleware\EnsureInstalled,
// whose allow-list names this file's paths.
//
// Upgrades are gated to Top-Level Administrators by the same middleware, and
// both wizards 404 once the install is current. No install/upgrade logic lives
// in these handlers; they call the Part 1 services.

use App\Http\Controllers\InstallWizardController;
use App\Http\Controllers\UpgradeWizardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Install — screens 1-6 (Task 2.4).
Route::get('/install', [InstallWizardController::class, 'welcome'])->name('wizard.install.welcome');
Route::get('/install/checks', [InstallWizardController::class, 'checks'])->name('wizard.install.checks');
Route::get('/install/database', [InstallWizardController::class, 'database'])->name('wizard.install.database');
Route::post('/install/database/test', [InstallWizardController::class, 'testConnection'])->name('wizard.install.test');
Route::post('/install/database', [InstallWizardController::class, 'storeDatabase'])->name('wizard.install.database.store');
// Screen 3's other exit: the database is already a finished site, so attach the
// code to it instead of installing over it.
Route::post('/install/adopt', [InstallWizardController::class, 'adopt'])->name('wizard.install.adopt');
Route::get('/install/site', [InstallWizardController::class, 'site'])->name('wizard.install.site');
Route::post('/install/site', [InstallWizardController::class, 'storeSite'])->name('wizard.install.site.store');
Route::get('/install/confirm', [InstallWizardController::class, 'confirm'])->name('wizard.install.confirm');
Route::post('/install/run', [InstallWizardController::class, 'run'])->name('wizard.install.run');
Route::get('/install/progress', [InstallWizardController::class, 'progress'])->name('wizard.install.progress');

// Upgrade — screens 1-4 (Task 2.5).
Route::get('/upgrade', [UpgradeWizardController::class, 'whatsNew'])->name('wizard.upgrade.whats_new');
Route::get('/upgrade/checks', [UpgradeWizardController::class, 'checks'])->name('wizard.upgrade.checks');
Route::get('/upgrade/confirm', [UpgradeWizardController::class, 'confirm'])->name('wizard.upgrade.confirm');
Route::post('/upgrade/run', [UpgradeWizardController::class, 'run'])->name('wizard.upgrade.run');
Route::get('/upgrade/progress', [UpgradeWizardController::class, 'progress'])->name('wizard.upgrade.progress');

// Per-session banner dismissal (Task 2.3).
Route::post('/upgrade/dismiss', function (Request $request) {
    $request->session()->put('wizard.upgrade.dismissed', true);

    return back();
})->name('wizard.upgrade.dismiss');

// Per-session "new release published" notice dismissal (Task 2.2).
Route::post('/admin/notices/dismiss', function (Request $request) {
    $request->session()->put('wizard.update-notice.dismissed', true);

    return back();
})->name('wizard.notice.dismiss');
