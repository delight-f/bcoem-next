<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\PayPalGateway;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Srmklive\PayPal\Testing\MockPayPalClient;

/**
 * End-to-end PayPal webhook convergence (issue #24 P5): a signature-verified
 * PAYMENT.CAPTURE.COMPLETED on POST /webhooks/paypal lands in the SAME rows the
 * Stripe path pins — payments(status=paid, method=paypal) + brewPaid/
 * brewConfirmed=1 — with dedup on event id, fail-closed signature handling,
 * attribution guard and refund reversal. No live calls: the gateway is bound
 * with the signature check stubbed.
 */
final class PayPalWebhookTest extends PublicSurfaceTestCase
{
    private const ENTRIES = [101, 102];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $prefs = DB::table('preferences')->where('id', 1)->first(['prefsCurrency']);
        $this->origPrefs = $prefs === null ? [] : (array) $prefs;
        DB::table('preferences')->where('id', 1)->update(['prefsCurrency' => 'USD']);

        if (! DB::table('users')->where('id', 7)->exists()) {
            DB::table('users')->insert([
                'id' => 7,
                'user_name' => 'payee@brewingcompetitions.com',
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '2',
                'userCreated' => '2024-01-01 00:00:01',
            ]);
            DB::table('brewer')->insert([
                'uid' => 7,
                'brewerFirstName' => 'Pay',
                'brewerLastName' => 'Me',
                'brewerEmail' => 'payee@brewingcompetitions.com',
            ]);
        }

        foreach (self::ENTRIES as $id) {
            DB::table('brewing')->insert([
                'id' => $id,
                'brewName' => 'Entry '.$id,
                'brewStyle' => '1-A',
                'brewBrewerID' => 7,
                'brewPaid' => 0,
                'brewConfirmed' => 1,
            ]);
        }

        $this->bindGateway(true);
    }

    protected function tearDown(): void
    {
        DB::table('payments')->where('entrant_uid', 7)->delete();
        DB::table('brewing')->whereIn('id', self::ENTRIES)->delete();

        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    public function test_completed_capture_marks_entries_paid_and_writes_ledger(): void
    {
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_1', self::capture('CAP-1'))
            ->assertStatus(Response::HTTP_OK);

        $row = DB::table('payments')->where('event_id', 'evt_paypal_1')->first();
        self::assertNotNull($row, 'verified success must write the ledger row');
        self::assertSame('paid', $row->status);
        self::assertSame('paypal', $row->method);
        self::assertSame('CAP-1', $row->provider_ref);
        self::assertSame('25.00', (string) $row->amount);
        self::assertSame(self::ENTRIES, json_decode((string) $row->entry_ids, true));
        self::assertNull($row->admin_uid);

        foreach (DB::table('brewing')->whereIn('id', self::ENTRIES)->get(['brewPaid', 'brewConfirmed']) as $flag) {
            self::assertSame(1, (int) $flag->brewPaid);
            self::assertSame(1, (int) $flag->brewConfirmed);
        }
    }

    public function test_duplicate_event_delivery_is_deduped(): void
    {
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_dup', self::capture('CAP-DUP'))
            ->assertStatus(Response::HTTP_OK);
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_dup', self::capture('CAP-DUP'))
            ->assertStatus(Response::HTTP_OK);

        self::assertSame(1, DB::table('payments')->where('event_id', 'evt_paypal_dup')->count());
    }

    public function test_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        $this->bindGateway(false);

        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_bad', self::capture('CAP-BAD'))
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        self::assertDatabaseMissing('payments', ['event_id' => 'evt_paypal_bad']);
        self::assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_paid_event_without_attribution_is_rejected(): void
    {
        // A foreign capture on the same account: verified but no custom_id.
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_foreign', [
            'id' => 'CAP-FOREIGN',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
        ])->assertStatus(Response::HTTP_BAD_REQUEST);

        self::assertDatabaseMissing('payments', ['event_id' => 'evt_paypal_foreign']);
        self::assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_unhandled_event_never_creates_a_ledger_row(): void
    {
        $this->postWebhook('PAYMENT.CAPTURE.DENIED', 'evt_paypal_denied', ['id' => 'CAP-X'])
            ->assertStatus(Response::HTTP_OK);

        self::assertDatabaseMissing('payments', ['event_id' => 'evt_paypal_denied']);
        self::assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_refund_event_reverses_flags_and_row_status(): void
    {
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', 'evt_paypal_paid', self::capture('CAP-R'))
            ->assertStatus(Response::HTTP_OK);

        $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', 'evt_paypal_refund', [
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            'links' => [[
                'rel' => 'up',
                'href' => 'https://api-m.paypal.com/v2/payments/captures/CAP-R',
            ]],
        ])->assertStatus(Response::HTTP_OK);

        $row = DB::table('payments')->where('provider_ref', 'CAP-R')->first();
        self::assertNotNull($row);
        self::assertSame('refunded', $row->status);
        self::assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
        self::assertSame(0, (int) DB::table('brewing')->where('id', 102)->value('brewConfirmed'));
    }

    /**
     * B1 regression: the webhook endpoints must be CSRF-exempt or they 419 in
     * production (the test environment bypasses CSRF, which hid this).
     */
    public function test_webhook_routes_are_csrf_exempt(): void
    {
        $excluded = app(ValidateCsrfToken::class)->getExcludedPaths();

        self::assertContains('webhooks/stripe', $excluded);
        self::assertContains('webhooks/paypal', $excluded);
        self::assertNotContains('pay/checkout', $excluded);
    }

    /**
     * Bind the container gateway with the signature check stubbed. Setup only
     * uses in-memory transport, so no live PayPal call is ever made.
     */
    private function bindGateway(bool $valid): void
    {
        $this->app->bind(PayPalGateway::class, fn (): PayPalGateway => new class($valid) extends PayPalGateway
        {
            public function __construct(private readonly bool $valid)
            {
                parent::__construct((new MockPayPalClient)->mockProvider(), 'whsec_test', 'USD');
            }

            protected function signatureValid(array $headers, string $raw): bool
            {
                return $this->valid;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return TestResponse<Response>
     */
    private function postWebhook(string $type, string $eventId, array $resource): TestResponse
    {
        $raw = (string) json_encode([
            'id' => $eventId,
            'event_type' => $type,
            'resource' => $resource,
        ]);

        return $this->call('POST', '/webhooks/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private static function capture(string $captureId): array
    {
        return [
            'id' => $captureId,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            'custom_id' => '7|101-102',
        ];
    }
}
