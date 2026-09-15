<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Sponsor logo image upload — port of admin/upload.admin.php +
 * handle.php (user_images branch) + process_delete.inc.php go=image.
 *
 * Files live in public/user_images (same directory the sponsor edit form's
 * logo dropdown and the public sponsors section read). Upload parity:
 * extension allow-list .jpg/.jpeg/.png/.svg/.webp/.gif, server-side mime
 * check, 20MB cap, PHP/executable blacklist, clean_filename + lowercase,
 * same-name overwrite. Delete redirects with legacy msg=31 ("File(s)
 * deleted successfully."), rejected uploads with msg=30.
 */
final class UploadController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['.jpeg', '.jpg', '.png', '.gif', '.webp', '.svg'];

    private const ALLOWED_MIMES = ['image/jpeg', 'image/jpg', 'image/gif', 'image/png', 'image/webp', 'image/svg+xml'];

    private const BLACKLIST = ['php', 'php3', 'php4', 'phtml', 'exe'];

    private const MAX_UPLOAD_BYTES = 20000000;

    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $single = $request->query('action') === 'html';

        return view('admin.upload', [
            'ctx' => TenantContext::load(),
            'files' => self::directoryList(),
            'single' => $single,
        ]);
    }

    /**
     * Multi-file dropzone fallback POST (and the single-file form posts
     * here too). Rejected files are skipped; accepted ones overwrite
     * same-named files, exactly like handle.php.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $suffix = $request->query('action') === 'html' ? '?action=html&msg=' : '?msg=';

        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return redirect('/admin/upload'.$suffix.'32');
        }

        /** @var list<UploadedFile> $files */
        $files = array_values((array) $request->file('file', []));

        $anyAccepted = false;
        $anyWriteFailed = false;
        foreach ($files as $file) {
            if (! $file->isValid()) {
                continue;
            }

            $extension = strtolower(strrchr($file->getClientOriginalName(), '.') ?: '');
            $parts = explode('.', $file->getClientOriginalName());
            if (in_array(end($parts), self::BLACKLIST, true)
                || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)
                || $file->getSize() > self::MAX_UPLOAD_BYTES) {
                continue;
            }

            // "Contains" mime match: libmagic formats vary across servers
            // (handle.php keeps stripos() for exactly this reason).
            $mime = mime_content_type($file->getRealPath()) ?: '';
            $mimeOk = false;
            foreach (self::ALLOWED_MIMES as $allowed) {
                if (stripos($mime, $allowed) !== false) {
                    $mimeOk = true;
                    break;
                }
            }
            if (! $mimeOk) {
                continue;
            }

            $name = strtolower(self::cleanFilename($file->getClientOriginalName()));
            if ($name === '' || str_contains($name, '..')) {
                continue;
            }

            try {
                $file->move($dir, $name);
            } catch (\Throwable) {
                // The file passed every check and still could not be written,
                // which is a folder the web server cannot write to — not a bad
                // file. Reported as msg=32 rather than blamed on the file type.
                $anyWriteFailed = true;

                continue;
            }

            $anyAccepted = true;
        }

        return redirect('/admin/upload'.$suffix.($anyAccepted ? '29' : ($anyWriteFailed ? '32' : '30')));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $name = basename((string) $request->input('file'));
        $dir = realpath(self::directory());
        if ($dir !== false) {
            $real = realpath(self::directory().$name);
            if ($real !== false && str_starts_with($real, $dir.DIRECTORY_SEPARATOR) && is_file($real)) {
                unlink($real);
            }
        }

        $suffix = $request->input('view') === 'html' ? '?action=html&msg=31' : '?msg=31';

        return redirect('/admin/upload'.$suffix);
    }

    /** @return list<array{name: string, mtime: int}> */
    private static function directoryList(): array
    {
        $dir = self::directory();
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.$name;
            if (is_file($path) && ! str_starts_with($name, '.')) {
                $files[] = ['name' => $name, 'mtime' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $files;
    }

    private static function directory(): string
    {
        return public_path('user_images').DIRECTORY_SEPARATOR;
    }

    /**
     * Port of lib/common.lib.php clean_filename(): accents → ASCII,
     * scrub special chars, spaces/underscores → dashes, collapse dashes,
     * keep one dot before the extension.
     */
    private static function cleanFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($translit !== false && $translit !== '') {
            $name = $translit;
        }
        $name = str_replace([' ', '_'], '-', $name);
        $name = preg_replace('/[^A-Za-z0-9\-]/', '', $name) ?? '';
        $name = preg_replace('/-+/', '-', $name) ?? '';

        return $name === '' ? '' : $name.'.'.strtolower($extension);
    }
}
