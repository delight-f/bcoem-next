<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

/**
 * What is already inside the database named by a set of credentials.
 *
 * The install wizard asks for connection details and, until this existed,
 * never looked at what the database held — it collected three screens of input
 * before refusing on the final click ("already installed"), which is a dead end
 * for someone who has replaced the old application files but kept their
 * database. Inspecting the target directly lets the wizard branch on it instead.
 *
 * Read-only: nothing here changes the database.
 */
final readonly class DatabaseInspection
{
    /** No tables: an ordinary fresh install. */
    public const STATE_EMPTY = 'empty';

    /** `bcoem_sys.setup` is 1: a finished site, of any version. */
    public const STATE_INSTALLED = 'installed';

    /** `bcoem_sys` exists but setup never completed. */
    public const STATE_INCOMPLETE = 'incomplete';

    /** Tables present, none of them ours — almost certainly the wrong database. */
    public const STATE_UNRECOGNISED = 'unrecognised';

    public function __construct(
        public string $state,
        public string $message,
        public string $version = '',
        public int $tableCount = 0,
        public string $privilegeWarning = '',
    ) {}

    /** Installing creates the schema and replaces `users`/`brewer`, so only an empty database is safe. */
    public function canInstall(): bool
    {
        return $this->state === self::STATE_EMPTY;
    }

    /** Adopting keeps the data as-is and only writes connection details. */
    public function canAdopt(): bool
    {
        return $this->state === self::STATE_INSTALLED;
    }

    public function isBlocked(): bool
    {
        return ! $this->canInstall() && ! $this->canAdopt();
    }
}
