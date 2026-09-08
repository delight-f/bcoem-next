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
    public function show(): View
    {
        $files = collect(is_dir(UserDocs::root()) ? scandir(UserDocs::root()) : [])
            ->filter(fn (string|false $f): bool => is_string($f) && str_ends_with(strtolower($f), '.pdf'))
            ->sort()
            ->values();

        return view('admin.upload-scoresheets', [
            'ctx' => TenantContext::load(),
            'files' => $files,
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
}
