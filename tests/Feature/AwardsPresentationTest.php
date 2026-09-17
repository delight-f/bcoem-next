<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Awards presentation (legacy awards.php, PARITY-001). Public gate:
 * judging past + all windows closed + prefsDisplayWinners=Y + delay
 * passed; admins (userLevel <= 1) always. 4 sorts × 3 themes.
 */
final class AwardsPresentationTest extends AdminScreensTestCase
{
    private ?int $tableId = null;

    private ?int $entryId = null;

    private bool $brewerSeeded = false;

    /** @var list<int> */
    private array $extraTableIds = [];

    /** @var list<int> */
    private array $extraEntryIds = [];

    /** @var list<int> */
    private array $extraBrewerUids = [];

    /** @var list<int> */
    private array $extraSponsorIds = [];

    protected function tearDown(): void
    {
        if ($this->entryId !== null) {
            DB::table('judging_scores')->where('eid', $this->entryId)->delete();
            DB::table('judging_scores_bos')->where('eid', $this->entryId)->delete();
            DB::table('judging_flights')->where('flightEntryID', $this->entryId)->delete();
            DB::table('brewing')->where('id', $this->entryId)->delete();
        }
        if ($this->extraEntryIds !== []) {
            DB::table('judging_scores')->whereIn('eid', $this->extraEntryIds)->delete();
            DB::table('judging_flights')->whereIn('flightEntryID', $this->extraEntryIds)->delete();
            DB::table('brewing')->whereIn('id', $this->extraEntryIds)->delete();
        }
        if ($this->tableId !== null) {
            DB::table('judging_tables')->where('id', $this->tableId)->delete();
        }
        if ($this->extraTableIds !== []) {
            DB::table('judging_tables')->whereIn('id', $this->extraTableIds)->delete();
        }
        if ($this->brewerSeeded) {
            DB::table('brewer')->where('uid', 999999)->delete();
            DB::table('users')->where('id', 999999)->delete();
        }
        if ($this->extraBrewerUids !== []) {
            DB::table('brewer')->whereIn('uid', $this->extraBrewerUids)->delete();
            DB::table('users')->whereIn('id', $this->extraBrewerUids)->delete();
        }
        if ($this->extraSponsorIds !== []) {
            DB::table('sponsors')->whereIn('id', $this->extraSponsorIds)->delete();
        }
        DB::table('preferences')->where('id', 1)->update([
            'prefsDisplayWinners' => 'N',
            'prefsWinnerDelay' => 0,
            'prefsWinnerMethod' => 0,
            'prefsShowBestBrewer' => 0,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 0,
            'prefsSponsorLogos' => 'N',
        ]);
        parent::tearDown();
    }

    public function test_admin_sees_deck_with_all_slide_types(): void
    {
        $this->seedWinners();

        $response = $this->get('/awards');

        $response->assertOk();
        $html = $response->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('reveal', $html);
        self::assertStringContainsString('vendor/reveal/theme/white.css', $html);
        self::assertStringContainsString('AWRD Test Table', $html);
        self::assertStringContainsString('Best of Show', $html);
        self::assertStringContainsString('Thank You', $html);
        self::assertStringNotContainsString('Thank You!', $html);
        self::assertStringContainsString('By the Numbers', $html);
        self::assertStringNotContainsString('By The Numbers', $html);
        self::assertStringContainsString('Congratulations to All Medal Winners', $html);
        self::assertStringContainsString('Awrdbrewer Testerson', $html);
    }

    public function test_deck_matches_legacy_text_constants(): void
    {
        $this->seedWinners();

        $html = (string) $this->get('/awards')->getContent();
        // Hidden scoring-methodology modal (awards.php:1102-1133).
        self::assertStringContainsString('Scoring Methodology', $html);
        self::assertStringContainsString('Each placing entry is given the following points:', $html);
        self::assertStringContainsString('1st Place:', $html);
        self::assertStringContainsString('2nd Place:', $html);
        self::assertStringContainsString('3rd Place:', $html);
    }

