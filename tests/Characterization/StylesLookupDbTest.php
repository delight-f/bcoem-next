<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Characterization: style-set lookup semantics executed VERBATIM against the
 * baseline schema (P1.8). The WHERE clauses below are copied from legacy
 * lib/common.lib.php (style_convert :1457-1467, style-info lookups
 * :1870-1879) and run via raw SQL so the test pins actual predicate
 * behavior rather than a reimplementation.
 *
 * Rules pinned:
 *   1. Generic set lookup matches (brewStyleVersion = ? OR brewStyleOwn =
 *      'custom') — custom rows extend every stock set.
 *   2. BJCP2025 set picks version by first character of the posted group:
 *      "C" → BJCP2025, else BJCP2021.
 *   3. AABC2025 set is dual-version: (AABC2025 AND brewStyleType='2') OR
 *      (AABC2022 AND brewStyleType != '2').
 */
final class StylesLookupDbTest extends MySqlTestCase
{
    /** @var list<int> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            DB::table('styles')->where('id', $id)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeStyle(array $overrides = []): int
    {
        $base = [
            'brewStyleGroup' => '28',
            'brewStyleNum' => 'Z',
            'brewStyle' => 'Fixture Style',
            'brewStyleVersion' => 'BJCP2021',
            'brewStyleType' => '1',
            'brewStyleOwn' => 'stock',
        ];
        $id = DB::table('styles')->insertGetId([...$base, ...$overrides]);
        if (! is_int($id)) {
            self::fail('styles insert failed');
        }
        $this->created[] = $id;

        return $id;
    }

    /**
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        $rows = DB::select($sql, $params);
        self::assertIsArray($rows);

        return array_values(array_map(
            fn ($row): array => (array) $row,
            $rows,
        ));
    }

    public function test_generic_set_matches_version_or_custom(): void
    {
        // common.lib.php:1879 shape. Custom rows match ANY version filter;
        // stock rows only match their own version.
        $this->makeStyle();                                                     // stock BJCP2021 28-Z
        $this->makeStyle(['brewStyleGroup' => '99', 'brewStyleNum' => 'A',
            'brewStyleVersion' => 'SOMETHINGELSE', 'brewStyleOwn' => 'custom']);

        $customOnly = $this->query(
            'SELECT id FROM styles WHERE brewStyleGroup = ? AND brewStyleNum = ? '
            ."AND (brewStyleVersion = ? OR brewStyleOwn = 'custom')",
            ['99', 'A', 'BJCP2021'],
        );
        self::assertCount(1, $customOnly);

        $stockMatch = $this->query(
            'SELECT id FROM styles WHERE brewStyleGroup = ? AND brewStyleNum = ? '
            ."AND (brewStyleVersion = ? OR brewStyleOwn = 'custom')",
            ['28', 'Z', 'BJCP2021'],
        );
        self::assertCount(1, $stockMatch);

        $wrongVersion = $this->query(
            'SELECT id FROM styles WHERE brewStyleGroup = ? AND brewStyleNum = ? '
            ."AND brewStyleVersion = ? AND brewStyleOwn != 'custom'",
            ['28', 'Z', 'AABC2022'],
        );
        self::assertCount(0, $wrongVersion);
    }

    public function test_bjcp_versions_are_distinct_rows(): void
    {
        // style_convert (:1457-1459): under prefsStyleSet BJCP2025 the group's
        // first character "C" selects version BJCP2025; everything else
        // selects BJCP2021. The two versions coexist as separate rows.
        $this->makeStyle(['brewStyleVersion' => 'BJCP2025']);
        $this->makeStyle();

        self::assertCount(1, $this->query(
            "SELECT id FROM styles WHERE brewStyleGroup = '28' AND brewStyleNum = 'Z' AND brewStyleVersion = 'BJCP2025'"));
        self::assertCount(1, $this->query(
            "SELECT id FROM styles WHERE brewStyleGroup = '28' AND brewStyleNum = 'Z' AND brewStyleVersion = 'BJCP2021'"));
    }

    public function test_aabc2025_dual_version_predicate(): void
    {
        // common.lib.php:1875 shape — AABC2025 rows match only when
        // brewStyleType='2'; AABC2022 rows match only when type != '2'.
        $this->makeStyle(['brewStyleNum' => 'P', 'brewStyleVersion' => 'AABC2025', 'brewStyleType' => '2']);
        $this->makeStyle(['brewStyleNum' => 'Q', 'brewStyleVersion' => 'AABC2025', 'brewStyleType' => '1']);
        $this->makeStyle(['brewStyleNum' => 'R', 'brewStyleVersion' => 'AABC2022', 'brewStyleType' => '1']);
        $this->makeStyle(['brewStyleNum' => 'S', 'brewStyleVersion' => 'AABC2022', 'brewStyleType' => '2']);

        $match = fn (string $num) => count($this->query(
            "SELECT id FROM styles WHERE brewStyleGroup = '28' AND brewStyleNum = ? AND "
            ."((brewStyleVersion='AABC2025' AND brewStyleType='2') OR (brewStyleVersion='AABC2022' AND brewStyleType !='2'))",
            [$num],
        ));

        self::assertSame(1, $match('P')); // 2025 + type 2 -> matches
        self::assertSame(0, $match('Q')); // 2025 + other type -> excluded
        self::assertSame(1, $match('R')); // 2022 + non-2 type -> matches
        self::assertSame(0, $match('S')); // 2022 + type 2 -> excluded
    }
}
