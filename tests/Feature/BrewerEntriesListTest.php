<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * My entries list + account entry management (ticket 08): /list renders the
 * brewer's entries ordered per legacy entries.db.php:7
 * (brewCategorySort, brewSubCategory), badge states mirror the
 * brewPaid/brewReceived/brewConfirmed flags, and edit/delete links follow
 * brewer_entries.pub.php:501/549/567/586-593 gating. Delete ports the
 * process.inc.php flow: ownership-checked POST landing on /list?msg=5.
 */
final class BrewerEntriesListTest extends PublicSurfaceTestCase
{
    private const LOGIN = 'user.baseline@brewingcompetitions.com';

    /** @var array<string, mixed> */
    private array $origContest = [];

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
        DB::table('brewing')->where('brewBrewerID', 999)->delete();

        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
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
     * Baseline contest dates are long past; open the entry window for the
     * duration of a test.
     */
    private function openEntryWindow(): void
    {
        $row = (array) DB::table('contest_info')->where('id', 1)->first();

        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }

        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
            'contestDropoffOpen' => Date::now()->subDays(1)->getTimestamp(),
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        return (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'Test Entry',
            'brewStyle' => 'American Amber Ale',
            'brewCategory' => '10',
            'brewCategorySort' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 1,
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewConfirmed' => '1',
            'brewJudgingNumber' => '123456',
        ], $overrides), 'id');
    }

    private function entriesHtml(): string
    {
        $html = (string) $this->get('/list')->assertOk()->getContent();

        self::assertSame(1, preg_match('/<section id="entries"(.*?)<\/section>/s', $html, $m), 'entries section rendered');

        return $m[1] ?? '';
    }

    /**
     * Translator result narrowed to string (these keys are plain strings).
     */
    private static function text(string $key): string
    {
        $v = __($key);

        return is_string($v) ? $v : $key;
    }

    public function test_entries_ordered_by_category_sort_then_subcategory(): void
    {
        $this->makeEntry(['brewCategorySort' => '10', 'brewSubCategory' => 'A', 'brewName' => 'Zulu Stout']);
        $this->makeEntry(['brewCategorySort' => '02', 'brewSubCategory' => 'C', 'brewName' => 'India Pale Ale']);
        $this->makeEntry(['brewCategorySort' => '02', 'brewSubCategory' => 'A', 'brewName' => 'Alpha Amber']);

        $this->login();
        $html = $this->entriesHtml();

        $alpha = strpos($html, 'Alpha Amber');
        $ipa = strpos($html, 'India Pale Ale');
        $zulu = strpos($html, 'Zulu Stout');

        self::assertNotFalse($alpha);
        self::assertNotFalse($ipa);
        self::assertNotFalse($zulu);
        self::assertTrue($alpha < $ipa && $ipa < $zulu, 'rows ordered by brewCategorySort then brewSubCategory');
    }

    public function test_badges_reflect_paid_received_confirmed_flags(): void
    {
        $this->makeEntry(['brewPaid' => 1, 'brewReceived' => 0, 'brewConfirmed' => '1']);
        $this->makeEntry(['brewPaid' => 0, 'brewReceived' => 1, 'brewConfirmed' => '0']);
        $this->makeEntry(['brewPaid' => 0, 'brewReceived' => 0, 'brewConfirmed' => '2']);

        $this->login();
        $html = $this->entriesHtml();

        self::assertSame(1, substr_count($html, 'data-flag="paid" data-state="yes"'));
        self::assertSame(2, substr_count($html, 'data-flag="paid" data-state="no"'));
        self::assertSame(1, substr_count($html, 'data-flag="received" data-state="yes"'));
        self::assertSame(2, substr_count($html, 'data-flag="received" data-state="no"'));

        // Any non-'1' confirmed value is unconfirmed (entry-lifecycle ledger #10).
        self::assertSame(1, substr_count($html, 'data-flag="confirmed" data-state="yes"'));
        self::assertSame(2, substr_count($html, 'data-flag="confirmed" data-state="no"'));
    }

    public function test_entry_and_judging_numbers_are_six_digit_padded(): void
    {
        $id = $this->makeEntry(['brewJudgingNumber' => '42']);

        $this->login();
        $html = $this->entriesHtml();

        self::assertStringContainsString(sprintf('%06d', $id), $html);
        self::assertStringContainsString('000042', $html);
    }

    public function test_edit_delete_enabled_while_window_open_unreceived_and_unpaid(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry();

        $this->login();
        $html = $this->entriesHtml();

        self::assertStringContainsString('/brew/'.$id.'/edit', $html);
        self::assertStringContainsString('/entries/'.$id.'"', $html);
    }

    public function test_delete_disabled_for_paid_entry_while_fee_applies(): void
    {
        // Baseline contestEntryFee is 8.00.
        $this->openEntryWindow();
        $id = $this->makeEntry(['brewPaid' => 1]);

        $this->login();
        $html = $this->entriesHtml();

        self::assertStringContainsString('/brew/'.$id.'/edit', $html);
        self::assertStringNotContainsString('/entries/'.$id.'"', $html);

        // Server-side gate refuses it too.
        $this->post('/entries/'.$id)->assertRedirect('/list?msg=5');
        self::assertTrue(DB::table('brewing')->where('id', $id)->exists());
    }

    public function test_edit_and_delete_disabled_once_received(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry(['brewReceived' => 1]);

        $this->login();
        $html = $this->entriesHtml();

        self::assertStringNotContainsString('/brew/'.$id.'/edit', $html);
        self::assertStringNotContainsString('/entries/'.$id.'"', $html);

        $this->post('/entries/'.$id)->assertRedirect('/list?msg=5');
        self::assertTrue(DB::table('brewing')->where('id', $id)->exists());
    }

    public function test_gating_closes_after_window_and_edit_deadline_pass(): void
    {
        // Force the entry window AND the edit deadline into the past
        // (the baseline's contestEntryEditDeadline is in the future, which
        // legitimately keeps editing open per legacy semantics).
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => 946684800,        // 2000-01-01
            'contestEntryDeadline' => 978307200,    // 2001-01-01
            'contestEntryEditDeadline' => 978307200,
        ]);
        DB::table('judging_locations')->delete();

        $id = $this->makeEntry();

        $this->login();
        $html = $this->entriesHtml();

        self::assertStringNotContainsString('/brew/'.$id.'/edit', $html);
        self::assertStringNotContainsString('/entries/'.$id.'"', $html);

        $this->post('/entries/'.$id)->assertRedirect('/list?msg=5');
        self::assertTrue(DB::table('brewing')->where('id', $id)->exists());
    }

    public function test_destroy_removes_own_unpaid_entry_and_redirects_with_msg5(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry();

        $this->login();
        $this->post('/entries/'.$id)->assertRedirect('/list?msg=5');

        self::assertFalse(DB::table('brewing')->where('id', $id)->exists());
        $this->get('/list?msg=5')->assertSee(self::text('site.deleted_ok'));
    }

    public function test_cannot_delete_another_users_entry(): void
    {
        $this->openEntryWindow();
        $other = $this->makeEntry(['brewBrewerID' => 999]);

        $this->login();
        $this->post('/entries/'.$other)->assertRedirect('/list?msg=5');

        self::assertTrue(DB::table('brewing')->where('id', $other)->exists());

        // The other user's row never appears on this user's list.
        $this->get('/list')->assertOk()->assertDontSee('Test Entry');
    }

    public function test_empty_state_message_when_no_entries(): void
    {
        $this->login();
        $this->get('/list')->assertOk()->assertSee(self::text('site.no_entries'));
    }
}
