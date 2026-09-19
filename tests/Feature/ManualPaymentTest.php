<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\Checkout;
use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;
use App\Support\Payments\PaymentService;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Manual payment marking (P3.5c, ticket 13). Pins:
 *  - gating: userLevel<=1 only (guest + entrant rejected);
 *  - single and batch marking write the SAME rows the Stripe path writes —
 *    payments row (status=paid, method, pay_method, reference, admin_uid
 *    audit) plus brewing brewPaid=1/brewConfirmed=1/brewUpdated (ledger #5);
 *  - duplicate marking of an already-paid entry is an idempotent no-op
 *    (pinned decision: no second payments row, flags untouched);
 *  - CONVERGENCE: manual marking and a fake Stripe success produce
 *    byte-identical payments+brewing state modulo provider columns.
 */
final class ManualPaymentTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'admin.marking@brewingcompetitions.com';

    private const ENTRANT = 'entrant.marking@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entries = [];

    /** @var array<string, mixed> */
    private array $origContest = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        // Manual marking only offers the methods the Payment tab enables
        // (Accept Cash / Accept Checks); the baseline ships both off.
        $this->origPrefs = [
            'prefsCash' => DB::table('preferences')->where('id', 1)->value('prefsCash'),
            'prefsCheck' => DB::table('preferences')->where('id', 1)->value('prefsCheck'),
        ];
        DB::table('preferences')->where('id', 1)->update(['prefsCash' => '1', 'prefsCheck' => '1']);

        foreach ([self::ADMIN, self::ENTRANT] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        DB::table('users')->insert([
            ['id' => 9101, 'user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => 9102, 'user_name' => self::ENTRANT, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        DB::table('brewer')->insert([
            'uid' => 9102,
            'brewerFirstName' => 'Marking',
            'brewerLastName' => 'Entrant',
            'brewerEmail' => self::ENTRANT,
        ]);

        $orig = DB::table('contest_info')->where('id', 1)->value('contestEntryFee');
        if ($orig !== null) {
            $this->origContest = ['contestEntryFee' => $orig];
        }
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '7.00']);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('payments')->whereIn('entrant_uid', [9101, 9102])->delete();
        DB::table('users')->whereIn('id', [9101, 9102])->delete();
        DB::table('brewer')->where('uid', 9102)->delete();
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    private function login(string $email): void
    {
        $this->loginWithEmail($email);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return int brewing.id
     */
    private function makeEntry(array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'Marking Fixture',
            'brewCategorySort' => '10',
            'brewCategory' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 9102,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entries[] = $id;

        return $id;
    }

    /**
     * @param  list<int>  $ids
     * @return TestResponse<Response>
     */
    private function mark(array $ids, string $payMethod = 'check', string $reference = '#123'): TestResponse
    {
        return $this->post('/admin/payments/mark', [
            'entry_ids' => $ids,
            'pay_method' => $payMethod,
            'reference' => $reference,
            'note' => 'mailed check',
        ]);
    }

    public function test_guest_and_entrant_are_rejected(): void
    {
        $id = $this->makeEntry();

        // Guest: the route's auth middleware bounces to /login.
        $this->get('/admin/payments/mark')->assertRedirect('/login');
        $this->mark([$id])->assertRedirect('/login');

        // Entrant (userLevel=2): same rejection.
        $this->login(self::ENTRANT);
        $this->get('/admin/payments/mark')->assertRedirect('/?msg=99');
        $this->mark([$id])->assertRedirect('/?msg=99');

        self::assertSame(0, (int) DB::table('payments')->count());
        self::assertSame(0, (int) DB::table('brewing')->where('id', $id)->value('brewPaid'));
    }

    public function test_marks_single_entry_with_audit_fields(): void
    {
        $id = $this->makeEntry();
        $this->login(self::ADMIN);

        $this->get('/admin/payments/mark')->assertOk()->assertSee('Marking Fixture');

        $this->mark([$id])->assertRedirect('/admin/payments/mark?msg=marked');

        $payment = (array) DB::table('payments')->where('entrant_uid', 9102)->sole();
        self::assertSame('paid', $payment['status']);
        self::assertSame('manual', $payment['method']);
        self::assertSame('check', $payment['pay_method']);
        self::assertSame('#123', $payment['reference']);
        self::assertSame(9101, (int) $payment['admin_uid']);
        self::assertSame('7.00', (string) $payment['amount']);
        self::assertSame('mailed check', $payment['note']);
        self::assertSame([$id], json_decode((string) $payment['entry_ids']));
        $entry = (array) DB::table('brewing')->where('id', $id)->first();
        self::assertSame(1, (int) $entry['brewPaid']);
        self::assertSame(1, (int) $entry['brewConfirmed']);
        self::assertNotEmpty($entry['brewUpdated']);
    }

    public function test_marks_batch_and_duplicate_is_idempotent_noop(): void
    {
        $a = $this->makeEntry(['brewName' => 'Batch A']);
        $b = $this->makeEntry(['brewName' => 'Batch B']);
        $this->login(self::ADMIN);

        $this->mark([$a, $b], 'bank-transfer', 'TRF-77')
            ->assertRedirect('/admin/payments/mark?msg=marked');

        self::assertSame(1, (int) DB::table('payments')->count());
        self::assertSame(14.0, (float) DB::table('payments')->value('amount'));
        self::assertSame('bank-transfer', (string) DB::table('payments')->value('pay_method'));
        self::assertSame('TRF-77', (string) DB::table('payments')->value('reference'));
        self::assertSame(1, (int) DB::table('brewing')->where('id', $a)->value('brewPaid'));
        self::assertSame(1, (int) DB::table('brewing')->where('id', $b)->value('brewPaid'));

        // Re-marking the same batch: idempotent no-op — no second ledger
        // row, no new event (pinned: explicit no-op, not an error).
        $before = DB::table('payments')->get();
        $this->mark([$a, $b])->assertRedirect('/admin/payments/mark?msg=already-paid');
        self::assertSame($before->toJson(), DB::table('payments')->get()->toJson());
    }

    public function test_manual_and_stripe_converge_byte_identical(): void
    {
        $manualEntry = $this->makeEntry(['brewName' => 'Converge Manual']);
        $stripeEntry = $this->makeEntry(['brewName' => 'Converge Stripe']);
        $this->login(self::ADMIN);

        // Path A: admin marks paid through the HTTP surface.
        $this->mark([$manualEntry]);

        // Path B: a fake Stripe success applied through the SAME service —
        // exactly what PayController::callback() does for a verified event.
        $this->app->bind(GatewayAdapter::class, fn (): GatewayAdapter => new FakeStripe);
        app(PaymentService::class)->apply(
            new PaymentResult(PaymentEvent::Paid, 'evt_stripe_1', 'pay_stripe_1', '7.00'),
            [$stripeEntry],
            9102,
            'stripe',
            '7.00',
        );

        // Brewing rows: identical flag state on both paths.
        $m = (array) DB::table('brewing')->where('id', $manualEntry)->first(['brewPaid', 'brewConfirmed']);
        $s = (array) DB::table('brewing')->where('id', $stripeEntry)->first(['brewPaid', 'brewConfirmed']);
        self::assertSame($m, $s);
        self::assertSame(1, (int) $m['brewPaid']);
        self::assertSame(1, (int) $m['brewConfirmed']);

        // Payments rows: every shared column byte-identical; only provider
        // columns differ (method, provider_ref/event_id identity,
        // pay_method/reference/admin_uid — manual-only audit).
        $pm = (array) DB::table('payments')->whereJsonContains('entry_ids', $manualEntry)->sole();
        $ps = (array) DB::table('payments')->whereJsonContains('entry_ids', $stripeEntry)->sole();
        foreach (['entrant_uid', 'amount', 'currency', 'status'] as $col) {
            self::assertSame((string) $pm[$col], (string) $ps[$col], $col);
        }
        self::assertSame('manual', $pm['method']);
        self::assertSame('stripe', $ps['method']);
        self::assertSame('paid', $pm['status']);
        self::assertSame('paid', $ps['status']);
        self::assertNotNull($pm['created_at']);
        self::assertNotNull($ps['created_at']);
    }
}

/** Minimal stand-in for the ticket 14 adapter: hosted-flow shape, method stripe. */
final class FakeStripe implements GatewayAdapter
{
    #[\Override]
    public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
    {
        return new Checkout('cs_test_'.implode('-', $entries), 'https://stripe.example/checkout');
    }

    #[\Override]
    public function method(): string
    {
        return 'stripe';
    }

    /** @param  array<string, mixed>  $payload */
    #[\Override]
    public function handleCallback(array $payload): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Paid, (string) ($payload['event_id'] ?? ''), (string) ($payload['ref'] ?? ''), (string) ($payload['amount'] ?? ''));
    }

    #[\Override]
    public function refund(string $paymentRef): PaymentResult
    {
        return new PaymentResult(PaymentEvent::Refunded, 'evt_refund_'.$paymentRef, $paymentRef);
    }
}
