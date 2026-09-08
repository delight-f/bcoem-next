<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use Carbon\CarbonImmutable;

/**
 * Standalone date renderer for tenant-facing timestamps.
 *
 * Mirrors the observable contract pinned by TimeZoneEpochTest: an epoch is
 * rendered in the tenant's configured UTC-offset timezone using the
 * prefsDateFormat / prefsTimeFormat codes (0 = Y/m/d, 1 = m/d/Y US,
 * 2 = d/m/Y intl, 999 = system; 0 = 12h, 1 = 24h). The "long" style spells
 * weekday/month names — legacy uses it whenever prefsLanguage starts "en-".
 */
final class DateFmt
{
    public static function tz(string|int|float|null $offset): string
    {
        $key = number_format((float) ($offset ?? 0), 3, '.', '');

        return (string) (config('timezones')[$key] ?? 'UTC');
    }

    private static function carbon(int $epoch, string|int|float|null $tzOffset): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($epoch)->setTimezone(self::tz($tzOffset));
    }

    /**
     * @param  'short'|'long'|'system'  $style
     */
    public static function date(
        int|string|null $epoch,
        string|int|float|null $tzOffset,
        string|int|null $dateFormat,
        string $style = 'short',
    ): ?string {
        if ($epoch === null || $epoch === '' || ! is_numeric($epoch)) {
            return null;
        }

        $dt = self::carbon((int) $epoch, $tzOffset);
        $code = (int) $dateFormat;

        return match ($style) {
            'long' => $code === 1 ? $dt->format('l, F j, Y') : $dt->format('l j F, Y'),
            'system' => $dt->format('Y-m-d'),
            default => match ($code) {
                1 => $dt->format('m/d/Y'),
                2 => $dt->format('d/m/Y'),
                999 => $dt->format('Y-m-d H:i:s'),
                default => $dt->format('Y/m/d'),
            },
        };
    }

    /**
     * Full "date time, TZ" render; $withZone=false drops the trailing zone
     * name (legacy date-time-no-gmt / date-no-gmt variants).
     *
     * @param  'short'|'long'|'system'  $style
     */
    public static function dateTime(
        int|string|null $epoch,
        string|int|float|null $tzOffset,
        string|int|null $dateFormat,
        string|int|null $timeFormat,
        string $style = 'short',
        bool $withZone = true,
        bool $withTime = true,
    ): ?string {
        $date = self::date($epoch, $tzOffset, $dateFormat, $style);

        if ($date === null) {
            return null;
        }

        if (! $withTime || ! is_numeric($epoch)) {
            return $date;
        }

        $dt = self::carbon((int) $epoch, $tzOffset);
        $time = $dt->format(((int) $timeFormat === 1) ? 'H:i' : 'g:i A');

        return $date.' '.$time.($withZone ? ', '.$dt->format('T') : '');
    }

    /**
     * Value format for the flatpickr .date-time-picker-system admin inputs
     * (app.js dateFormat 'Y-m-d H:i' / 'Y-m-d h:i K' chosen by the form's
     * data-time-24hr). The 12h variant is zero-padded ('h') so flatpickr's
     * token parser accepts a prefilled value untouched.
     *
     * @param  int|string|null  $epoch  UTC epoch or null/'' for an empty field
     */
    public static function dateTimeInput(
        int|string|null $epoch,
        string|int|float|null $tzOffset,
        bool $time24hr,
    ): ?string {
        if ($epoch === null || $epoch === '' || ! is_numeric($epoch)) {
            return null;
        }

        $dt = self::carbon((int) $epoch, $tzOffset);

        return $dt->format($time24hr ? 'Y-m-d H:i' : 'Y-m-d h:i A');
    }
}
