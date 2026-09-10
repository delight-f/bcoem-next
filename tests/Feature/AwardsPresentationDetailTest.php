<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Awards presentation — winner methods 1/2, Best Brewer/Club slides,
 * slide-fidelity details (co-brewer, Pro edition, style sets,
 * special-best reveal semantics), and the scoring-methodology dialog.
 * Legacy refs: awards.php :241-440 (methods 1/2), :516-585 (special best),
 * :588-1141 (BB/BC), :1102-1133 (dialog).
 */
final class AwardsPresentationDetailTest extends AdminScreensTestCase
{
    /** @var array<string, list<int>> */
    private array $ids = [];

    /** @var array<string, mixed> */
    private array $prefBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefBackup = (array) DB::table('preferences')->where('id', 1)->first();
    }

    protected function tearDown(): void
    {
        foreach (['judging_scores', 'judging_scores_bos', 'judging_flights', 'brewing', 'special_best_data'] as $t) {
            if (isset($this->ids[$t])) {
                DB::table($t)->whereIn('id', $this->ids[$t])->delete();
            }
        }
        if (isset($this->ids['special_best_info'])) {
            DB::table('special_best_info')->whereIn('id', $this->ids['special_best_info'])->delete();
        }
        if (isset($this->ids['judging_tables'])) {
            DB::table('judging_tables')->whereIn('id', $this->ids['judging_tables'])->delete();
        }
        if (isset($this->ids['brewer'])) {
            DB::table('brewer')->whereIn('uid', $this->ids['brewer'])->delete();
            DB::table('users')->whereIn('id', $this->ids['brewer'])->delete();
        }

        DB::table('preferences')->where('id', 1)->update($this->prefBackup);
        parent::tearDown();
    }

    public function test_category_method_renders_category_slides(): void
    {
        $this->seedEntries(1);
        DB::table('preferences')->where('id', 1)->update(['prefsWinnerMethod' => 1, 'prefsStyleSet' => 'BJCP2025']);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('Category 21', $html);
        self::assertStringContainsString('Awrdbrewer Testerson', $html);
        // Category slide ignores ?go= (legacy does too).
        $this->get('/awards?go=table-entry-count-desc')->assertOk();
    }

    public function test_subcategory_method_renders_subcategory_slides(): void
    {
        $this->seedEntries(1);
        DB::table('preferences')->where('id', 1)->update(['prefsWinnerMethod' => 2, 'prefsStyleSet' => 'BJCP2025']);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('Category 21A', $html);
        self::assertStringContainsString('Awrdbrewer Testerson', $html);
    }

    public function test_co_brewer_and_style_display(): void
    {
        $this->seedEntries(1, coBrewer: 'J. Cobrewer');
        DB::table('preferences')->where('id', 1)->update(['prefsWinnerMethod' => 0, 'prefsStyleSet' => 'BJCP2025']);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('J. Cobrewer', $html);
        // BJCP2025 → cat.sub: style (legacy default branch).
        self::assertStringContainsString('21A', $html);
    }

    public function test_pro_edition_shows_brewery_name(): void
    {
        $this->seedEntries(1, brewery: 'Awrd Brewery LLC');
        DB::table('preferences')->where('id', 1)->update(['prefsWinnerMethod' => 0, 'prefsProEdition' => 1]);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('Awrd Brewery LLC', $html);
        self::assertStringNotContainsString('Awrdbrewer Testerson', $html);
    }

    public function test_special_best_sequential_reveal(): void
    {
        $this->seedSpecialBest(2);
        DB::table('preferences')->where('id', 1)->update(['prefsWinnerMethod' => 0]);

        $html = (string) $this->get('/awards')->getContent();

        // sbi_display_places=0 → sequential fragment indices via
        // place_heirarchy(running count): first row 5, second 4 (legacy
        // place_heirarchy inverts), and no explicit place label is shown.
        self::assertStringContainsString('data-fragment-index="5"', $html);
        self::assertStringContainsString('data-fragment-index="4"', $html);
        self::assertStringContainsString('AWRD Special Best', $html);
    }

    public function test_best_brewer_slide_renders_when_enabled(): void
    {
        $this->seedEntries(1);
        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestBrewer' => 1,
            'prefsScoringCOA' => 0,
            'prefsBestUseBOS' => 0,
            'prefsBestBrewerTitle' => 'Heavy Medal',
        ]);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('Heavy Medal', $html);
        self::assertStringContainsString('participating brewers', $html);
        self::assertStringContainsString('Scoring Methodology', $html);
        // The dialog renders only with BB/BC slides (legacy gate).
        self::assertStringContainsString('id="scoring-method"', $html);
    }

    public function test_best_club_slide_only_when_amateur(): void
    {
        $this->seedEntries(2, clubs: ['Test Club A', 'Test Club B']);
        DB::table('preferences')->where('id', 1)->update([
            'prefsShowBestClub' => 1,
            'prefsProEdition' => 0,
        ]);

        $html = (string) $this->get('/awards')->getContent();

        self::assertStringContainsString('Best Club', $html);
        self::assertStringContainsString('Test Club A', $html);
    }

    /**
     * Seed N placed entries (brewers 999999+i) that belong to category 21A.
     *
     * @param  list<string>  $clubs
     */
    private function seedEntries(int $n, string $coBrewer = '', string $brewery = '', array $clubs = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $uid = 999110 + $i;
            // Suffix walks A-Z (chr requires a 0-255 codepoint).
            $club = $clubs[$i] ?? ('Test Club '.chr(65 + $i % 26));
            DB::table('users')->insert([
                'id' => $uid,
                'user_name' => 'awrddetail'.(string) $i.'@x.com',
                'password' => password_hash('x', PASSWORD_BCRYPT),
                'userLevel' => 2,
            ]);
            DB::table('brewer')->insert([
                'uid' => $uid,
                'brewerFirstName' => 'Awrdbrewer',
                'brewerLastName' => 'Testerson',
                'brewerBreweryName' => $brewery !== '' ? $brewery : 'Awrd Brewery',
                'brewerClubs' => $club,
            ]);
            $this->ids['brewer'][] = $uid;

            $entryId = (int) DB::table('brewing')->insertGetId([
                'brewName' => 'AWRD Detail Brew '.($i + 1),
                'brewCategory' => '21',
                'brewCategorySort' => '21',
                'brewSubCategory' => 'A',
                'brewCoBrewer' => $coBrewer,
                'brewPaid' => 1,
                'brewReceived' => 1,
                'brewBrewerID' => $uid,
            ]);
            $this->ids['brewing'][] = $entryId;

            $tableId = (int) DB::table('judging_tables')->insertGetId([
                'tableName' => 'AWRD Table '.($i + 1),
                'tableNumber' => $i + 1,
                'tableStyles' => (string) $this->styleIdFor21A(),
            ]);
            $this->ids['judging_tables'][] = $tableId;

            DB::table('judging_flights')->insert([
                'flightTable' => $tableId,
                'flightNumber' => 1,
                'flightEntryID' => $entryId,
                'flightEntryOrder' => 1,
            ]);
            $this->ids['judging_flights'][] = (int) DB::table('judging_flights')->where('flightEntryID', $entryId)->value('id');

            DB::table('judging_scores')->insert([
                'eid' => $entryId,
                'scorePlace' => (string) ($i + 1),
                'scoreTable' => $tableId,
            ]);
            $this->ids['judging_scores'][] = (int) DB::table('judging_scores')->where('eid', $entryId)->value('id');
        }
    }

    private function styleIdFor21A(): int
    {
        return (int) (DB::table('styles')
            ->where('brewStyleGroup', '21')
            ->where('brewStyleNum', 'A')
            ->where('brewStyleActive', 'Y')
            ->value('id') ?? 0);
    }

    /** Seed special_best_info with 2 entries, no place display. */
    private function seedSpecialBest(int $n): void
    {
        $this->seedEntries($n);

        $sbiId = (int) DB::table('special_best_info')->insertGetId([
            'sbi_name' => 'AWRD Special Best',
            'sbi_display_places' => 0,
        ]);
        $this->ids['special_best_info'][] = $sbiId;

        foreach ($this->ids['brewing'] as $i => $entryId) {
            $dataId = (int) DB::table('special_best_data')->insertGetId([
                'sid' => $sbiId,
                'eid' => $entryId,
                'bid' => $this->ids['brewer'][$i],
                'sbd_place' => null,
            ]);
            $this->ids['special_best_data'][] = $dataId;
        }
    }
}
