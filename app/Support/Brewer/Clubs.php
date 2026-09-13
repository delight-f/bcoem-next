<?php

declare(strict_types=1);

namespace App\Support\Brewer;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy `brewerClubs` storage semantics, ported byte-for-byte from
 * `includes/process/process_brewer_info.inc.php:60-73`: a submitted value
 * that names a known club is stored as-is; "Other" stores the free-text
 * field Title-Cased ("Other" itself when left blank); anything else is
 * cleared. The value is a single string — legacy's select offers one club,
 * never a list.
 *
 * Known-club sources for the picker and validation: the contest-info
 * `contestClubs` JSON session list, every club already stored on a `brewer`
 * row, and — since issue #22 — the local mirror of the published central
 * clubs list (`clubs` table, populated by ClubsSyncService).
 */
final class Clubs
{
    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function value(array $data, TenantContext $ctx): string
    {
        $club = $data['brewerClubs'] ?? null;

        if (empty($club)) {
            return '';
        }

        if (in_array(strtolower((string) $club), self::known($ctx), true)) {
            return (string) $club;
        }

        if ($club === 'Other' && ! empty($data['brewerClubsOther'])) {
            return ucwords((string) $data['brewerClubsOther']);
        }

        // Legacy stores the literal "Other" when the free-text field is
        // blank; blank_to_null() then keeps it (it is not blank).
        return $club === 'Other' ? 'Other' : '';
    }

    /**
     * Every known club name with its original casing, de-duplicated
     * case-insensitively. This is the list a picker or search box shows;
     * known() is the same set folded for comparison.
     *
     * Both sources matter: contestClubs is the list the admin page itself
     * maintains, and brewer rows carry what entrants have already typed.
     * Reading only one of them makes the admin search miss clubs the
     * organizer just added.
     *
     * @return list<string>
     */
    public static function all(TenantContext $ctx): array
    {
        $names = [];

        foreach (self::sources($ctx) as $club) {
            // Key by the folded name so "Foo Club" and "foo club" collapse
            // to whichever spelling was seen first.
            $key = strtolower($club);

            if (! array_key_exists($key, $names)) {
                $names[$key] = $club;
            }
        }

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    public static function known(TenantContext $ctx): array
    {
        return array_map('strtolower', self::all($ctx));
    }

    /**
     * Club names from both storage locations, unsorted and not de-duplicated.
     *
     * @return list<string>
     */
    private static function sources(TenantContext $ctx): array
    {
        $found = [];

        $json = $ctx->contestStr('contestClubs');
        if ($json !== null && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $club) {
                    if (is_string($club) && $club !== '') {
                        $found[] = $club;
                    }
                }
            }
        }

        $stored = DB::table('brewer')
            ->whereNotNull('brewerClubs')
            ->where('brewerClubs', '!=', '')
            ->distinct()
            ->pluck('brewerClubs');

        foreach ($stored as $club) {
            if (is_string($club) && $club !== '') {
                $found[] = $club;
            }
        }

        // Synced central list (issue #22). Appended last so an existing local
        // spelling — from contestClubs or a brewer row — still wins the
        // case-insensitive de-dupe in all(); the sync keeps local casing too.
        // Guarded because this helper also runs during a rolling upgrade,
        // before the clubs migration has applied.
        if (Schema::hasTable('clubs')) {
            // Repair the mirror on first use when the daily schedule cannot
            // run (a plain FTP host has no cron), or when the list has aged
            // out. No-op and throttled once fresh.
            app(ClubsSyncService::class)->refreshIfStale();

            $synced = DB::table('clubs')
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->distinct()
                ->pluck('name');

            foreach ($synced as $club) {
                if (is_string($club) && $club !== '') {
                    $found[] = $club;
                }
            }
        }

        return $found;
    }
}
