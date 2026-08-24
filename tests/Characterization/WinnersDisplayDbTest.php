<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;

/**
 * Characterization: winners selection semantics against the baseline schema
 * (P1.7). The place filter is copied from includes/db/winners.db.php:43:
 *
 *   scorePlace IN ('1','2','3','4','5')   -- literal 'HM' is NOT included
 *
 * Combined with the scoring-ledger finding that both '5' and 'HM' mean
 * Honorable Mention, this pins a real trap: entries with 'HM' written by
 * newer flows vanish from winners pages in legacy.
 */
final class WinnersDisplayDbTest extends MySqlTestCase
{
    /** @var list<int> */
    private array $entries = [];

    /** @var list<int> */
    private array $scores = [];

    protected function tearDown(): void
    {
        foreach ($this->scores as $id) {
            self::db()->where('id', $id)->delete('judging_scores');
        }
        foreach ($this->entries as $id) {
            self::db()->where('id', $id)->delete('brewing');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        $base = [
            'brewName' => 'Winners Fixture',
            'brewCategorySort' => '15',
            'brewCategory' => '15',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '9004',
            'brewConfirmed' => '1',
        ];
        self::db()->insert('brewing', [...$base, ...$overrides]);
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->entries[] = $id;

        return $id;
    }

    private function makeScore(int $entryId, ?string $place): int
    {
        self::db()->insert('judging_scores', [
            'eid' => $entryId,
            'bid' => 9004,
            'scoreEntry' => 30,
            'scorePlace' => $place,
            'scoreTable' => 1,
        ]);
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->scores[] = $id;

        return $id;
    }

    public function test_winners_filter_includes_five_excludes_hm_literal(): void
    {
        // winners.db.php:43 verbatim filter.
        $filter = "(scorePlace='1' OR scorePlace='2' OR scorePlace='3' "
            ."OR scorePlace='4' OR scorePlace='5')";

        $places = ['1', '2', '3', '4', '5', 'HM', null, '6'];
        foreach ($places as $p) {
            $eid = $this->makeEntry(['brewJudgingNumber' => sprintf('%06d', count($this->entries) + 100)]);
            $this->makeScore($eid, $p);
        }

        $rows = self::db()->rawQuery(
            "SELECT scorePlace FROM judging_scores WHERE {$filter}",
        );
        $got = array_column($rows, 'scorePlace');
        sort($got);

        self::assertSame(['1', '2', '3', '4', '5'], $got);
    }
}
