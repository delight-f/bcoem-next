<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Entries\UserDocs;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Admin scoresheet uploads (P4.4 back-office companion to
 * Output\ScoresheetsController). Spec: admin/upload_scoresheets.admin.php —
 * PDFs named with the six-digit entry number (000198.pdf), stored in the
 * entrant-docs root, streamed later through the clamped output endpoint.
 */
final class UploadScoresheetsController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        return view('admin.upload-scoresheets', [
            'ctx' => TenantContext::load(),
            'files' => UserDocs::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'extensions:pdf', 'max:20480'],
        ]);

        foreach ($data['files'] as $file) {
            /** @var UploadedFile $file */
            $name = basename($file->getClientOriginalName());
            // Legacy names each PDF with the six-digit entry number; keep a
            // bare basename and let the organizer's naming discipline rule.
            $file->move(UserDocs::root(), $name);
        }

        return redirect('/admin/upload-scoresheets?msg=2');
    }

    /**
     * Delete All (legacy process.inc.php action=delete_scoresheets →
     * rdelete(USER_DOCS)). Admin gate comes from the route middleware.
     */
    public function destroyAll(): RedirectResponse
    {
        foreach (UserDocs::all() as $name) {
            UserDocs::delete($name);
        }

        return redirect('/admin/upload-scoresheets?msg=31');
    }

    /**
     * Delete one file (legacy process_delete.inc.php go=doc branch →
     * unlink(USER_DOCS.basename($filter))). UserDocs::delete clamps the
     * name to a bare .pdf basename inside the docs root.
     */
    public function destroy(string $file): RedirectResponse
    {
        UserDocs::delete($file);

        return redirect('/admin/upload-scoresheets?msg=31');
    }
}
