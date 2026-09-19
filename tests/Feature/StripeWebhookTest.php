<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;

/**
 * End-to-end webhook convergence (P3.5b acceptance): a signed
 * checkout.session.completed on POST /webhooks/stripe lands in the SAME
 * rows the ledger pins — payments(status=paid) + brewPaid=1/brewConfirmed=1
 * (ledger #5, port addition) — with dedup on event id (#7), refund reversal
 * (#8) and fail-closed signature handling. No live Stripe calls: payloads
 * are signed locally and verified by the SDK for real (see StripeTestClient).
 */
final class StripeWebhookTest extends PublicSurfaceTestCase
{
    private const SECRET = 'whsec_test';

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $prefs = DB::table('preferences')->where('id', 1)->first(['prefsCurrency', 'prefsStripe']);
        $this->origPrefs = $prefs === null ? [] : (array) $prefs;

        DB::table('preferences')->where('id', 1)->update([
            'prefsCurrency' => 'USD',
            'prefsStripe' => json_encode(['account_id' => 'acct_connected', 'webhook_secret' => self::SECRET]),
        ]);
        config(['services.stripe.secret' => 'sk_test_platform']);

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

        foreach ([101, 102] as $id) {
            DB::table('brewing')->insert([
                'id' => $id,
                'brewName' => 'Entry '.$id,
                'brewStyle' => '1-A',
                'brewBrewerID' => 7,
                'brewPaid' => 0,
                'brewConfirmed' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::table('payments')->whereIn('entrant_uid', [7])->delete();
        DB::table('brewing')->whereIn('id', [101, 102])->delete();
        DB::table('users')->whereIn('id', [8, 9])->delete();

        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        ApiRequestor::setHttpClient(null); // @phpstan-ignore argument.type (resetting restores the default transport lazily)
        parent::tearDown();
    }

    public function test_completed_event_marks_entries_paid_and_writes_ledger(): void
    {
        $response = $this->postWebhook('checkout.session.completed', 'evt_paid_1', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_1',
            'amount_total' => 2500,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ]);

        $response->assertStatus(Response::HTTP_OK);

        $row = DB::table('payments')->where('event_id', 'evt_paid_1')->first();
        $this->assertNotNull($row, 'verified success must write the ledger row');
        $this->assertSame('paid', $row->status);
        $this->assertSame('stripe', $row->method);
        $this->assertSame('pi_1', $row->provider_ref);
        $this->assertSame('25.00', (string) $row->amount);
        $this->assertSame([101, 102], json_decode((string) $row->entry_ids, true));
        $this->assertNull($row->admin_uid);

        // Ledger #5 (+port addition): paid AND confirmed.
        foreach (DB::table('brewing')->whereIn('id', [101, 102])->orderBy('id')->get(['brewPaid', 'brewConfirmed']) as $flag) {
            $this->assertSame(1, (int) $flag->brewPaid);
            $this->assertSame(1, (int) $flag->brewConfirmed);
        }
        $this->assertNotNull(DB::table('brewing')->where('id', 101)->value('brewUpdated'));
    }

    public function test_async_succeeded_event_marks_entries_paid(): void
    {
        // Stripe-recommended must-handle: bank-settlement methods complete
        // days later via async_payment_succeeded (payments plan W5).
        $this->postWebhook('checkout.session.async_payment_succeeded', 'evt_async_1', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_async',
            'amount_total' => 1600,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101'],
        ])->assertStatus(Response::HTTP_OK);

        $row = DB::table('payments')->where('event_id', 'evt_async_1')->first();
        $this->assertNotNull($row);
        $this->assertSame('paid', $row->status);
        $this->assertSame('16.00', (string) $row->amount);
        $this->assertSame(1, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_async_failed_event_never_marks(): void
    {
        $this->postWebhook('checkout.session.async_payment_failed', 'evt_async_2', [
            'object' => 'checkout.session',
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101'],
        ])->assertStatus(Response::HTTP_OK);

        $this->assertNull(DB::table('payments')->where('event_id', 'evt_async_2')->first());
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_paid_event_without_our_metadata_is_rejected(): void
    {
        // A foreign/legacy Checkout Session completed on the same connected
        // account: verified signature but no attribution — fail closed.
        $this->postWebhook('checkout.session.completed', 'evt_foreign', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_foreign',
            'amount_total' => 100,
        ])->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertNull(DB::table('payments')->where('event_id', 'evt_foreign')->first());
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_admin_refund_issues_gateway_refund_and_reverses_flags(): void
    {
        // Settle a payment first (webhook path — the only writer).
        $this->postWebhook('checkout.session.completed', 'evt_refund_setup', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_refundable',
            'amount_total' => 1600,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ])->assertStatus(Response::HTTP_OK);

        // Admin clicks refund: gateway call stubbed, flags reversed (#8).
        $stub = new StripeTestClient([
            '/refunds' => StripeTestClient::json([
                'id' => 're_admin_1',
                'object' => 'refund',
                'payment_intent' => 'pi_refundable',
                'amount' => 1600,
                'status' => 'succeeded',
            ]),
        ]);
        ApiRequestor::setHttpClient($stub);

        $this->loginAdmin();
        $rowId = (int) DB::table('payments')->where('event_id', 'evt_refund_setup')->value('id');
        $this->post("/admin/payments/{$rowId}/refund")->assertRedirect('/admin/payments?msg=refunded');

        $row = DB::table('payments')->where('id', $rowId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refunded', $row->status);
        $this->assertSame('re_admin_1', $row->event_id);
        foreach ([101, 102] as $id) {
            $this->assertSame(0, (int) DB::table('brewing')->where('id', $id)->value('brewPaid'));
            $this->assertSame(0, (int) DB::table('brewing')->where('id', $id)->value('brewConfirmed'));
        }
    }

    public function test_admin_refund_refuses_non_stripe_or_unsettled_rows(): void
    {
        DB::table('payments')->insert([
            'entrant_uid' => 7,
            'entry_ids' => json_encode([101]),
            'amount' => '8.00',
            'currency' => 'USD',
            'method' => 'manual',
            'provider_ref' => null,
            'event_id' => 'evt_manual_row',
            'status' => 'paid',
            'created_at' => now(),
        ]);
        $rowId = (int) DB::table('payments')->where('event_id', 'evt_manual_row')->value('id');

        $this->loginAdmin();
        $this->post("/admin/payments/{$rowId}/refund")->assertRedirect('/admin/payments?msg=refund-invalid');
        $this->assertSame('paid', (string) DB::table('payments')->where('id', $rowId)->value('status'));
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));

        DB::table('payments')->where('id', $rowId)->delete();
    }

    private function loginAdmin(): void
    {
        // isAdmin() = userLevel <= 1; the seeded user 7 is an entrant (2),
        // so create/update a level-0 admin.
        if (! DB::table('users')->where('id', 9)->exists()) {
            DB::table('users')->insert([
                'id' => 9,
                'user_name' => 'admin-refund@brewingcompetitions.com',
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '0',
                'userCreated' => '2024-01-01 00:00:01',
            ]);
        }
        $this->loginWithEmail('admin-refund@brewingcompetitions.com');
    }

    public function test_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        $raw = StripeTestClient::event('checkout.session.completed', 'evt_bad_sig', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_x',
            'amount_total' => 2500,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ]);

