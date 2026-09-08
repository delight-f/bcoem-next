<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;

/**
 * Characterization: registration cap COUNT semantics against the baseline
 * schema (P1.2). The queries below mirror the legacy MysqliDb where-chains:
 *
 *   - per-user entry cap: COUNT(*) WHERE brewBrewerID = <uid>  — counts
 *     UNCONFIRMED and UNPAID entries too (process_brewing.inc.php:46-49)
 *   - subcategory limit count: WHERE brewBrewerID AND brewCategorySort AND
 *     brewSubCategory (common.lib.php:3664-3668) — exact equality on the
 *     padded sort column, so decoys in other categories don't leak in
 */
final class RegistrationRulesDbTest extends MySqlTestCase
{
    /** @var list<int> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            self::db()->where('id', $id)->delete('brewing');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        $base = [
            'brewName' => 'Reg Rules Fixture',
            'brewCategory' => '15',
            'brewCategorySort' => '15',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '9001',
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ];
        self::db()->insert('brewing', [...$base, ...$overrides]);
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->created[] = $id;

        return $id;
    }

    /**
     * @param  list<mixed>  $params
     */
    private function countFor(string $sql, array $params): int
    {
        $row = self::db()->rawQueryOne($sql, $params);
        self::assertIsArray($row);

        return (int) $row['count'];
    }

    public function test_user_cap_counts_unconfirmed_and_unpaid_entries(): void
    {
        // Legacy cap query is a bare COUNT(*) — no confirmed/paid filter.
        $this->makeEntry(['brewConfirmed' => '1', 'brewPaid' => 1]);
        $this->makeEntry(['brewConfirmed' => '0', 'brewPaid' => 0]); // abandoned draft
        $this->makeEntry(['brewSubCategory' => 'B']);                // different subcat, same user

        $count = $this->countFor(
            'SELECT COUNT(*) AS count FROM brewing WHERE brewBrewerID = ?',
            ['9001'],
        );

        self::assertSame(3, $count); // drafts consume cap exactly like legacy
    }

    public function test_subcategory_count_is_sort_plus_sub_exact(): void
    {
        $this->makeEntry();                                              // 15-A target
        $this->makeEntry(['brewSubCategory' => 'B']);                    // same cat, other sub
        $this->makeEntry(['brewCategory' => '15', 'brewCategorySort' => '015', 'brewSubCategory' => 'A']); // width variant

        $count = $this->countFor(
            'SELECT COUNT(*) AS count FROM brewing '
            .'WHERE brewBrewerID = ? AND brewCategorySort = ? AND brewSubCategory = ?',
            ['9001', '15', 'A'],
        );

        self::assertSame(1, $count);
    }

    public function test_ba_style_set_counts_by_subcategory_only(): void
    {
        // common.lib.php:3657-3662: under prefsStyleSet BA the count drops
        // the category filter entirely (subcategories are globally unique).
        $this->makeEntry(['brewCategory' => '10', 'brewCategorySort' => '10']);
        $this->makeEntry(['brewCategory' => '77', 'brewCategorySort' => '77']);

        $count = $this->countFor(
            'SELECT COUNT(*) AS count FROM brewing WHERE brewBrewerID = ? AND brewSubCategory = ?',
            ['9001', 'A'],
        );

        self::assertSame(2, $count);
    }
}
