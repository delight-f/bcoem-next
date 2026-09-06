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

    protected function tearDown(): void
    {
        if ($this->entryId !== null) {
            DB::table('judging_scores')->where('eid', $this->entryId)->delete();
            DB::table('judging_scores_bos')->where('eid', $this->entryId)->delete();
            DB::table('judging_flights')->where('flightEntryID', $this->entryId)->delete();
            DB::table('brewing')->where('id', $this->entryId)->delete();
        }
        if ($this->tableId !== null) {
            DB::table('judging_tables')->where('id', $this->tableId)->delete();
        }
        if ($this->brewerSeeded) {
            DB::table('brewer')->where('uid', 999999)->delete();
            DB::table('users')->where('id', 999999)->delete();
        }
        DB::table('preferences')->where('id', 1)->update([
            'prefsDisplayWinners' => 'N',
            'prefsWinnerDelay' => 0,
            'prefsWinnerMethod' => 0,
            'prefsShowBestBrewer' => 0,
            'prefsShowBestClub' => 0,
            'prefsScoringCOA' => 0,
        ]);
        parent::tearDown();
    }

    public function test_admin_sees_deck_with_all_slide_types(): void
    {
        $this->seedWinners();

        $response = $this->get('/awards');

        $response->assertOk();
        $html = $response->getContent();
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
        auth()->logout();
        $this->get('/awards')->assertRedirect('/?msg=7');
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