        $this->postSigned($raw, StripeTestClient::sign('whsec_wrong', $raw))
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertDatabaseMissing('payments', ['event_id' => 'evt_bad_sig']);
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    public function test_duplicate_event_delivery_is_deduped(): void
    {
        $object = [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_dup',
            'amount_total' => 2500,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ];

        $this->postWebhook('checkout.session.completed', 'evt_dup', $object)->assertStatus(Response::HTTP_OK);
        $this->postWebhook('checkout.session.completed', 'evt_dup', $object)->assertStatus(Response::HTTP_OK); // acked, but...

        $this->assertSame(1, DB::table('payments')->where('event_id', 'evt_dup')->count(), 'ledger #7: one row per event');
    }

    public function test_refund_event_reverses_flags_and_row_status(): void
    {
        $this->postWebhook('checkout.session.completed', 'evt_r_paid', [
            'object' => 'checkout.session',
            'payment_intent' => 'pi_r',
            'amount_total' => 2500,
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ]);

        $this->postWebhook('charge.refunded', 'evt_r_refund', [
            'object' => 'charge',
            'payment_intent' => 'pi_r',
            'amount_refunded' => 2500,
        ])->assertStatus(Response::HTTP_OK);

        $row = DB::table('payments')->where('provider_ref', 'pi_r')->first();
        $this->assertNotNull($row);
        $this->assertSame('refunded', $row->status);
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'), 'ledger #8: refund reverses brewPaid');
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 102)->value('brewConfirmed'));
    }

    public function test_expired_checkout_never_creates_a_ledger_row(): void
    {
        $this->postWebhook('checkout.session.expired', 'evt_exp', [
            'object' => 'checkout.session',
            'metadata' => ['entrant_uid' => '7', 'entry_ids' => '101-102'],
        ])->assertStatus(Response::HTTP_OK);

        $this->assertDatabaseMissing('payments', ['event_id' => 'evt_exp']);
        $this->assertSame(0, (int) DB::table('brewing')->where('id', 101)->value('brewPaid'));
    }

    /**
     * Onboarding surface (P3.5b): settings page renders for admins, 403s
     * everyone else (legacy userLevel gate).
     */
    public function test_admin_stripe_page_is_admin_gated(): void
    {
        // Own admin row: the shared baseline users' passwords are not ours
        // to know, so session auth is driven directly via actingAs().
        if (! DB::table('users')->where('id', 8)->exists()) {
            DB::table('users')->insert([
                'id' => 8,
                'user_name' => 'stripe.admin@brewingcompetitions.com',
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '1',
                'userCreated' => '2024-01-01 00:00:01',
            ]);
        }

        $this->actingAs(User::query()->findOrFail(7));
        $this->get('/admin/stripe')->assertStatus(Response::HTTP_FORBIDDEN);

        $this->actingAs(User::query()->findOrFail(8));
        $this->get('/admin/stripe')->assertStatus(Response::HTTP_OK)
            ->assertSee('Stripe Connect');

        $this->post('/logout');
        $this->get('/admin/stripe')->assertRedirect('/login');
    }

    /**
     * Sanity-check the adapter seam used above resolves from real prefs.
     */
    public function test_for_tenant_reads_settings_from_preferences(): void
    {
        $this->assertSame('stripe', StripeGateway::forTenant()->method());
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function postWebhook(string $type, string $eventId, array $object): TestResponse
    {
        $raw = StripeTestClient::event($type, $eventId, $object);

        return $this->postSigned($raw, StripeTestClient::sign(self::SECRET, $raw));
    }

    /** Laravel's post() cannot send a raw body — go through call(). */
    /** @return TestResponse<Response> */
    private function postSigned(string $raw, string $signature): TestResponse
    {
        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }
}
