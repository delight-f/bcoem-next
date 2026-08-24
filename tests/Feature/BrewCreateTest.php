<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Response;
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
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewBrewerID', 1)->delete();

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
}
