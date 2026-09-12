<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Brewer\Clubs;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The admin pickers must offer the right options.
 *
 * Two sources feed the club list: `contestClubs` (the list the
 * competition-info page itself maintains) and the clubs already stored on
 * brewer rows. The admin search previously read only the latter, so a club
 * the organizer had just added was invisible to their own search box.
 */
final class AdminPickerSourcesTest extends AdminScreensTestCase
{
    /** @var list<int> */
    private array $brewerUids = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The base class snapshots and restores this row for every test.
        $this->remember('contest_info');
    }

    protected function tearDown(): void
    {
        if ($this->brewerUids !== []) {
            DB::table('brewer')->whereIn('uid', $this->brewerUids)->delete();
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function clubsFromPage(): array
    {
        $html = (string) $this->get('/admin/competition-info')->assertOk()->getContent();

        $matched = preg_match('/var bcoem_clubs = (\[.*?\]);/s', $html, $m);
        self::assertSame(1, $matched, 'the club search source must be embedded on the page');

        /** @var list<string> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function test_club_sources_merge_both_contest_list_and_brewer_rows(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestClubs' => json_encode(['Alpha Club'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('brewer')->insert([
            'uid' => 991001,
            'brewerFirstName' => 'Picker',
            'brewerLastName' => 'Source',
            'brewerClubs' => 'Beta Club',
        ]);
        $this->brewerUids[] = 991001;

        $names = Clubs::all(TenantContext::load());

        self::assertContains('Alpha Club', $names, 'contestClubs is a club source');
        self::assertContains('Beta Club', $names, 'brewer rows are a club source');
    }

    public function test_club_names_keep_casing_and_dedupe_case_insensitively(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestClubs' => json_encode(['Foo Brewers'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('brewer')->insert([
            'uid' => 991002,
            'brewerFirstName' => 'Picker',
            'brewerLastName' => 'Case',
            'brewerClubs' => 'foo brewers',
        ]);
        $this->brewerUids[] = 991002;

        $names = Clubs::all(TenantContext::load());

        $matches = array_values(array_filter($names, static fn (string $n): bool => strtolower($n) === 'foo brewers'));
        self::assertCount(1, $matches, 'the same club must not appear twice');
        self::assertSame('Foo Brewers', $matches[0], 'the first spelling seen is kept');

        // known() stays the folded form used for validation comparisons.
        self::assertContains('foo brewers', Clubs::known(TenantContext::load()));
    }

    public function test_competition_info_search_offers_clubs_saved_on_the_contest(): void
    {
        // Regression: a club the organizer added on this very page has to be
        // searchable here, not only after an entrant has stored it.
        DB::table('contest_info')->where('id', 1)->update([
            'contestClubs' => json_encode(['Recent Club Added By Organizer'], JSON_THROW_ON_ERROR),
        ]);

        self::assertContains('Recent Club Added By Organizer', $this->clubsFromPage());
    }

    public function test_table_form_groups_styles_by_category_with_bulk_controls(): void
    {
        // A style set holds well over a hundred entries; the picker groups
        // them and offers per-group selection instead of a flat list.
        $html = (string) $this->get('/admin/judging/tables/create')->assertOk()->getContent();

        self::assertStringContainsString('bcoem-style-group', $html);
        self::assertStringContainsString('style-filter', $html);
        self::assertStringContainsString('styles-select-shown', $html);
        self::assertGreaterThan(
            1,
            substr_count($html, '<legend'),
            'styles must render in more than one group',
        );
    }

    /**
     * Issue #22: the mirrored central clubs list is a third picker source.
     */
    public function test_synced_central_clubs_feed_the_picker(): void
    {
        $now = now();

        DB::table('clubs')->insert([
            'name' => 'Central Picker Club',
            'name_normalized' => 'central picker club',
            'source' => 'upstream',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            self::assertContains('Central Picker Club', Clubs::all(TenantContext::load()));
            self::assertContains('Central Picker Club', $this->clubsFromPage());
        } finally {
            DB::table('clubs')->where('name', 'Central Picker Club')->delete();
        }
    }

    /**
     * Issue #22: when a synced club collides case-insensitively with a local
     * one, the local spelling is kept (same precedence the sync documents).
     */
    public function test_local_casing_wins_over_a_synced_duplicate(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestClubs' => json_encode(['Shared Casing Club'], JSON_THROW_ON_ERROR),
        ]);

        $now = now();
        DB::table('clubs')->insert([
            'name' => 'shared casing club',
            'name_normalized' => 'shared casing club',
            'source' => 'upstream',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $matches = array_values(array_filter(
                Clubs::all(TenantContext::load()),
                static fn (string $name): bool => strtolower($name) === 'shared casing club',
            ));

            self::assertCount(1, $matches, 'the club must not appear twice');
            self::assertSame('Shared Casing Club', $matches[0], 'the pre-existing local spelling is kept');
        } finally {
            DB::table('clubs')->where('name', 'shared casing club')->delete();
        }
    }
}
