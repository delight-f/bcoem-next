<?php

declare(strict_types=1);

namespace Tests\Feature;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Slice B gate (ticket 18 / P3.8): the scripted season leg on a real
 * baseline dump — register entrant → create entry → edit entry → pay via
 * MANUAL admin marking → final DB-state convergence on users, brewer,
 * brewing flags, and payments rows (spec §8.3 payment exception: the pay
 * leg is validated by resulting DB state, not page diffing). The suite
 * runs without a browser; a green run IS the "zero console errors" gate —
 * every step above would surface as a 500/exception otherwise.
 *
 * The bcoem_test DB is shared; unique emails + explicit teardown keep the
 * corpus pristine (same discipline as RegisterFlowTest).
 */
final class SliceBE2ETest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'gate.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9201;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private string $email = '';

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var array<string, mixed> */
    private array $origContest = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        // Manual check marking requires the Payment tab's "Accept Checks?"
        // switch (the baseline ships it off).
        $this->origPrefs = ['prefsCheck' => DB::table('preferences')->where('id', 1)->value('prefsCheck')];
        DB::table('preferences')->where('id', 1)->update(['prefsCheck' => '1']);

        $this->email = 'gate.test.'.uniqid().'@example.com';

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();

        $orig = (array) DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = collect($orig)->except(['id'])->all();
        // The baseline entry window is already past; force registration AND
        // entry windows open around any realistic test clock (epochs in
        // this schema — same trick as RegisterFlowTest/BrewEditTest).
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',      // 2000-01-01 UTC
            'contestRegistrationDeadline' => '4102444800', // 2100-01-01 UTC
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
            'contestEntryEditDeadline' => Date::now()->addDays(30)->getTimestamp(),
            'contestDropoffOpen' => Date::now()->subDays(1)->getTimestamp(),
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
            'contestShippingDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);

        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->userIds[] = self::ADMIN_ID;
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entryIds !== [] ? $this->entryIds : [0])->delete();
        DB::table('payments')->whereIn('entrant_uid', $this->userIds !== [] ? $this->userIds : [0])->delete();
        foreach ($this->userIds as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(): array
    {
        return [
            'user_name' => $this->email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => 'pabst',
            'brewerFirstName' => 'Gate',
            'brewerLastName' => 'Tester',
            'brewerCountry' => 'United States',
            'brewerAddress' => '123 Main St',
            'brewerCity' => 'Anytown',
            'brewerState' => 'CO',
            'brewerZip' => '80001',
            'brewerPhone1' => '555-1234',
            'brewerProAm' => '0',
            'brewerClubs' => '',
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
            'brewerJudgeWaiver' => null,
        ];
    }

    public function test_registration_entry_edit_manual_payment_converges(): void
    {
        // --- 1. Register a NEW entrant; auto-login lands on the list. ---
        $this->post('/register/entrant', $this->registerPayload())
            ->assertRedirect('/?section=list&msg=7');
        $this->assertAuthenticated();

        $user = (array) DB::table('users')->where('user_name', $this->email)->sole();
        $uid = (int) $user['id'];
        $this->userIds[] = $uid;
        self::assertSame('2', (string) $user['userLevel']); // legacy entrant level
        self::assertStringStartsWith('$2y$', (string) $user['password']);

        $brewer = (array) DB::table('brewer')->where('uid', $uid)->sole();
        self::assertSame('Gate', $brewer['brewerFirstName']);
        self::assertSame('Tester', $brewer['brewerLastName']);
        self::assertSame($this->email, $brewer['brewerEmail']);
        self::assertSame('N', $brewer['brewerJudge']);
        self::assertSame('N', $brewer['brewerSteward']);

        $staff = (array) DB::table('staff')->where('uid', $uid)->sole();
        self::assertSame(0, (int) $staff['staff_judge']);
        self::assertSame(0, (int) $staff['staff_steward']);

        // --- 2. Create an entry through POST /brew. ---
        $this->post('/brew', [
            'brewName' => 'Gate Pale Ale',
            'brewStyle' => '1-A',
            'brewCoBrewer' => '',
            'brewInfo' => '',
            'brewComments' => '',
            'brewConfirmed' => '1',
        ])->assertRedirect('/list?msg=1');

        $entryId = (int) DB::table('brewing')->where('brewBrewerID', $uid)->max('id');
        $this->entryIds[] = $entryId;
        self::assertSame('Gate Pale Ale', (string) DB::table('brewing')->where('id', $entryId)->value('brewName'));

        // --- 3. Edit the entry through POST /brew/{id}/edit. ---
        $this->post('/brew/'.$entryId.'/edit', [
            'brewName' => 'Gate Pale Ale (revised)',
            'brewStyle' => '1-A',
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
        ])->assertRedirect('/list?msg=2');

        self::assertSame('Gate Pale Ale (revised)', (string) DB::table('brewing')->where('id', $entryId)->value('brewName'));

        // --- 4. Pay via the deterministic MANUAL marking path (admin). ---
        $this->post('/logout');
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);

        $fee = (float) DB::table('contest_info')->where('id', 1)->value('contestEntryFee');

        $this->post('/admin/payments/mark', [
            'entry_ids' => [$entryId],
            'pay_method' => 'check',
            'reference' => '#1001',
            'note' => 'mailed check',
        ])->assertRedirect('/admin/payments/mark?msg=marked');

        // --- 5. Final convergence state. ---
        $entry = (array) DB::table('brewing')->where('id', $entryId)->sole();
        self::assertSame(1, (int) $entry['brewPaid']);
        self::assertSame('1', (string) $entry['brewConfirmed']);
        self::assertSame(0, (int) $entry['brewReceived']); // paid ≠ received
        self::assertNotEmpty($entry['brewUpdated']);
        self::assertSame((string) $uid, (string) $entry['brewBrewerID']);
        self::assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $entry['brewJudgingNumber']);

        $payment = (array) DB::table('payments')->where('entrant_uid', $uid)->sole(); // exactly one row
        self::assertSame('paid', $payment['status']);
        self::assertSame('manual', $payment['method']);
        self::assertSame('check', $payment['pay_method']);
        self::assertSame('#1001', $payment['reference']);
        self::assertSame($fee, (float) $payment['amount']); // contestEntryFee × 1 entry
        self::assertSame(self::ADMIN_ID, (int) $payment['admin_uid']);
        self::assertContains($entryId, array_map(intval(...), json_decode((string) $payment['entry_ids'], true)));
    }
}
