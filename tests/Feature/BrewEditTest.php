<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Entry edit + lifecycle gates (ticket 10): GET/POST /brew/{id}/edit ports
 * pub/brew.pub.php's edit mode and process_brewing.inc.php's edit branch.
 * Ownership is brewBrewerID = Auth::id(); the edit gate is EntryGates::edit
 * (brewReceived==0, judging not started, window open or before the edit
 * deadline). Paid/received are re-read from the DB for entrants (POST
 * tamper ignored, :269–279), style/category fields freeze once the entry
 * window closes (ledger #4), the judging number is never reallocated, and
 * missing required style fields reset brewConfirmed='0' with msg=1-<style>
 * (ledger #11).
 */
final class BrewEditTest extends PublicSurfaceTestCase
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
     * Close the entry window but leave the entry-edit deadline in the
     * future — legacy still allows edits until that deadline
     * (brewer_entries.pub.php:501 gate, EntryGates::edit).
     */
    private function closeWindowKeepEditDeadline(): void
    {
        $row = (array) DB::table('contest_info')->where('id', 1)->first();

        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }

        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(14)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryEditDeadline' => Date::now()->addDays(5)->getTimestamp(),
            // The effective edit deadline is the earlier of the edit
            // deadline and the drop-ship close (Windows::entryEditDeadline),
            // so both must stay ahead for the edit gate to remain open.
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
            'contestShippingDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        return (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'Test Entry',
            'brewStyle' => 'Weissbier',
            'brewCategory' => '10',
            'brewCategorySort' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 1,
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewConfirmed' => '1',
            'brewJudgingNumber' => '123456',
            'brewUpdated' => '2024-01-01 00:00:00',
        ], $overrides), 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $id): array
    {
        $row = (array) DB::table('brewing')->where('id', $id)->first();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return array_merge([
            'brewName' => 'Renamed Weissbier',
            'brewStyle' => '10-A',
            'brewInfo' => '',
            'brewComments' => '',
            'brewCoBrewer' => '',
            'brewPossAllergens' => '',
            'brewABV' => '',
            'brewSweetnessLevel' => '',
            'brewJuiceSource' => '',
            'brewPouring' => '',
            'brewPackaging' => '',
            'brewMead1' => '',
            'brewMead2' => '',
            'brewMead3' => '',
            'brewConfirmed' => '1',
        ], $overrides);
    }

    public function test_edit_happy_path_updates_row_preserving_flags_and_judging_number(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry();

        $this->login();
        $this->get('/brew/'.$id.'/edit')
            ->assertOk()
            ->assertSee('Test Entry');

        $this->post('/brew/'.$id.'/edit', self::payload())
            ->assertRedirect('/list?msg=2');

        $row = self::row($id);

        self::assertSame('Renamed Weissbier', $row['brewName']);
        // Style parts re-derived per process_brewing.inc.php:286-293 + style name lookup.
        self::assertSame('Weissbier', $row['brewStyle']);
        self::assertSame('10', $row['brewCategory']);
        self::assertSame('10', $row['brewCategorySort']);
        self::assertSame('A', $row['brewSubCategory']);
        // Flags preserved, judging number never reallocated (:269–279).
        self::assertSame(0, (int) $row['brewPaid']);
        self::assertSame(0, (int) $row['brewReceived']);
        self::assertSame(1, (int) $row['brewConfirmed']);
        self::assertSame('123456', $row['brewJudgingNumber']);
        // brewUpdated stamped on every edit (:745).
        self::assertNotSame('2024-01-01 00:00:00', $row['brewUpdated']);
    }

    public function test_cannot_edit_another_users_entry(): void
    {
        $this->openEntryWindow();
        $other = $this->makeEntry(['brewBrewerID' => 999]);

        $this->login();
        $this->get('/brew/'.$other.'/edit')->assertRedirect('/list');

        $before = self::row($other);
        $this->post('/brew/'.$other.'/edit', self::payload())->assertRedirect('/list');

        self::assertSame($before, self::row($other));
    }

    public function test_edit_blocked_once_received(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry(['brewReceived' => 1]);

        $this->login();
        $this->get('/brew/'.$id.'/edit')->assertRedirect('/list');

        $before = self::row($id);
        $this->post('/brew/'.$id.'/edit', self::payload())->assertRedirect('/list');

        self::assertSame($before, self::row($id));
    }

    public function test_edit_blocked_after_window_and_edit_deadline_pass(): void
    {
        // Force the entry window AND the edit deadline into the past (the
        // baseline drop-ship date is future, so without this the shared test
        // DB can leave editing open and this gate won't trip).
        $row = (array) DB::table('contest_info')->where('id', 1)->first();
        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => 946684800,        // 2000-01-01
            'contestEntryDeadline' => 978307200,    // 2001-01-01
            'contestEntryEditDeadline' => 978307200,
            'contestDropoffDeadline' => 978307200,
            'contestShippingDeadline' => 978307200,
        ]);

        $id = $this->makeEntry();

        $this->login();
        $this->get('/brew/'.$id.'/edit')->assertRedirect('/list');

        $before = self::row($id);
        $this->post('/brew/'.$id.'/edit', self::payload())->assertRedirect('/list');

        self::assertSame($before, self::row($id));
    }

    public function test_edit_allowed_while_window_closed_before_edit_deadline(): void
    {
        $this->closeWindowKeepEditDeadline();
        $id = $this->makeEntry();

        $this->login();
        $this->get('/brew/'.$id.'/edit')->assertOk();

        $this->post('/brew/'.$id.'/edit', self::payload())->assertRedirect('/list?msg=2');
        self::assertSame('Renamed Weissbier', self::row($id)['brewName']);
    }

    public function test_style_fields_frozen_once_window_closes(): void
    {
        // Window closed, edit deadline still ahead: other fields stay
        // editable but the style/category block keeps its stored values
        // (entry-lifecycle ledger #4).
        $this->closeWindowKeepEditDeadline();
        $id = $this->makeEntry();

        $this->login();
        $this->post('/brew/'.$id.'/edit', self::payload(['brewStyle' => '28-A']))
            ->assertRedirect('/list?msg=2');

        $row = self::row($id);

        self::assertSame('Weissbier', $row['brewStyle']);
        self::assertSame('10', $row['brewCategory']);
        self::assertSame('10', $row['brewCategorySort']);
        self::assertSame('A', $row['brewSubCategory']);
        self::assertSame('Renamed Weissbier', $row['brewName']);
    }

    public function test_entrant_paid_received_tamper_is_ignored(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry();

        $this->login();
        $this->post('/brew/'.$id.'/edit', self::payload(['brewPaid' => '1', 'brewReceived' => '1']))
            ->assertRedirect('/list?msg=2');

        $row = self::row($id);

        // Entrant values re-read from the DB (:273–279).
        self::assertSame(0, (int) $row['brewPaid']);
        self::assertSame(0, (int) $row['brewReceived']);
    }

    public function test_admin_can_set_paid_and_received_from_edit_form(): void
    {
        $this->openEntryWindow();
        $id = $this->makeEntry();
        DB::table('users')->where('id', 1)->update(['userLevel' => '1']);

        $this->login();
        $this->post('/brew/'.$id.'/edit', self::payload(['brewPaid' => '1', 'brewReceived' => '1']))
            ->assertRedirect('/list?msg=2');

        $row = self::row($id);

        self::assertSame(1, (int) $row['brewPaid']);
        self::assertSame(1, (int) $row['brewReceived']);
    }

    public function test_missing_required_style_fields_reset_confirmed_with_msg_code(): void
    {
        // C1-A New World Cider requires carbonation AND sweetness info
        // (brewStyleCarb/brewStyleSweet == 1); leaving both blank unconfirms
        // the entry and redirects with the legacy msg=1-<style> code.
        $this->openEntryWindow();
        $id = $this->makeEntry([
            'brewStyle' => 'New World Cider',
            'brewCategory' => 'C1',
            'brewCategorySort' => 'C1',
            'brewSubCategory' => 'A',
        ]);

        $this->login();
        $this->post('/brew/'.$id.'/edit', self::payload(['brewStyle' => 'C1-A']))
            ->assertRedirect('/brew/'.$id.'/edit?msg=1-C1-A');

        self::assertSame(0, (int) self::row($id)['brewConfirmed']);

        // Supplying both required values confirms the entry again. Cider
        // sweetness posts under the legacy brewMead2-cider name
        // (process_brewing.inc.php:393).
        $this->post('/brew/'.$id.'/edit', self::payload([
            'brewStyle' => 'C1-A',
            'brewMead1' => 'Sparkling',
            'brewMead2-cider' => 'Dry',
        ]))->assertRedirect('/list?msg=2');

        $row = self::row($id);

        self::assertSame(1, (int) $row['brewConfirmed']);
        self::assertSame('Sparkling', $row['brewMead1']);
        self::assertSame('Dry', $row['brewMead2']);
    }
}
