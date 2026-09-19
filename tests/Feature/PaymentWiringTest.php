<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaymentConfirmMail;
use App\Support\Payments\FeeCalculator;
use App\Support\Tenant\TenantContext;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Payment-tab controls that used to save but do nothing:
 *  - Accept Cash / Accept Checks gate the manual mark-as-paid method list;
 *  - Checks Payable To rides on the check payment confirmation;
 *  - Checkout Fees Paid by Entrant adds a real surcharge to the checkout
 *    total (and only there — the entry-fee model is untouched);
 *  - Pay to Print gates the entrant's own entry-label printing.
 */
final class PaymentWiringTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'pay.wiring.admin@brewingcompetitions.com';

    private const ENTRANT = 'pay.wiring.entrant@brewingcompetitions.com';

    private const OTHER = 'pay.wiring.other@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const ADMIN_ID = 9301;

    private const ENTRANT_ID = 9302;

    private const OTHER_ID = 9303;

    /** @var list<int> */
    private array $entries = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        DB::table('users')->whereIn('user_name', [self::ADMIN, self::ENTRANT, self::OTHER])->delete();
        DB::table('users')->insert([
            ['id' => self::ADMIN_ID, 'user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => self::ENTRANT_ID, 'user_name' => self::ENTRANT, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => self::OTHER_ID, 'user_name' => self::OTHER, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        foreach ([[self::ENTRANT_ID, self::ENTRANT], [self::OTHER_ID, self::OTHER]] as [$id, $email]) {
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('brewer')->insert([
                'uid' => $id,
                'brewerFirstName' => 'Wiring',
                'brewerLastName' => 'Entrant'.$id,
                'brewerEmail' => $email,
            ]);
        }

        foreach ([
            'prefsCash', 'prefsCheck', 'prefsCheckPayee', 'prefsTransFee',
            'prefsTransFeePercent', 'prefsTransFeeFixed', 'prefsPayToPrint',
        ] as $key) {
            $this->origPrefs[$key] = DB::table('preferences')->where('id', 1)->value($key);
        }

        $this->origContest = ['contestEntryFee' => DB::table('contest_info')->where('id', 1)->value('contestEntryFee')];
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '10.00']);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('payments')->whereIn('entrant_uid', [self::ADMIN_ID, self::ENTRANT_ID, self::OTHER_ID])->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::ENTRANT_ID, self::OTHER_ID])->delete();
        DB::table('brewer')->whereIn('uid', [self::ENTRANT_ID, self::OTHER_ID])->delete();

        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function setPrefs(array $values): void
    {
        DB::table('preferences')->where('id', 1)->update($values);
    }

    private function loginAs(string $email): void
    {
        $this->loginWithEmail($email);
    }

    /** @param array<string, mixed> $overrides */
    private function makeEntry(int $uid, array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'Wiring Entry',
            'brewCategorySort' => '10',
            'brewCategory' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => $uid,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entries[] = $id;

        return $id;
    }

    // ---- 4a: cash / check gate the manual method list ----

    public function test_manual_method_list_follows_cash_and_check_switches(): void
    {
        // The form (and its method select) only renders when something is unpaid.
        $this->makeEntry(self::ENTRANT_ID);
        $this->setPrefs(['prefsCash' => '0', 'prefsCheck' => '0']);
        $this->loginAs(self::ADMIN);

        $html = (string) $this->get('/admin/payments/mark')->assertOk()->getContent();
        self::assertStringNotContainsString('<option value="cash">', $html);
        self::assertStringNotContainsString('<option value="check">', $html);
        self::assertStringContainsString('<option value="dropoff">', $html);

        $this->setPrefs(['prefsCash' => '1', 'prefsCheck' => '1']);
        $html = (string) $this->get('/admin/payments/mark')->assertOk()->getContent();
        self::assertStringContainsString('<option value="cash">', $html);
        self::assertStringContainsString('<option value="check">', $html);
    }

    public function test_disabled_method_is_rejected(): void
    {
        $id = $this->makeEntry(self::ENTRANT_ID);
        $this->setPrefs(['prefsCash' => '0', 'prefsCheck' => '0']);
        $this->loginAs(self::ADMIN);

        $this->from('/admin/payments/mark')->post('/admin/payments/mark', [
            'entry_ids' => [$id],
            'pay_method' => 'check',
        ])->assertSessionHasErrors('pay_method');

        self::assertSame(0, (int) DB::table('payments')->count());
        self::assertSame(0, (int) DB::table('brewing')->where('id', $id)->value('brewPaid'));
    }

    // ---- 4b: Checks Payable To on the confirmation ----

    public function test_check_confirmation_carries_payee(): void
    {
        $id = $this->makeEntry(self::ENTRANT_ID);
        $this->setPrefs([
            'prefsCash' => '0',
            'prefsCheck' => '1',
            'prefsCheckPayee' => 'Springfield Brew Club',
        ]);
        $this->loginAs(self::ADMIN);
        Mail::fake();

        $this->post('/admin/payments/mark', [
            'entry_ids' => [$id],
            'pay_method' => 'check',
            'reference' => '#9',
        ])->assertRedirect('/admin/payments/mark?msg=marked');

        Mail::assertSent(PaymentConfirmMail::class, fn (PaymentConfirmMail $mail): bool => $mail->checkPayee === 'Springfield Brew Club');
    }

    // ---- 4c: transaction fee surcharge ----

    public function test_transaction_fee_adds_to_checkout_total_only(): void
    {
        // Off: the plain entry fee.
        $this->setPrefs(['prefsTransFee' => 'N', 'prefsTransFeePercent' => '2.90', 'prefsTransFeeFixed' => '0.30']);
        self::assertSame('10.00', FeeCalculator::forEntrant(TenantContext::load(), self::ENTRANT_ID, 1));

        // On: 10.00 + (10.00 × 2.90% + 0.30) = 10.59.
        $this->setPrefs(['prefsTransFee' => 'Y', 'prefsTransFeePercent' => '2.90', 'prefsTransFeeFixed' => '0.30']);
        self::assertSame('10.59', FeeCalculator::forEntrant(TenantContext::load(), self::ENTRANT_ID, 1));

        // The entry-fee model itself (used for entry-fee displays) is untouched.
        self::assertSame('10.00', FeeCalculator::total(1, false, FeeCalculator::params(TenantContext::load())));
    }

    public function test_transaction_fee_with_no_rate_leaves_the_total_unchanged(): void
    {
        $this->setPrefs(['prefsTransFee' => 'Y', 'prefsTransFeePercent' => null, 'prefsTransFeeFixed' => null]);

        self::assertSame('10.00', FeeCalculator::forEntrant(TenantContext::load(), self::ENTRANT_ID, 1));
    }

    // ---- 4d: Pay to Print ----

    public function test_pay_to_print_gates_entrant_labels(): void
    {
        $id = $this->makeEntry(self::ENTRANT_ID, ['brewPaid' => 0]);

        // Off: labels render for an unpaid entry.
        $this->setPrefs(['prefsPayToPrint' => '0']);
        $this->loginAs(self::ENTRANT);
        $this->get('/list/labels')->assertOk();

        // On: the unpaid entry is refused with a message, not a silent blank.
        $this->setPrefs(['prefsPayToPrint' => '1']);
        $this->get('/list/labels')->assertRedirect('/list?msg=13');

        // Paid: labels render again.
        DB::table('brewing')->where('id', $id)->update(['brewPaid' => 1]);
        $this->get('/list/labels')->assertOk();
    }

    public function test_entrant_labels_never_include_another_brewers_entries(): void
    {
        $otherId = $this->makeEntry(self::OTHER_ID);
        $this->setPrefs(['prefsPayToPrint' => '0']);
        $this->loginAs(self::ENTRANT);

        // Caller has no entries of their own — nothing to print.
        $this->get('/list/labels')->assertRedirect('/list');

        // Someone else's entry id is intersected away, never rendered.
        $this->get('/list/labels?ids='.$otherId)->assertRedirect('/list');

        // With their own entry present the route renders, still scoped to it;
        // another brewer's id is still intersected away.
        $this->makeEntry(self::ENTRANT_ID);
        $this->get('/list/labels')->assertOk();
        $this->get('/list/labels?ids='.$otherId)->assertRedirect('/list');
    }
}