    public function test_theme_and_sort_params(): void
    {
        $this->seedWinners();

        $this->get('/awards?view=black')->assertOk()
            ->assertSee('vendor/reveal/theme/black.css', false);
        $this->get('/awards?view=blue')->assertOk()
            ->assertSee('vendor/reveal/theme/moon.css', false);
        foreach (['table-entry-count-desc', 'table-name-only', 'table-entry-count-asc'] as $go) {
            $this->get('/awards?go='.$go)->assertOk()
                ->assertSee('AWRD Test Table', false);
        }
    }

    public function test_anon_bounced_when_unpublished(): void
    {
        // The baseline fixture ships as a finished competition with results
        // already published (prefsDisplayWinners='Y', winner delay passed, all
        // windows closed, no future judging sessions), so the public deck is
        // live by design. Pin the unpublished branch explicitly instead of
        // depending on whatever ambient state earlier tests left behind.
        DB::table('preferences')->where('id', 1)->update(['prefsDisplayWinners' => 'N']);

        auth()->logout();
        $this->get('/awards')->assertRedirect('/?msg=7');
    }

    /**
     * go=table-numbers orders by the numeric table number, not the name:
     * "Table 2: Zzz Table" must precede "Table 10: Aaa Table", which the
     * old titleLong (name) compare got backwards.
     */
    public function test_table_number_sort_is_numeric(): void
    {
        $this->seedPlacedEntry(2, 'Zzz Table', 999991, 'Zed', '1');
        $this->seedPlacedEntry(10, 'Aaa Table', 999992, 'Abe', '1');

        $html = (string) $this->get('/awards?go=table-numbers')->getContent();

        $pos2 = strpos($html, 'Table 2: Zzz Table');
        $pos10 = strpos($html, 'Table 10: Aaa Table');
        self::assertNotFalse($pos2);
        self::assertNotFalse($pos10);
        self::assertLessThan($pos10, $pos2);
    }

    /**
     * Sponsor logos live in public/user_images (served via asset), not the
     * /storage symlink; rows whose file is missing are dropped.
     */
    public function test_sponsor_slide_uses_user_images_and_skips_missing(): void
    {
        $existing = (int) DB::table('sponsors')->insertGetId([
            'sponsorName' => 'AWRD Sponsor OK',
            'sponsorImage' => 'sample_logo.png',
            'sponsorEnable' => 1,
        ]);
        $this->extraSponsorIds[] = $existing;

        $missing = (int) DB::table('sponsors')->insertGetId([
            'sponsorName' => 'AWRD Sponsor Missing',
            'sponsorImage' => 'awrd_missing_logo_xyz.png',
            'sponsorEnable' => 1,
        ]);
        $this->extraSponsorIds[] = $missing;

        DB::table('preferences')->where('id', 1)->update(['prefsSponsorLogos' => 'Y']);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString(asset('user_images/sample_logo.png'), $html);
        self::assertStringNotContainsString('/storage/user_images/', $html);
        self::assertStringNotContainsString('awrd_missing_logo_xyz.png', $html);
    }

    /**
     * A table with no placings is dead air: absent by default, restored by
     * the ?empty=1 escape hatch.
     */
    public function test_empty_winner_slides_skipped_by_default(): void
    {
        $this->seedPlacedEntry(500, 'AWRD Placed Table', 999993, 'Placed', '1');
        $this->seedEmptyTable(501, 'AWRD Empty Table');

        $def = (string) $this->get('/awards')->getContent();
        self::assertStringNotContainsString('AWRD Empty Table', $def);

        $legacy = (string) $this->get('/awards?empty=1')->getContent();
        self::assertStringContainsString('AWRD Empty Table', $legacy);
    }

    /**
     * Guards against re-introducing the tables x styles N+1: the deck is
     * preloaded once per request, so adding tables (each listing several
     * styles) must not add queries. Also pins the absolute deck cost under
     * a small ceiling.
     */
    public function test_awards_deck_query_count_does_not_scale_with_tables(): void
    {
        $this->seedWinners();

        $baseline = $this->countAwardsQueries();

        $styleIds = DB::table('styles')->where('brewStyleActive', 'Y')->limit(3)->pluck('id')
            ->map(static fn ($v): int => (int) $v)->all();
        for ($i = 0; $i < 6; $i++) {
            $this->seedTableWithStyles(600 + $i, 'AWRD Scale Table '.$i, $styleIds);
        }

        $withTables = $this->countAwardsQueries();

        self::assertLessThan(60, $baseline, "deck baseline was {$baseline} queries");
        self::assertLessThanOrEqual(
            $baseline + 2,
            $withTables,
            "adding 6 tables grew the deck from {$baseline} to {$withTables} queries",
        );
    }

