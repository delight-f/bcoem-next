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

    /**
     * Stored scoresheet PDF basenames, sorted.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $root = self::root();
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (scandir($root) ?: [] as $entry) {
            if (is_file($root.'/'.$entry) && str_ends_with(strtolower($entry), '.pdf')) {
                $files[] = $entry;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Delete one stored scoresheet. Refuses anything that is not a plain
     * `.pdf` basename resolving inside the docs root, so a crafted filename
     * can never reach outside it.
     */
    public static function delete(string $name): bool
    {
        if ($name === '' || basename($name) !== $name || ! str_ends_with(strtolower($name), '.pdf')) {
            return false;
        }

        $root = realpath(self::root());
        $path = realpath(self::root().'/'.$name);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return false;
        }

        return unlink($path);
    }
}
