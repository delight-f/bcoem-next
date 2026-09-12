<?php

declare(strict_types=1);

namespace App\Services\Installation;

/**
 * Version-keyed registry of data fixups, e.g. `for('3.0', '3.1')`.
 *
 * Each jump carries its own small classes so one version's fixup logic cannot
 * tangle with another's. Register in a service provider:
 * `UpgradeFixups::register('3.0', '3.1', BackfillCompetitionTimestamps::class);`
 */
final class UpgradeFixups
{
    /** @var array<string, list<class-string<UpgradeFixup>>> */
    private static array $registry = [];

    /**
     * @return list<UpgradeFixup>
     */
    public function for(string $from, string $to): array
    {
        $fixups = [];
        foreach (self::$registry[$from.'->'.$to] ?? [] as $class) {
            $fixups[] = new $class;
        }

        return $fixups;
    }

    /**
     * @param  class-string<UpgradeFixup>  $class
     */
    public static function register(string $from, string $to, string $class): void
    {
        self::$registry[$from.'->'.$to][] = $class;
    }
}