    private function countAwardsQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get('/awards')->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * One placed entry on its own table (extra fixture, torn down after).
     */
    private function seedPlacedEntry(int $number, string $tableName, int $uid, string $lastName, string $place): void
    {
        DB::table('users')->insert([
            'id' => $uid,
            'user_name' => 'awrd'.$uid.'@x.com',
            'password' => password_hash('x', PASSWORD_BCRYPT),
            'userLevel' => 2,
        ]);
        DB::table('brewer')->insert([
            'uid' => $uid,
            'brewerFirstName' => 'Awrdbrewer',
            'brewerLastName' => $lastName,
        ]);
        $this->extraBrewerUids[] = $uid;

        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => $tableName,
            'tableNumber' => $number,
        ]);
        $this->extraTableIds[] = $tableId;

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'AWRD '.$tableName.' Brew',
            'brewCategory' => '21',
            'brewCategorySort' => '21',
            'brewSubCategory' => 'A',
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewBrewerID' => $uid,
        ]);
        $this->extraEntryIds[] = $entryId;

        DB::table('judging_flights')->insert([
            'flightTable' => $tableId,
            'flightNumber' => 1,
            'flightEntryID' => $entryId,
            'flightEntryOrder' => 1,
        ]);
        DB::table('judging_scores')->insert([
            'eid' => $entryId,
            'scorePlace' => $place,
            'scoreTable' => $tableId,
        ]);
    }

    private function seedEmptyTable(int $number, string $tableName): void
    {
        $this->extraTableIds[] = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => $tableName,
            'tableNumber' => $number,
        ]);
    }

    /**
     * A table listing styles but with no placings (query-count fixture).
     *
     * @param  list<int>  $styleIds
     */
    private function seedTableWithStyles(int $number, string $tableName, array $styleIds): void
    {
        $this->extraTableIds[] = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => $tableName,
            'tableNumber' => $number,
            'tableStyles' => implode(',', $styleIds),
        ]);
    }

    /**
     * One table carrying a received, paid entry with a 1st-place score and
     * a 1st-place BOS row in a real BOS-enabled style type.
     */
    private function seedWinners(): void
    {
        DB::table('users')->insert([
            'id' => 999999,
            'user_name' => 'awrd@x.com',
            'password' => password_hash('x', PASSWORD_BCRYPT),
            'userLevel' => 2,
        ]);
        DB::table('brewer')->insert([
            'uid' => 999999,
            'brewerFirstName' => 'Awrdbrewer',
            'brewerLastName' => 'Testerson',
        ]);
        $this->brewerSeeded = true;

        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => 1,
            'prefsScoringCOA' => 0,
            'prefsBestUseBOS' => 0,
        ]);

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'AWRD Test Table',
            'tableNumber' => 99,
        ]);
        $this->entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'AWRD Test Brew',
            'brewCategory' => '21',
            'brewCategorySort' => '21',
            'brewSubCategory' => 'A',
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewBrewerID' => 999999,
        ]);
        DB::table('judging_flights')->insert([
            'flightTable' => $this->tableId,
            'flightNumber' => 1,
            'flightEntryID' => $this->entryId,
            'flightEntryOrder' => 1,
        ]);
        DB::table('judging_scores')->insert([
            'eid' => $this->entryId,
            'scorePlace' => '1',
            'scoreTable' => $this->tableId,
        ]);
        $bosType = (int) (DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->value('id') ?? 2);
        DB::table('judging_scores_bos')->insert([
            'eid' => $this->entryId,
            'scorePlace' => '1',
            'scoreType' => $bosType,
        ]);
    }
}
