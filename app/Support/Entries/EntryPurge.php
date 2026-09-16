<?php

declare(strict_types=1);

namespace App\Support\Entries;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Entry purge predicates shared by the Entries screen and the Preferences
 * "Purge stale entries" action (legacy data_integrity_check() →
 * purge_entries(type, 1), common.lib.php:3763/485-599).
 *
 * "Stale" = untouched for 24 hours (brewing.brewUpdated older than a day)
 * AND (unconfirmed OR a style that requires special-ingredient info but has
 * none). Legacy keyed the interval off brewUpdated, not a created date.
 */
final class EntryPurge
{
    /**
     * Entries whose style demands special-ingredient info but that carry
     * none. Version predicate mirrors data_cleanup.inc.php:39-46 (BJCP2025
     * and AABC2025 span two seeded versions; every other set matches its
     * own version).
     *
     * @return list<int>
     */
    public static function missingSpecialInfo(TenantContext $ctx): array
    {
        $set = $ctx->prefsStr('prefsStyleSet');

        $query = DB::table('brewing as a')
            ->join('styles as b', function ($j): void {
                $j->on('a.brewCategorySort', '=', 'b.brewStyleGroup')
                    ->on('a.brewSubCategory', '=', 'b.brewStyleNum');
            })
            ->where('b.brewStyleReqSpec', '1')
            ->where(function ($q): void {
                $q->whereNull('a.brewInfo')->orWhere('a.brewInfo', '');
            });

        if ($set === 'BJCP2025') {
            $query->whereIn('b.brewStyleVersion', ['BJCP2021', 'BJCP2025']);
        } elseif ($set === 'AABC2025') {
            $query->whereIn('b.brewStyleVersion', ['AABC2022', 'AABC2025']);
        } else {
            $query->where('b.brewStyleVersion', $set);
        }

        return array_values(array_map('intval', $query->pluck('a.id')->all()));
    }

    /**
     * Ids of stale entries: untouched for 24h AND (unconfirmed OR missing
     * required special-ingredient info).
     *
     * @return list<int>
     */
    public static function stale(TenantContext $ctx, int $now): array
    {
        $cutoff = date('Y-m-d H:i:s', $now - 86400);
        $special = self::missingSpecialInfo($ctx);

        $query = DB::table('brewing')
            ->where('brewUpdated', '<', $cutoff)
            ->where(function ($q) use ($special): void {
                $q->where('brewConfirmed', '0');
                if ($special !== []) {
                    $q->orWhereIn('id', $special);
                }
            });

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    /** Delete the stale entries; returns how many rows were removed. */
    public static function purgeStale(TenantContext $ctx, int $now): int
    {
        $ids = self::stale($ctx, $now);

        return $ids === [] ? 0 : DB::table('brewing')->whereIn('id', $ids)->delete();
    }
}
