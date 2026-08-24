<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\Checkout;
use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Public pay page (ticket 15): unpaid list + batch total, checkout redirect
 * through the bound GatewayAdapter, success flag flips via PaymentService,
 * cancel leaves entries unpaid, already-paid/free-comp settled states, and
 * the legacy gating ladder (pay.pub.php:63-75).
 */
final class PayPageTest extends PublicSurfaceTestCase
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
        DB::table('payments')->where('entrant_uid', 1)->delete();
        DB::table('judging_locations')->where('judgingLocName', 'paytest')->delete();

        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }

        parent::tearDown();
    }

    /**
     * Deterministic fake standing in for whichever adapter ticket 13/14
     * binds in production. Same contract, so it also exercises the page's
     * transport-blindness.
     */
    private function bindFakeGateway(PaymentEvent $callbackEvent = PaymentEvent::Paid, string $eventId = 'evt_paytest_1'): void
    {
        $this->app->bind(GatewayAdapter::class, function () use ($callbackEvent, $eventId) {
            return new class($callbackEvent, $eventId) implements GatewayAdapter
            {
                public function __construct(
                    private readonly PaymentEvent $event,
                    private readonly string $eventId,
                ) {}

                public function method(): string
                {
                    return 'manual';
                }

                public function createCheckout(array $entries, int $entrantUid, string $feeTotal): Checkout
                {
                    FakeAdapterProbe::$checkoutArgs = [$entries, $entrantUid, $feeTotal];

                    return new Checkout('cs_paytest', '/gateway-hosted');
                }

                public function handleCallback(array $payload): PaymentResult
                {
                    $count = count(array_filter(explode('-', (string) ($payload['entries'] ?? ''))));

                    return new PaymentResult(
                        $this->event,
                        $this->eventId,
                        'pi_paytest',
                        number_format($count * 8, 2, '.', ''),
                    );
                }

                public function refund(string $paymentRef): PaymentResult
                {
                    return new PaymentResult(PaymentEvent::Refunded, 'evt_refund_'.$paymentRef);
                }

                public function cancel(string $checkoutId): PaymentResult
                {
                    return new PaymentResult(PaymentEvent::Cancelled, 'evt_cancel_'.$checkoutId);
                }
            };
        });
    }

    private function login(): void
    {
        $this->post('/login', [
            'loginUsername' => self::LOGIN,
            'loginPassword' => 'bcoem',
        ]);
    }

    private function setFee(string $fee): void
    {
        $row = (array) DB::table('contest_info')->where('id', 1)->first();

        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }

        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => $fee]);
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

    private function html(): string
    {
        return (string) $this->get('/pay')->assertOk()->getContent();
    }

    // -----------------------------------------------------------------
    // Gating + states
    // -----------------------------------------------------------------

    public function test_anonymous_visitor_is_bounced_to_login(): void
    {
        // auth middleware bounces anonymous visitors to the login page.
        $this->get('/pay')->assertRedirect('/login');
    }

    public function test_unpaid_list_shows_batch_total_and_excludes_paid_entries(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $this->makeEntry(['brewName' => 'Unpaid Ale']);
        $this->makeEntry(['brewName' => 'Unpaid Lager', 'brewCategorySort' => '11', 'brewSubCategory' => 'C']);
        $this->makeEntry(['brewName' => 'Already Paid', 'brewPaid' => 1]);

        $html = $this->html();
        self::assertStringContainsString('Unpaid Ale', $html);
        self::assertStringContainsString('Unpaid Lager', $html);
        self::assertStringNotContainsString('Already Paid', $html);

        // Legacy orders by brewCategorySort then brewSubCategory.
        self::assertLessThan(
            strpos($html, 'Unpaid Lager') ?: PHP_INT_MAX,
            strpos($html, 'Unpaid Ale') ?: PHP_INT_MAX,
        );
        // Batch total = unpaid confirmed x contestEntryFee (2 x 8).
        self::assertMatchesRegularExpression('/16\.00/', $html);
        self::assertStringContainsString((string) route('pay.checkout'), $html);

    }

    public function test_unconfirmed_entries_are_not_charged_or_listed(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $this->makeEntry(['brewName' => 'Confirmed Entry', 'brewConfirmed' => '1']);
        $this->makeEntry(['brewName' => 'Unconfirmed Entry', 'brewConfirmed' => '0']);

        $html = $this->html();

        self::assertStringNotContainsString('Unconfirmed Entry', $html);
        self::assertMatchesRegularExpression('/8\.00/', $html);
    }

    public function test_already_paid_renders_settled_state_without_checkout(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $this->makeEntry(['brewPaid' => 1]);

        $html = $this->html();

        self::assertStringContainsString('Your fees have been marked as paid. Thank you!', $html);
        self::assertStringNotContainsString((string) route('pay.checkout'), $html);
    }

    public function test_free_competition_has_nothing_to_collect(): void
    {
        $this->setFee('0');
        $this->bindFakeGateway();
        $this->login();

        // Even an unpaid-flag row (legacy data edge) must not open a checkout:
        // free comps mark entries paid at creation.
        $this->makeEntry(['brewName' => 'Free Entry']);

        $html = $this->html();

        self::assertStringContainsString('Your fees have been marked as paid. Thank you!', $html);
        self::assertStringNotContainsString((string) route('pay.checkout'), $html);
    }

    public function test_no_gateway_configured_renders_unavailable_state(): void
    {
        $this->setFee('8');
        $this->login();

        $this->makeEntry(['brewName' => 'Unpayable']);

        // Legacy condition (pinned): pay.pub.php rendered its payment
        // sections only when prefsCash/prefsCheck/prefsPaypal == 'Y'; with
        // none enabled the page showed totals but no way to pay. The port's
        // equivalent is "no GatewayAdapter bound".
        $html = $this->html();

        self::assertStringContainsString('Online payment is not available for this competition at this time.', $html);
        self::assertStringContainsString('Unpayable', $html);
        self::assertStringNotContainsString((string) route('pay.checkout'), $html);
    }

    public function test_disable_pay_when_all_windows_closed(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        // Baseline dates are long past; force every window fully closed
        // including a past judging session so the pay window closes too.
        $row = (array) DB::table('contest_info')->where('id', 1)->first();
        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }
        $past = Date::now()->subDays(60)->getTimestamp();
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => Date::now()->subDays(90)->getTimestamp(),
            'contestRegistrationDeadline' => $past,
            'contestEntryOpen' => Date::now()->subDays(90)->getTimestamp(),
            'contestEntryDeadline' => $past,
            'contestJudgeOpen' => Date::now()->subDays(90)->getTimestamp(),
            'contestJudgeDeadline' => $past,
            'contestDropoffOpen' => Date::now()->subDays(90)->getTimestamp(),
            'contestDropoffDeadline' => $past,
            'contestShippingOpen' => Date::now()->subDays(90)->getTimestamp(),
            'contestShippingDeadline' => $past,
        ]);
        DB::table('judging_locations')->insert([
            'judgingLocName' => 'paytest',
            'judgingDate' => $past,
            'judgingDateEnd' => $past,
            'judgingLocType' => 0,
        ]);

        $this->makeEntry(['brewName' => 'Late Entry']);

        $html = $this->html();

        self::assertStringContainsString('Since the account registration, entry registration, shipping, and drop-off deadlines have all passed, payments are no longer being accepted.', $html);
        self::assertStringNotContainsString((string) route('pay.checkout'), $html);
    }

    public function test_paid_entry_limit_blocks_further_payment(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $rowPrefs = (array) DB::table('preferences')->where('id', 1)->first();
        $origPref = ['prefsEntryLimitPaid' => $rowPrefs['prefsEntryLimitPaid']];
        DB::table('preferences')->where('id', 1)->update(['prefsEntryLimitPaid' => '1']);
        // limitReached only bites while the entry window is open; baseline
        // dates are long past, so open it for this test.
        $row = (array) DB::table('contest_info')->where('id', 1)->first();
        if ($this->origContest === []) {
            $this->origContest = collect($row)->except(['id'])->all();
        }
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
        ]);
        $this->makeEntry(['brewName' => 'Paid One', 'brewPaid' => 1]);
        $this->makeEntry(['brewName' => 'Still Unpaid']);

        try {
            $html = $this->html();

            self::assertStringContainsString('The limit of paid entries has been reached - further entry payments are not being accepted.', $html);
            self::assertStringNotContainsString((string) route('pay.checkout'), $html);
        } finally {
            DB::table('preferences')->where('id', 1)->update($origPref);
        }
    }

    // -----------------------------------------------------------------
    // Checkout flow
    // -----------------------------------------------------------------

    public function test_checkout_redirects_with_full_batch_and_amount(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $a = $this->makeEntry(['brewName' => 'Batch A']);
        $b = $this->makeEntry(['brewName' => 'Batch B']);
        $this->makeEntry(['brewName' => 'Excluded Paid', 'brewPaid' => 1]);

        $this->post('/pay/checkout')->assertRedirect('/gateway-hosted');

        $captured = FakeAdapterProbe::$checkoutArgs;
        self::assertNotNull($captured);
        [$ids, $uid, $feeTotal] = $captured;
        sort($ids);
        $expected = [$a, $b];
        sort($expected);
        self::assertSame([$expected, 1, '16.00'], [$ids, $uid, $feeTotal]);
    }

    public function test_checkout_without_unpaid_entries_stays_on_page(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $this->post('/pay/checkout')->assertRedirect('/pay');
    }

    public function test_success_callback_flips_flags_through_service_idempotently(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway(PaymentEvent::Paid, 'evt_paid_once');
        $this->login();

        $a = $this->makeEntry(['brewName' => 'To Pay A']);
        $b = $this->makeEntry(['brewName' => 'To Pay B']);

        $this->get("/pay/callback?uid=1&entries={$a}-{$b}&fee=16.00")
            ->assertRedirect('/pay?msg=13');

        foreach ([$a, $b] as $id) {
            $row = DB::table('brewing')->where('id', $id)->first();
            self::assertNotNull($row);
            self::assertSame(1, (int) $row->brewPaid);
            self::assertSame(1, (int) $row->brewConfirmed);
        }

        $payment = DB::table('payments')->where('entrant_uid', 1)->first();
        self::assertNotNull($payment);
        self::assertSame(16.0, (float) $payment->amount);
        self::assertSame('manual', $payment->method);
        self::assertSame('paid', $payment->status);

        // Ledger #7: duplicate event delivery dedups on event id — one row,
        // flags unchanged.
        $this->get("/pay/callback?uid=1&entries={$a}-{$b}&fee=16.00")
            ->assertRedirect('/pay?msg=13');

        self::assertSame(1, DB::table('payments')->where('entrant_uid', 1)->count());
    }

    public function test_failed_callback_lands_cancelled_state_and_leaves_unpaid(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway(PaymentEvent::Failed, 'evt_failed');
        $this->login();

        $a = $this->makeEntry(['brewName' => 'Stays Unpaid']);

        $this->get("/pay/callback?uid=1&entries={$a}&fee=8.00")
            ->assertRedirect('/pay?msg=14');

        self::assertSame(0, (int) DB::table('brewing')->where('id', $a)->value('brewPaid'));
        self::assertSame(0, DB::table('payments')->count());

        // The cancelled state renders the legacy msg=14 text and the entry
        // is still up for payment.
        $html = (string) $this->get('/pay?msg=14')->assertOk()->getContent();
        self::assertStringContainsString('Your online payment has been cancelled.', $html);
        self::assertStringContainsString('Stays Unpaid', $html);
    }

    public function test_callback_cannot_pay_another_users_entries(): void
    {
        $this->setFee('8');
        $this->bindFakeGateway();
        $this->login();

        $foreign = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Foreign Entry',
            'brewStyle' => 'Porter',
            'brewCategory' => '12',
            'brewCategorySort' => '12',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 999,
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewConfirmed' => '1',
        ], 'id');

        $this->get("/pay/callback?uid=1&entries={$foreign}&fee=8.00")
            ->assertRedirect('/pay?msg=13');

        self::assertSame(0, (int) DB::table('brewing')->where('id', $foreign)->value('brewPaid'));

        DB::table('brewing')->where('id', $foreign)->delete();
    }
}

/**
 * Read-side handle on the fake's capture slot (anonymous class statics are
 * per-class; resolve through the container binding).
 */
final class FakeAdapterProbe
{
    /** @var array{0: list<int>, 1: int, 2: string}|null */
    public static ?array $checkoutArgs = null;
}
