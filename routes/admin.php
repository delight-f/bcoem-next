<?php

declare(strict_types=1);

// P5.4 remaining admin screens (spec §7). Owned by the P5.4 ticket.
//
// Every controller action re-checks the admin gate in-controller
// ($request->user()?->isAdmin() → redirect '/?msg=99'), mirroring
// ManualPaymentController/LocationController — 'auth' middleware here only
// guarantees a user, not an admin.

use App\Http\Controllers\Admin\AllDatesController;
use App\Http\Controllers\Admin\ChangeUserPasswordController;
use App\Http\Controllers\Admin\CompetitionInfoController;
use App\Http\Controllers\Admin\ContactsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HeroImagesController;
use App\Http\Controllers\Admin\MakeAdminController;
use App\Http\Controllers\Admin\ModsController;
use App\Http\Controllers\Admin\SendTestEmailController;
use App\Http\Controllers\Admin\SitePreferencesController;
use App\Http\Controllers\Admin\SponsorsController;
use App\Http\Controllers\Admin\StylesAdminController;
use App\Http\Controllers\Admin\StyleTypesController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\UploadScoresheetsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    // Admin landing menu (legacy ?section=admin → admin/default.admin.php).
    Route::get('/admin', DashboardController::class)
        ->name('admin.dashboard');

    Route::get('/admin/upload-scoresheets', [UploadScoresheetsController::class, 'show'])
        ->name('admin.upload_scoresheets');
    Route::post('/admin/upload-scoresheets', [UploadScoresheetsController::class, 'store'])
        ->name('admin.upload_scoresheets.store');

    Route::get('/admin/competition-info', [CompetitionInfoController::class, 'edit'])
        ->name('admin.competition_info.edit');
    Route::put('/admin/competition-info', [CompetitionInfoController::class, 'update'])
        ->name('admin.competition_info.update');
    // Legacy go=qr branch: QR check-in password only, from the modal on the
    // competition-info edit page.
    Route::put('/admin/competition-info/qr-password', [CompetitionInfoController::class, 'updateQrPassword'])
        ->name('admin.competition_info.qr_password');

    // site_preferences — five tabbed sub-forms (go=default|entries|email|payment|best).
    Route::get('/admin/site-preferences/{go?}', [SitePreferencesController::class, 'edit'])
        ->name('admin.site_preferences.edit');
    Route::put('/admin/site-preferences/{go?}', [SitePreferencesController::class, 'update'])
        ->name('admin.site_preferences.update');

    // all_dates — one form writing contest_info dates + judging window + winners delay.
    Route::get('/admin/dates', [AllDatesController::class, 'edit'])
        ->name('admin.dates.edit');
    Route::put('/admin/dates', [AllDatesController::class, 'update'])
        ->name('admin.dates.update');

    // hero_images — list/save checkboxes, upload, delete (legacy posts
    // section=hero_images&action=upload|save|delete to the same URL).
    Route::get('/admin/hero-images', [HeroImagesController::class, 'index'])
        ->name('admin.hero_images.index');
    Route::post('/admin/hero-images/upload', [HeroImagesController::class, 'upload'])
        ->name('admin.hero_images.upload');
    Route::post('/admin/hero-images/save', [HeroImagesController::class, 'save'])
        ->name('admin.hero_images.save');
    Route::post('/admin/hero-images/delete', [HeroImagesController::class, 'delete'])
        ->name('admin.hero_images.delete');

    // go=upload — sponsor logo images into public/user_images
    // (admin/upload.admin.php + handle.php user_images branch +
    // process_delete.inc.php go=image). ?action=html is the single-file
    // variant of the same page.
    Route::get('/admin/upload', [UploadController::class, 'index'])
        ->name('admin.upload.index');
    Route::post('/admin/upload', [UploadController::class, 'store'])
        ->name('admin.upload.store');
    Route::post('/admin/upload/delete', [UploadController::class, 'destroy'])
        ->name('admin.upload.delete');

    // sponsors — CRUD plus the inline bulk update (action=update).
    Route::get('/admin/sponsors', [SponsorsController::class, 'index'])
        ->name('admin.sponsors.index');
    Route::get('/admin/sponsors/create', [SponsorsController::class, 'create'])
        ->name('admin.sponsors.create');
    Route::post('/admin/sponsors', [SponsorsController::class, 'store'])
        ->name('admin.sponsors.store');
    Route::put('/admin/sponsors', [SponsorsController::class, 'bulkUpdate'])
        ->name('admin.sponsors.bulk');
    Route::get('/admin/sponsors/{id}/edit', [SponsorsController::class, 'edit'])
        ->name('admin.sponsors.edit');
    Route::put('/admin/sponsors/{id}', [SponsorsController::class, 'update'])
        ->name('admin.sponsors.update');
    Route::delete('/admin/sponsors/{id}', [SponsorsController::class, 'destroy'])
        ->name('admin.sponsors.destroy');

    // contacts — plain CRUD.
    Route::get('/admin/contacts', [ContactsController::class, 'index'])
        ->name('admin.contacts.index');
    Route::get('/admin/contacts/create', [ContactsController::class, 'create'])
        ->name('admin.contacts.create');
    Route::post('/admin/contacts', [ContactsController::class, 'store'])
        ->name('admin.contacts.store');
    Route::get('/admin/contacts/{id}/edit', [ContactsController::class, 'edit'])
        ->name('admin.contacts.edit');
    Route::put('/admin/contacts/{id}', [ContactsController::class, 'update'])
        ->name('admin.contacts.update');
    Route::delete('/admin/contacts/{id}', [ContactsController::class, 'destroy'])
        ->name('admin.contacts.destroy');

    // mods — CRUD plus the enable-toggle bulk update.
    Route::get('/admin/mods', [ModsController::class, 'index'])->name('admin.mods.index');
    Route::get('/admin/mods/create', [ModsController::class, 'create'])->name('admin.mods.create');
    Route::post('/admin/mods', [ModsController::class, 'store'])->name('admin.mods.store');
    Route::put('/admin/mods', [ModsController::class, 'bulkUpdate'])->name('admin.mods.bulk');
    Route::get('/admin/mods/{id}/edit', [ModsController::class, 'edit'])->name('admin.mods.edit');
    Route::put('/admin/mods/{id}', [ModsController::class, 'update'])->name('admin.mods.update');
    Route::delete('/admin/mods/{id}', [ModsController::class, 'destroy'])->name('admin.mods.destroy');

    // style_types — CRUD plus combine/separate Mead/Cider.
    Route::get('/admin/style-types', [StyleTypesController::class, 'index'])
        ->name('admin.style_types.index');
    Route::get('/admin/style-types/create', [StyleTypesController::class, 'create'])
        ->name('admin.style_types.create');
    Route::post('/admin/style-types', [StyleTypesController::class, 'store'])
        ->name('admin.style_types.store');
    Route::post('/admin/style-types/combine', [StyleTypesController::class, 'combine'])
        ->name('admin.style_types.combine');
    Route::post('/admin/style-types/separate', [StyleTypesController::class, 'separate'])
        ->name('admin.style_types.separate');
    Route::get('/admin/style-types/{id}/edit', [StyleTypesController::class, 'edit'])
        ->name('admin.style_types.edit');
    Route::put('/admin/style-types/{id}', [StyleTypesController::class, 'update'])
        ->name('admin.style_types.update');
    Route::delete('/admin/style-types/{id}', [StyleTypesController::class, 'destroy'])
        ->name('admin.style_types.destroy');

    // styles — accepted-styles/at-limit checklist (PUT = bulk update) plus
    // custom-style CRUD (brewStyleOwn='custom').
    Route::get('/admin/styles', [StylesAdminController::class, 'index'])
        ->name('admin.styles.index');
    Route::put('/admin/styles', [StylesAdminController::class, 'bulkUpdate'])
        ->name('admin.styles.bulk');
    Route::get('/admin/styles/create', [StylesAdminController::class, 'create'])
        ->name('admin.styles.create');
    Route::post('/admin/styles', [StylesAdminController::class, 'store'])
        ->name('admin.styles.store');
    Route::get('/admin/styles/{id}/edit', [StylesAdminController::class, 'edit'])
        ->name('admin.styles.edit');
    Route::put('/admin/styles/{id}', [StylesAdminController::class, 'update'])
        ->name('admin.styles.update');
    Route::delete('/admin/styles/{id}', [StylesAdminController::class, 'destroy'])
        ->name('admin.styles.destroy');

    // make_admin / change_user_password — per-user account ops.
    Route::get('/admin/users/{id}/level', [MakeAdminController::class, 'edit'])
        ->name('admin.make_admin.edit');
    Route::put('/admin/users/{id}/level', [MakeAdminController::class, 'update'])
        ->name('admin.make_admin.update');
    Route::get('/admin/users/{id}/password', [ChangeUserPasswordController::class, 'edit'])
        ->name('admin.change_user_password.edit');
    Route::put('/admin/users/{id}/password', [ChangeUserPasswordController::class, 'update'])
        ->name('admin.change_user_password.update');

    // send_test_email — GET performs the send (legacy sends during render).
    Route::get('/admin/send-test-email', [SendTestEmailController::class, 'show'])
        ->name('admin.send_test_email.show');
});
