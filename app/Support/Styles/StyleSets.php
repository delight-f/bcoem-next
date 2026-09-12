<?php

declare(strict_types=1);

namespace App\Support\Styles;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Style sets — one source of truth for the six sets this port offers,
 * ported from the upstream master `includes/styles.inc.php` table.
 *
 * Columns per set:
 *  - short:        picker label ("BJCP 2021 / 2025");
 *  - long:         full set name ("BJCP Beer 2021, Mead 2015, Cider 2025");
 *  - separator:    display separator between group and sub-style;
 *  - subStyleMethod: upstream `sub_style` flag — 1 for AABC/BA sets, whose
 *                  style numbers already carry the group prefix;
 *  - noNumbering:  upstream `no_numbering` — BA sets do not number styles,
 *                  so displays omit the group-num prefix entirely.
 *
 * Deliberately EXCLUDED (legacy-only values still present in the styles
 * table and still honoured by the predicates): `BA`, `AABC` (2019),
 * `BJCP2008`, `BJCP2015`. They resolve to their raw value / false / ''
 * through the fallbacks below rather than being listable.
 *
 * Dual-version sets (ledger styles pins #5-#7): a set may span the current
 * version AND its predecessor. `brewStyleType = '2'` ("other" cider/perry
 * rows) lives in the NEW version; every other row stays under the previous
 * one. Custom rows (`brewStyleOwn = 'custom'`) extend every set and bypass
 * all version filters.
 */
final class StyleSets
{
    /**
     * Ordered set table. Order is the picker order.
     *
     * @var array<string, array{short: string, long: string, separator: string, subStyleMethod: int, noNumbering: bool}>
     */
    private const SETS = [
        'BJCP2025' => [
            'short' => 'BJCP 2021 / 2025',
            'long' => 'BJCP Beer 2021, Mead 2015, Cider 2025',
            'separator' => '',
            'subStyleMethod' => 0,
            'noNumbering' => false,
        ],
        'BJCP2021' => [
            'short' => 'BJCP 2015 / 2021',
            'long' => 'BJCP Beer 2021, Mead and Cider 2015',
            'separator' => '',
            'subStyleMethod' => 0,
            'noNumbering' => false,
        ],
        'BA2026' => [
            'short' => 'BA 2026',
            'long' => 'Brewers Association 2026',
            'separator' => '-',
            'subStyleMethod' => 1,
            'noNumbering' => true,
        ],
        'AABC2022' => [
            'short' => 'AABC 2022',
            'long' => 'Australian Amateur Brewing Championship 2022',
            'separator' => '.',
            'subStyleMethod' => 1,
            'noNumbering' => false,
        ],
        'AABC2025' => [
            'short' => 'AABC 2025',
            'long' => 'Australian Amateur Brewing Championship 2025',
            'separator' => '.',
            'subStyleMethod' => 1,
            'noNumbering' => false,
        ],
        'NWCiderCup' => [
            'short' => 'NW Cider Cup',
            'long' => 'Northwest Cider Cup',
            'separator' => '-',
            'subStyleMethod' => 0,
            'noNumbering' => false,
        ],
    ];

    /** Dual-version sets: set value => the predecessor version it spans. */
    private const DUAL_VERSION = [
        'BJCP2025' => 'BJCP2021',
        'AABC2025' => 'AABC2022',
    ];

    /**
     * Legacy-only values the port no longer offers but must still behave
     * like the set they were superseded by (installs carrying them): the
     * old Brewers Association set is the same no-numbering family as BA2026.
     */
    private const LEGACY_ALIASES = [
        'BA' => 'BA2026',
    ];

    /**
     * The whole table, keyed by set value, in picker order.
     *
     * @return array<string, array{short: string, long: string, separator: string, subStyleMethod: int, noNumbering: bool}>
     */
    public static function all(): array
    {
        return self::SETS;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::SETS);
    }

    /** Picker label (short name); unknown/legacy values fall back to the raw value. */
    public static function label(string $value): string
    {
        return self::SETS[$value]['short'] ?? $value;
    }

    /** Upstream `no_numbering`: displays omit the group-num prefix. */
    public static function noNumbering(string $value): bool
    {
        return self::SETS[self::LEGACY_ALIASES[$value] ?? $value]['noNumbering'] ?? false;
    }

    /** Display separator between group and sub-style. */
    public static function separator(string $value): string
    {
        return self::SETS[$value]['separator'] ?? '';
    }

    /** Upstream `sub_style` flag. */
    public static function subStyleMethod(string $value): int
    {
        return self::SETS[$value]['subStyleMethod'] ?? 0;
    }

    /**
     * The versions a set spans, NEWEST FIRST. Dual sets (BJCP2025,
     * AABC2025) list the current version before the predecessor it spans;
     * every other set is itself. Order is the resolution order — the first
     * version that yields a row wins, so a code present in both versions
     * resolves to the newest row.
     *
     * @return list<string>
     */
    public static function versions(string $set): array
    {
        return isset(self::DUAL_VERSION[$set])
            ? [$set, self::DUAL_VERSION[$set]]
            : [$set];
    }

    /**
     * Resolve a single style row under the active set: try the set's
     * versions in {@see versions()} order (newest first) and return the
     * first match, always also accepting `brewStyleOwn = 'custom'`.
     *
     * `$group` and `$num` must already be canonical (`01`/`C1`, `04`) —
     * callers canonicalize; there is deliberately no re-padding here, so a
     * code storing `'001'` will NOT match (see EntriesController pin 4).
     */
    public static function findStyle(string $set, string $group, string $num): ?\stdClass
    {
        foreach (self::versions($set) as $version) {
            $row = DB::table('styles')
                ->where('brewStyleGroup', $group)
                ->where('brewStyleNum', $num)
                ->where(function (Builder $q) use ($version): void {
                    $q->where('brewStyleVersion', $version)->orWhere('brewStyleOwn', 'custom');
                })
                ->first();

            if ($row !== null) {
                return (object) $row;
            }
        }

        return null;
    }

    /**
     * The active-set lookup predicate (ledger styles pins #5-#7).
     *
     * Dual-version sets:
     *   (version = SET AND brewStyleType = '2')
     *   OR (version = PREV AND brewStyleType != '2')
     *   OR brewStyleOwn = 'custom'
     * Everything else:
     *   brewStyleVersion = SET OR brewStyleOwn = 'custom'
     *
     * Wrapped in one outer grouping so callers can chain freely; the
     * returned builder has no columns selected and no ordering applied.
     * A set with no table entry (e.g. `BA2026` before its rows are
     * imported, or a legacy value) takes the plain-equality branch.
     */
    public static function activeQuery(string $set): Builder
    {
        return DB::table('styles')->where(function (Builder $q) use ($set): void {
            if (isset(self::DUAL_VERSION[$set])) {
                $previous = self::DUAL_VERSION[$set];

                $q->where(function (Builder $qq) use ($set): void {
                    $qq->where('brewStyleVersion', $set)->where('brewStyleType', '2');
                })->orWhere(function (Builder $qq) use ($previous): void {
                    $qq->where('brewStyleVersion', $previous)->where('brewStyleType', '!=', '2');
                });
            } else {
                $q->where('brewStyleVersion', $set);
            }

            $q->orWhere('brewStyleOwn', 'custom');
        });
    }
}
