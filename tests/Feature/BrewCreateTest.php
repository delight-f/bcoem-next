<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\BrewController;
use App\Support\Payments\FeeCalculator;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Entry creation (P3.3a): GET/POST /brew ports brew.pub.php (add mode) +
 * process_brewing.inc.php's add branch. Row defaults follow entry-lifecycle
 * ledger #1/#2 (brewConfirmed='1', free comp forces paid), judging numbers
 * follow #5 (six digits 1–9, app-level uniqueness, no DB constraint — #9),
 * and caps gate creation with the legacy msg codes (registration-rules #2:
 * msg=8 user cap, msg=9 subcat cap).
 */
final class BrewCreateTest extends PublicSurfaceTestCase
{
    private const LOGIN = 'user.baseline@brewingcompetitions.com';

    /** @var array<string, mixed> */
    private array $origContest = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    private ?string $origDiscount = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => self::LOGIN,
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '2',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Entrant',
                'brewerEmail' => self::LOGIN,
            ]);
        } else {
            DB::table('users')->where('id', 1)->update([
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '2',
            ]);
        }
        $row = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $row === null ? [] : (array) $row;

        $discount = DB::table('brewer')->where('uid', 1)->value('brewerDiscount');
        $this->origDiscount = $discount === null ? null : (string) $discount;

        // Open the entry window: the brew form (brew.pub.php add mode) only
        // renders between the entry-open and deadline timestamps, and the
        // baseline has it closed. Pin the window open for form-listing tests.
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewBrewerID', 1)->delete();

        DB::table('brewer')->where('uid', 1)->update(['brewerDiscount' => $this->origDiscount]);

        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', [
            'loginUsername' => self::LOGIN,
            'loginPassword' => 'bcoem',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function postEntry(array $overrides = []): TestResponse
    {
        return $this->post('/brew', array_merge([
            'brewName' => 'My Pale Ale',
            'brewStyle' => '1-A',
            'brewCoBrewer' => '',
            'brewInfo' => '',
            'brewComments' => '',
            'brewConfirmed' => '1',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastEntry(): ?array
    {
        $row = DB::table('brewing')->where('brewBrewerID', 1)->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function setPrefs(array $values): void
    {
        $row = (array) DB::table('preferences')->where('id', 1)->first();

        if ($this->origPrefs === []) {
            $this->origPrefs = collect($row)->except(['id'])->all();
        }

        DB::table('preferences')->where('id', 1)->update($values);
    }

    public function test_create_inserts_row_with_legacy_defaults_and_style_fields(): void
    {
        $this->login();
        $this->postEntry(['brewABV' => '6.5', 'brewComments' => 'hoppy']);

        $response = $this->post('/brew', [
            'brewName' => 'Second Run',
            'brewStyle' => '1-B',
            'brewConfirmed' => '1',
        ]);
        $response->assertRedirect('/list?msg=1');

        $entry = $this->lastEntry();
        self::assertNotNull($entry);
        self::assertSame('Second Run', $entry['brewName']);
        self::assertSame('American Lager', $entry['brewStyle']);
        self::assertSame('1', $entry['brewCategory']);
        self::assertSame('01', $entry['brewCategorySort']);
        self::assertSame('B', $entry['brewSubCategory']);
        self::assertSame(1, (int) $entry['brewStyleType']);
        self::assertSame('1', (string) $entry['brewBrewerID']);
        $brewer = (array) DB::table('brewer')->where('uid', 1)->first();
        self::assertSame($brewer['brewerFirstName'], $entry['brewBrewerFirstName']);
        self::assertSame($brewer['brewerLastName'], $entry['brewBrewerLastName']);
        // Entry-lifecycle ledger #1 creation defaults.
        self::assertSame('1', (string) $entry['brewConfirmed']);
        self::assertSame(0, (int) $entry['brewPaid']);
        self::assertSame(0, (int) $entry['brewReceived']);
        self::assertNotEmpty($entry['brewUpdated']);
        // Ledger #9: no DB unique constraint — app-level allocation only.
        self::assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $entry['brewJudgingNumber']);
        // Empty text fields stored as NULL (blank_to_null parity).
        self::assertNull($entry['brewCoBrewer']);
        self::assertNull($entry['brewInfo']);
    }

    public function test_judging_numbers_are_unique_across_entries(): void
    {
        $this->login();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/brew', ['brewName' => 'Batch '.$i, 'brewStyle' => '1-A', 'brewConfirmed' => '1']);
        }

        $numbers = DB::table('brewing')->where('brewBrewerID', 1)->pluck('brewJudgingNumber');
        self::assertCount(3, $numbers);
        self::assertCount(3, array_unique($numbers->all()));
        foreach ($numbers as $number) {
            self::assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $number);
        }
    }

    public function test_zero_fee_competition_forces_brew_paid(): void
    {
        $row = (array) DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = collect($row)->except(['id'])->all();
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => 0]);

        $this->login();
        $this->postEntry();

        $entry = $this->lastEntry();
        self::assertNotNull($entry);
        self::assertSame(1, (int) $entry['brewPaid']);
    }

    public function test_user_cap_rejects_with_legacy_msg_8(): void
    {
        $this->setPrefs(['prefsUserEntryLimit' => '1']);

        $this->login();
        $this->postEntry();
        self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());

        $this->post('/brew', ['brewName' => 'Over Cap', 'brewStyle' => '1-A', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=8');

        // Rejected rows are not inserted; the cap counts ALL rows (#1).
        self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    public function test_subcategory_cap_rejects_with_legacy_msg_9(): void
    {
        $this->setPrefs(['prefsUserSubCatLimit' => '2']);

        $this->login();
        $this->postEntry(['brewStyle' => '1-A']);
        $this->postEntry(['brewStyle' => '1-A']);

        $this->post('/brew', ['brewName' => 'Third A', 'brewStyle' => '1-A', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=9');

        // Same subcategory blocked...
        self::assertSame(2, DB::table('brewing')->where('brewBrewerID', 1)->count());
        // ...but a different subcategory of the same category still allowed.
        $this->post('/brew', ['brewName' => 'First B', 'brewStyle' => '1-B', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=1');
        self::assertSame(3, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    /**
     * Entries-tab "Enable By Style" grid: prefsStyleLimits JSON keyed by
     * medal group. A second entry in a full group is rejected with msg=12.
     */
    public function test_style_group_limit_rejects_with_msg_12(): void
    {
        $this->clearCaps();
        $this->setPrefs(['prefsStyleLimits' => json_encode(['01' => '1'])]);

        $this->login();
        $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

        $this->post('/brew', ['brewName' => 'Group Full', 'brewStyle' => '1-B', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=12');

        self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    /**
     * Per-style-type limits (style_types.styleTypeEntryLimit) applied to the
     * resolved style's brewStyleType.
     */
    public function test_style_type_limit_rejects_with_msg_12(): void
    {
        $style = BrewController::styleFlags('1-A', TenantContext::load());
        self::assertNotNull($style);
        $typeId = (int) $style->brewStyleType;
        $original = DB::table('style_types')->where('id', $typeId)->value('styleTypeEntryLimit');
        DB::table('style_types')->where('id', $typeId)->update(['styleTypeEntryLimit' => '1']);

        $this->clearCaps();

        try {
            $this->login();
            $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

            $this->post('/brew', ['brewName' => 'Type Full', 'brewStyle' => '1-B', 'brewConfirmed' => '1'])
                ->assertRedirect('/list?msg=12');

            self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());
        } finally {
            DB::table('style_types')->where('id', $typeId)->update(['styleTypeEntryLimit' => $original]);
        }
    }

    /**
     * Entries-tab "Enable By Table or Medal Group": prefsStyleLimits='2' with
     * judging_tables.tableEntryLimit counting the participant's entries across
     * the table's styles.
     */
    public function test_per_table_limit_rejects_with_msg_12(): void
    {
        $style = BrewController::styleFlags('1-A', TenantContext::load());
        self::assertNotNull($style);

        $tableId = DB::table('judging_tables')->insertGetId([
            'tableName' => 'P2 Test Table',
            'tableStyles' => (string) $style->id,
            'tableNumber' => 1,
            'tableEntryLimit' => 1,
        ]);

        $this->clearCaps();
        $this->setPrefs(['prefsStyleLimits' => '2']);

        try {
            $this->login();
            $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

            $this->post('/brew', ['brewName' => 'Table Full', 'brewStyle' => '1-A', 'brewConfirmed' => '1'])
                ->assertRedirect('/list?msg=12');

            self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());
        } finally {
            DB::table('judging_tables')->where('id', $tableId)->delete();
        }
    }

    /**
     * #1-#4 incremental tiers (prefsUserEntryLimitDates): while a tier's
     * window is open the tier's limit-number caps the participant (msg=8).
     */
    public function test_incremental_tier_limit_applies_inside_its_window(): void
    {
        $this->clearCaps();
        $this->setPrefs([
            'prefsUserEntryLimitDates' => json_encode(['1' => ['limit-number' => '1', 'limit-days' => '30']]),
        ]);

        // setUp opened the window 2 days ago → tier-1 window (30d) still open.
        $this->login();
        $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

        $this->post('/brew', ['brewName' => 'Incremental Full', 'brewStyle' => '1-B', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=8');

        self::assertSame(1, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    /** Once the tier's window has passed, the incremental limit no longer applies. */
    public function test_incremental_tier_expires_after_its_window(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(40)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
        ]);

        $this->clearCaps();
        $this->setPrefs([
            'prefsUserEntryLimitDates' => json_encode(['1' => ['limit-number' => '1', 'limit-days' => '30']]),
        ]);

        $this->login();
        $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

        $this->post('/brew', ['brewName' => 'After Window', 'brewStyle' => '1-B', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=1');

        self::assertSame(2, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    /**
     * The edit path now runs the same caps, but excludes the row being edited:
     * a benign re-save of the entry that already fills the group is allowed,
     * while moving a different entry into the full group is blocked.
     */
    public function test_edit_rechecks_caps_and_excludes_the_edited_row(): void
    {
        $this->clearCaps();
        $this->setPrefs(['prefsStyleLimits' => json_encode(['01' => '1'])]);

        $this->login();
        $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');
        $first = $this->lastEntry();
        self::assertNotNull($first);

        // Benign re-save: the only entry in group 01 stays in group 01.
        $this->post('/brew/'.$first['id'].'/edit', [
            'brewName' => 'Renamed', 'brewStyle' => '1-A', 'brewConfirmed' => '1',
        ])->assertRedirect('/list?msg=2');

        // Park an entry in a different group, then try to move it into 01.
        $this->post('/brew', ['brewName' => 'Other Group', 'brewStyle' => '2-A', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=1');
        $second = $this->lastEntry();
        self::assertNotNull($second);

        $this->post('/brew/'.$second['id'].'/edit', [
            'brewName' => 'Move Into Full Group', 'brewStyle' => '1-B', 'brewConfirmed' => '1',
        ])->assertRedirect('/list?msg=12');
    }

    /** Clear every cap so a single-mechanism test cannot trip another. */
    private function clearCaps(): void
    {
        $this->setPrefs([
            'prefsUserEntryLimit' => null,
            'prefsUserSubCatLimit' => null,
            'prefsUSCLExLimit' => null,
            'prefsUSCLEx' => null,
            'prefsStyleLimits' => null,
            'prefsUserEntryLimitDates' => null,
            'prefsEntryLimit' => null,
            'prefsEntryLimitPaid' => null,
        ]);
    }

    // ---- member discount password ----

    /** Arm a member rate + password on the contest row (restored in tearDown). */
    private function setMemberDiscount(string $password = 'memberpw'): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryFee' => '10.00',
            'contestEntryFeePasswordNum' => '6.00',
            'contestEntryFeePassword' => $password,
            'contestEntryFeeDiscount' => 'N',
        ]);
    }

    public function test_member_discount_password_grants_the_member_rate(): void
    {
        $this->clearCaps();
        $this->setMemberDiscount();

        $this->login();
        $this->postEntry(['brewStyle' => '1-A', 'contestEntryFeePassword' => 'memberpw'])
            ->assertRedirect('/list?msg=1');

        self::assertSame('Y', (string) DB::table('brewer')->where('uid', 1)->value('brewerDiscount'));
        // The cheaper member rate now applies to this entrant…
        self::assertSame('6.00', FeeCalculator::forEntrant(TenantContext::load(), 1, 1));
        // …and clears back to the standard fee without the flag.
        DB::table('brewer')->where('uid', 1)->update(['brewerDiscount' => 'N']);
        self::assertSame('10.00', FeeCalculator::forEntrant(TenantContext::load(), 1, 1));
    }

    public function test_member_discount_password_wrong_value_is_rejected_loudly(): void
    {
        $this->clearCaps();
        $this->setMemberDiscount();
        DB::table('brewer')->where('uid', 1)->update(['brewerDiscount' => 'N']);

        $this->login();
        $this->from('/brew')->post('/brew', [
            'brewName' => 'Bad Password', 'brewStyle' => '1-A', 'brewConfirmed' => '1',
            'contestEntryFeePassword' => 'wrong',
        ])->assertSessionHasErrors('contestEntryFeePassword');

        // No flag granted and no entry written.
        self::assertNotSame('Y', (string) DB::table('brewer')->where('uid', 1)->value('brewerDiscount'));
        self::assertSame(0, DB::table('brewing')->where('brewBrewerID', 1)->count());
    }

    public function test_member_discount_password_blank_is_a_noop(): void
    {
        $this->clearCaps();
        $this->setMemberDiscount();
        DB::table('brewer')->where('uid', 1)->update(['brewerDiscount' => 'N']);

        $this->login();
        $this->postEntry(['brewStyle' => '1-A'])->assertRedirect('/list?msg=1');

        self::assertNotSame('Y', (string) DB::table('brewer')->where('uid', 1)->value('brewerDiscount'));
    }

    // ---- prefsSpecific hides the Brewer's Specifics field ----

    public function test_prefs_specific_hides_the_comments_field(): void
    {
        $this->setPrefs(['prefsSpecific' => '1']);
        $this->login();
        $this->get('/brew')->assertOk()->assertDontSee('name="brewComments"', false);

        $this->setPrefs(['prefsSpecific' => '0']);
        $this->get('/brew')->assertOk()->assertSee('name="brewComments"', false);
    }

    public function test_hidden_comments_field_preserves_the_stored_value(): void
    {
        // Store a value while the field is shown…
        $this->setPrefs(['prefsSpecific' => '0']);
        $this->login();
        $this->postEntry(['brewComments' => 'keepers'])->assertRedirect('/list?msg=1');
        $entry = $this->lastEntry();
        self::assertNotNull($entry);

        // …then hide it and re-save: an absent field must not null the column.
        $this->setPrefs(['prefsSpecific' => '1']);
        $this->post('/brew/'.$entry['id'].'/edit', [
            'brewName' => 'Renamed', 'brewStyle' => '1-A', 'brewConfirmed' => '1',
        ])->assertRedirect('/list?msg=2');

        self::assertSame('keepers', (string) DB::table('brewing')->where('id', $entry['id'])->value('brewComments'));
    }

    public function test_form_lists_only_selected_styles_of_the_active_set(): void
    {
        $this->login();

        $html = (string) $this->get('/brew')->assertOk()->getContent();

        // Option values carry the system separator shape the POST explodes
        // on: ltrim(group,'0').'-'.sub (style_number_const default method).
        self::assertSame(1, substr_count($html, 'value="1-A"'));
        self::assertStringContainsString('American Light Lager', $html);
        // Styles outside the organizer selection / active set stay hidden.
        self::assertStringNotContainsString('value="999-Z"', $html);
    }

    public function test_validation_requires_name_and_style(): void
    {
        $this->login();

        $this->from('/brew')->post('/brew', ['brewName' => '', 'brewStyle' => 'nope'])
            ->assertSessionHasErrors(['brewName', 'brewStyle']);

        self::assertNull(DB::table('brewing')->where('brewBrewerID', 1)->first());
    }

    /**
     * AABC2025 spans AABC2022 (beer) + AABC2025 (cider/mead, type 2). A beer
     * code that lives only under AABC2022 must still resolve — the old
     * single-version guess looked in AABC2025 and found nothing.
     */
    public function test_aabc2025_beer_style_resolves_from_aabc2022(): void
    {
        $this->setPrefs(['prefsStyleSet' => 'AABC2025']);
        $this->login();

        // Real code path: POST /brew resolves the code through
        // BrewController::styleFlags() → StyleSets::findStyle().
        $this->post('/brew', ['brewName' => 'AABC Beer', 'brewStyle' => '01-04', 'brewConfirmed' => '1'])
            ->assertRedirect('/list?msg=1');

        $entry = $this->lastEntry();
        self::assertNotNull($entry);
        // Resolved to the AABC2022 row instead of a null style.
        self::assertSame('American Light Lager [BJCP 1A]', $entry['brewStyle']);
        self::assertSame('01', (string) $entry['brewCategorySort']);
        self::assertSame('04', $entry['brewSubCategory']);
        self::assertSame(1, (int) $entry['brewStyleType']);

        // The flag lookup the entry form uses is non-null under AABC2025.
        $flags = BrewController::styleFlags('01-04', TenantContext::load());
        self::assertNotNull($flags);
        self::assertSame('AABC2022', (string) $flags->brewStyleVersion);
    }

    /**
     * BJCP2025 spans BJCP2021. Resolution is newest-first: a cider code
     * present in BOTH versions must win the BJCP2025 row, while a beer code
     * (only in BJCP2021) falls through to the predecessor.
     */
    public function test_bjcp2025_resolves_newest_first_then_falls_back(): void
    {
        $this->setPrefs(['prefsStyleSet' => 'BJCP2025']);
        $ctx = TenantContext::load();

        // C1-A exists in BJCP2025 (Common Cider) and BJCP2021 (New World
        // Cider) — newest wins.
        $cider = BrewController::styleFlags('C1-A', $ctx);
        self::assertNotNull($cider);
        self::assertSame('BJCP2025', (string) $cider->brewStyleVersion);
        self::assertSame('Common Cider', (string) $cider->brewStyle);

        // 01-A exists only in BJCP2021 (American Light Lager) — fallback.
        $beer = BrewController::styleFlags('01-A', $ctx);
        self::assertNotNull($beer);
        self::assertSame('BJCP2021', (string) $beer->brewStyleVersion);
        self::assertSame('American Light Lager', (string) $beer->brewStyle);
    }

    /** Custom styles extend every version and must still resolve. */
    public function test_custom_style_still_resolves(): void
    {
        $this->setPrefs(['prefsStyleSet' => 'AABC2025']);
        $id = DB::table('styles')->insertGetId([
            'brewStyleGroup' => '77',
            'brewStyleNum' => 'ZZ',
            'brewStyle' => 'Custom One-Off',
            'brewStyleVersion' => 'ZZCUSTOM',
            'brewStyleType' => '1',
            'brewStyleOwn' => 'custom',
        ]);

        try {
            $flags = BrewController::styleFlags('77-ZZ', TenantContext::load());
            self::assertNotNull($flags);
            self::assertSame('custom', (string) $flags->brewStyleOwn);
            self::assertSame('Custom One-Off', (string) $flags->brewStyle);
        } finally {
            DB::table('styles')->where('id', $id)->delete();
        }
    }
}
