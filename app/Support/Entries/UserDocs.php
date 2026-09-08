<?php

declare(strict_types=1);

namespace App\Support\Entries;

/**
 * Entrant scoresheet storage root. Files live OUTSIDE the webroot
 * (storage/user_docs) — never under public/ — so PDFs are reachable only
 * through the authorized, traversal-clamped stream in
 * Output\ScoresheetsController. Legacy kept them in the docroot behind an
 * .htaccess deny; serving nothing directly is the modern equivalent.
 */
final class UserDocs
{
    public static function root(): string
    {
        return storage_path('user_docs');
    }

    /** Absolute path for a scoresheet filename (caller clamps traversal). */
    public static function path(string $name): string
    {
        return self::root().'/'.$name;
    }
}
