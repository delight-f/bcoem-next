<?php

declare(strict_types=1);

namespace App\Support\Brewer;

/**
 * Outcome of a clubs-list sync (issue #22, Task B.3).
 *
 * Every terminal path of ClubsSyncService::sync() returns one of these:
 * a real sync, an "already up to date" short-circuit, or a failure that
 * guarantees the clubs table was left untouched. The command and the admin
 * screen both render `summary()`, so the user-facing wording lives here
 * rather than being duplicated at each call site.
 */
final class SyncResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $upToDate,
        public readonly ?string $version,
        public readonly int $added,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly ?string $failure,
    ) {}

    public static function success(string $version, int $added, int $updated, int $unchanged): self
    {
        return new self(true, false, $version, $added, $updated, $unchanged, null);
    }

    public static function upToDate(string $version): self
    {
        return new self(true, true, $version, 0, 0, 0, null);
    }

    public static function failure(string $message): self
    {
        return new self(false, false, null, 0, 0, 0, $message);
    }

    /**
     * One-line, human-readable outcome. `updated` counts every club that was
     * already present and had its last_seen_at refreshed; `unchanged` is 0 by
     * construction on a content-changing sync (a version that had not changed
     * short-circuits earlier as up-to-date).
     */
    public function summary(): string
    {
        if (! $this->ok) {
            return 'Clubs list sync failed: '.($this->failure ?? 'unknown error')
                .'. The local clubs table was left unchanged.';
        }

        if ($this->upToDate) {
            return 'Clubs list already up to date (version '.($this->version ?? 'unknown').').';
        }

        return sprintf(
            'Clubs list synced to version %s: %d added, %d updated, %d unchanged.',
            (string) $this->version,
            $this->added,
            $this->updated,
            $this->unchanged,
        );
    }
}
