<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use Illuminate\Support\Facades\DB;

/**
 * Per-request snapshot of the three configuration rows every page reads:
 * `contest_info` (id=1), `preferences` (id=1) and `judging_preferences`
 * (id=1).
 *
 * Legacy copies these rows wholesale into $_SESSION behind cache flags
 * that are invalidated on save, so "a change takes effect on the next
 * request". The standalone build is stateless: it simply reads fresh rows
 * per request, which trivially satisfies that contract for anonymous
 * traffic.
 *
 * Column access stays string-typed like the DB rows; consumers cast.
 */
final class TenantContext
{
    /** @var array<string, mixed> */
    public private(set) array $contest = [];

    /** @var array<string, mixed> */
    public private(set) array $prefs = [];

    /** @var array<string, mixed> */
    public private(set) array $judging = [];

    public static function load(): self
    {
        $self = new self;
        $self->contest = (array) DB::table('contest_info')->where('id', 1)->first();
        $self->prefs = (array) DB::table('preferences')->where('id', 1)->first();
        $self->judging = (array) DB::table('judging_preferences')->where('id', 1)->first();

        return $self;
    }

    public function contestStr(string $key): ?string
    {
        return self::str($this->contest, $key);
    }

    public function prefsStr(string $key): ?string
    {
        return self::str($this->prefs, $key);
    }

    public function judgingStr(string $key): ?string
    {
        return self::str($this->judging, $key);
    }

    /**
     * Contest date column as a UTC epoch, or null when empty/unset — the
     * NULL-means-closed contract from WindowStates.
     */
    public function contestEpoch(string $key): ?int
    {
        $v = $this->contestStr($key);

        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (int) $v;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;

        return $v === null ? null : (string) $v;
    }
}
