<?php

declare(strict_types=1);

namespace App\Services\Installation\Fixups;

use App\Services\Installation\UpgradeFixup;
use App\Support\Brewer\ClubsSyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Primes the central clubs list after the migration that adds its tables
 * (issue #22).
 *
 * The wizard and `app:upgrade` create the `clubs` tables, but `clubs:sync` is
 * schedule-driven and a shared/FTP host has no cron — so without this the
 * mirror would stay empty until someone found Admin → Clubs List. Running the
 * first sync as an upgrade fixup populates the picker the moment the upgrade
 * finishes, through the mechanism the operator already used.
 *
 * Best-effort: sync() already turns a fetch failure into a no-op, and anything
 * unexpected is swallowed so the clubs list can never fail an upgrade. The
 * picker's lazy refresh (ClubsSyncService::refreshIfStale) is the safety net
 * when this fixup's version jump does not match — a skipped or renumbered
 * release still self-heals on first use.
 */
final class SyncCentralClubsList implements UpgradeFixup
{
    public function run(): void
    {
        try {
            app(ClubsSyncService::class)->sync();
        } catch (Throwable $e) {
            Log::warning('Clubs list sync during upgrade failed.', ['exception' => $e->getMessage()]);
        }
    }
}
