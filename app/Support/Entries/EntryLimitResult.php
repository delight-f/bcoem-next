<?php

declare(strict_types=1);

namespace App\Support\Entries;

/**
 * Outcome of the entry-limits decision (ticket 11).
 *
 * $reason carries the legacy msg code for redirect parity:
 * '' allowed, '8' per-user cap, '9' subcategory cap,
 * '403' non-owner submission attempt.
 */
final class EntryLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}
}
