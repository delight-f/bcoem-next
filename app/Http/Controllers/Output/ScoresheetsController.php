<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Entries\UserDocs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Scoresheet download (spec §7 P5.2).
 *
 * NOT generation and NOT bundling: the legacy module
 * (output/scoresheets.output.php, reached via output.inc.php
 * section=scoresheet) streams a SINGLE uploaded scoresheet PDF from the
 * protected USER_DOCS directory as an attachment download. Its
 * obfuscated-filename dance (encrypt/decrypt + copy into user_temp)
 * existed to hide paths from the brewer-facing URL; behind this
 * authenticated admin route it is unnecessary, so the port resolves
 * ?file= directly inside the non-public storage/user_docs directory
 * (basename-clamped, which also blocks the ../ traversal legacy never
 * checked; files outside the webroot are additionally unreachable by URL).
 *
 * DIVERGENCE: no filename obfuscation, no user_temp copy, no per-entry
 * subdirectory parameter (?view=); the file is streamed in place.
 */
final class ScoresheetsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // basename() clamps any path component — ?file=../secret.pdf can
        // only ever resolve inside user_docs.
        $fileQuery = $request->query('file', '');
        $name = basename(is_string($fileQuery) ? $fileQuery : '');
        if ($name === '' || $name === '.' || $name === '..') {
            abort(404);
        }

        $path = UserDocs::path($name);
        if (! is_file($path)) {
            abort(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
