<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No shipped style catalog may list the same lookup code twice.
 *
 * StyleSets::findStyle() resolves an entry's style on group + number + version
 * with no ORDER BY, and BrewController writes the resolved row's name onto the
 * entry's own brewStyle column, so a code shared by two styles silently stores
 * and prints one as the other. The baseline dump shipped exactly that: "New
 * Zealand-Style India Pale Ale" sat on '182' beside "New Zealand-Style Pale
 * Ale" until upstream moved it to '183'
 * (brewcompetitiononlineentry 25686a8). Every install seeded from the dump
 * inherited the collision, and the BA2026 seed is the same kind of catalog.
 *
 * Both are read as data here: the catalogs are wrong on disk long before any
 * database is involved, so the check needs none.
 */
final class BaselineStyleCatalogTest extends TestCase
{
    /** Codes per row, as version|group|num => style name. */
    public function test_shipped_style_catalogs_give_every_style_its_own_code(): void
    {
        $catalogs = [
            'sql/bcoem_baseline_3.0.X.sql' => self::codesFromBaselineDump(),
            'database/migrations/2026_09_11_000000_seed_ba2026_styles.php' => self::codesFromBa2026Seed(),
        ];

        foreach ($catalogs as $source => $codes) {
            self::assertGreaterThan(100, count($codes), $source.' yielded no styles to check');

            $seen = [];
            foreach ($codes as [$code, $name]) {
                $seen[$code][] = $name;
            }

            self::assertSame(
                [],
                array_filter($seen, fn (array $names): bool => count($names) > 1),
                $source.' gives two styles the same lookup code',
            );
        }
    }

    /**
     * The rows upstream's fix moved, pinned by name so a future reshuffle of
     * the BA catalog cannot quietly restore the collision.
     */
    public function test_new_zealand_styles_hold_their_own_codes(): void
    {
        $byCode = [];
        foreach (self::codesFromBaselineDump() as [$code, $name]) {
            $byCode[$code] = $name;
        }

        self::assertSame('New Zealand-Style Pale Ale', $byCode['BA|06|182'] ?? null);
        self::assertSame('New Zealand-Style India Pale Ale', $byCode['BA|06|183'] ?? null);
    }

    /**
     * `INSERT INTO baseline_styles` values, read straight from the dump. Each
     * tuple begins at column 0 with the id, and the six fields needed here all
     * precede the free-text style description.
     *
     * @return list<array{string, string}>
     */
    private static function codesFromBaselineDump(): array
    {
        $root = dirname(__DIR__, 2);
        $sql = (string) file_get_contents($root.'/sql/bcoem_baseline_3.0.X.sql');

        $codes = [];
        foreach (preg_split('/;\R/', $sql) ?: [] as $statement) {
            if (! str_starts_with(ltrim($statement), 'INSERT INTO `baseline_styles`')) {
                continue;
            }

            preg_match_all(
                "/^\((\d+), '([^']*)', '([^']*)', '((?:[^']|'')*)', '(?:[^']|'')*', '([^']*)'/m",
                $statement,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $row) {
                $codes[] = [$row[5].'|'.$row[2].'|'.$row[3], $row[4]];
            }
        }

        return $codes;
    }

    /**
     * The BA2026 seed rows, whose five leading keys are group, number, name,
     * category and version in that order.
     *
     * @return list<array{string, string}>
     */
    private static function codesFromBa2026Seed(): array
    {
        $root = dirname(__DIR__, 2);
        $php = (string) file_get_contents($root.'/database/migrations/2026_09_11_000000_seed_ba2026_styles.php');

        preg_match_all(
            "/'brewStyleGroup' => '([^']*)', 'brewStyleNum' => '([^']*)', 'brewStyle' => '([^']*)'"
            .", 'brewStyleCategory' => '[^']*', 'brewStyleVersion' => '([^']*)'/",
            $php,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $row): array => [$row[4].'|'.$row[1].'|'.$row[2], $row[3]], $matches);
    }
}
