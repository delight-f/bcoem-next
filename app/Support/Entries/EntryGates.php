<?php

declare(strict_types=1);

namespace App\Support\Entries;

/**
 * Edit/delete gating for the entries list, pinned from the legacy surface:
 *
 * - brewer_entries.pub.php:501 (edit link) / :549 (delete link) / :567
 *   (judging-started override): an entry is editable while it has not been
 *   received AND (the entry window is open OR the entry-edit deadline has
 *   not passed), and judging has not started.
 * - brewer_entries.pub.php:586-593: on top of the edit rule, a paid entry
 *   cannot be deleted while the window is open and the competition charges
 *   an entry fee.
 */
final class EntryGates
{
    public static function edit(int $brewReceived, bool $entryWindowOpen, ?int $editDeadline, int $now, bool $judgingStarted): bool
    {
        return self::windowAllows($entryWindowOpen, $editDeadline, $now)
            && ! $judgingStarted
            && $brewReceived === 0;
    }

    public static function delete(int $brewReceived, int $brewPaid, bool $entryWindowOpen, ?int $editDeadline, int $now, bool $judgingStarted, float $entryFee): bool
    {
        if (! self::edit($brewReceived, $entryWindowOpen, $editDeadline, $now, $judgingStarted)) {
            return false;
        }

        return ! ($entryWindowOpen && $brewPaid === 1 && $entryFee > 0);
    }

    private static function windowAllows(bool $entryWindowOpen, ?int $editDeadline, int $now): bool
    {
        return $entryWindowOpen || ($editDeadline !== null && $now < $editDeadline);
    }
}
