<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;
use App\Support\Payments\PaymentService;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Payment state machine (P3.5a) against the real schema: success marking,
 * event-id idempotency (#7), refund reversal (#8), and #6 semantics —
 * only verified Paid events write anything.
 */
final class PaymentServiceTest extends PublicSurfaceTestCase
{
    private const EVENT = 'evt_svc_1';

    /** @var list<int> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        MySqlTestCase::ensureMigrated();

        foreach ([1, 2] as $i) {
            DB::table('brewing')->insert([
                'brewName' => 'Svc Fixture '.$i,
                'brewCategorySort' => '7',
                'brewCategory' => '7',
                'brewSubCategory' => 'A',
                'brewBrewerID' => 9100 + $i,
                'brewConfirmed' => '1',
                'brewPaid' => 0,
                'brewReceived' => 0,
            ]);
            $this->entries[] = (int) DB::getPdo()->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if ($this->entries !== []) {
            DB::table('brewing')->whereIn('id', $this->entries)->delete();
        }
        DB::table('payments')->where('event_id', 'like', 'evt_svc_%')->delete();

        parent::tearDown();
    }

    public function test_mark_paid_inserts_row_and_flips_flags(): void
    {
        $service = new PaymentService;

        self::assertTrue($service->markPaid(
            $this->entries, 42, '25.00', PaymentService::METHOD_STRIPE, 'pay_1', self::EVENT,
        ));

        $row = $this->paymentRow(self::EVENT);
        self::assertSame(42, (int) $row->entrant_uid);
        self::assertSame('25.00', (string) $row->amount);
        self::assertSame('stripe', $row->method);
        self::assertSame('pay_1', $row->provider_ref);
        self::assertSame('paid', $row->status);
        self::assertSame($this->entries, json_decode((string) $row->entry_ids, true));

        foreach ($this->entries as $id) {
            $entry = DB::table('brewing')->where('id', $id)->first();
            self::assertNotNull($entry);
            self::assertSame(1, (int) $entry->brewPaid);
            self::assertSame(1, (int) $entry->brewConfirmed);
            self::assertNotSame('', (string) $entry->brewUpdated);
        }
    }

    public function test_duplicate_event_is_a_noop(): void
    {
        $service = new PaymentService;
        $service->markPaid($this->entries, 42, '25.00', PaymentService::METHOD_STRIPE, 'pay_1', self::EVENT);

        // Re-delivery (ledger #7): no second row, flags untouched.
        self::assertFalse($service->markPaid(
            $this->entries, 42, '25.00', PaymentService::METHOD_STRIPE, 'pay_1', self::EVENT,
        ));
        self::assertSame(1, DB::table('payments')->where('event_id', self::EVENT)->count());
    }

    public function test_failed_and_cancelled_events_write_nothing(): void
    {
        $service = new PaymentService;

        self::assertFalse($service->apply(
            new PaymentResult(PaymentEvent::Failed, 'evt_svc_fail'),
            $this->entries, 42, PaymentService::METHOD_STRIPE, '25.00',
        ));
        self::assertFalse($service->apply(
            new PaymentResult(PaymentEvent::Cancelled, 'evt_svc_cancel'),
            $this->entries, 42, PaymentService::METHOD_STRIPE, '25.00',
        ));

        self::assertSame(0, DB::table('payments')->count());
        foreach ($this->entries as $id) {
            self::assertSame(0, (int) DB::table('brewing')->where('id', $id)->value('brewPaid'));
        }
    }

    public function test_mark_refunded_reverses_status_and_uncovered_entries(): void
    {
        $service = new PaymentService;
        $service->markPaid([$this->entries[0]], 42, '12.50', PaymentService::METHOD_STRIPE, 'pay_r', 'evt_svc_r1');

        // A second payment also covers entries[0]: its paid state must survive.
        $service->markPaid([$this->entries[0]], 43, '12.50', PaymentService::METHOD_MANUAL, null, 'evt_svc_r2');

        self::assertTrue($service->markRefunded('pay_r', 'evt_svc_rr'));

        // The refund event replaces event_id on the row (single dedup
        // surface), so look up by provider ref.
        self::assertSame('refunded', DB::table('payments')->where('provider_ref', 'pay_r')->value('status'));
        self::assertNull(DB::table('payments')->where('event_id', 'evt_svc_r1')->value('status'));
        // entries[0] still covered by evt_svc_r2; nothing reverses it.
        self::assertSame(1, (int) DB::table('brewing')->where('id', $this->entries[0])->value('brewPaid'));
    }

    public function test_refund_reverses_flags_when_no_other_payment_covers_entry(): void
    {
        $service = new PaymentService;

        // Unknown provider ref: no-op, no exception.
        self::assertFalse($service->markRefunded('no-such-ref', 'evt_svc_xr'));

        $service->markPaid($this->entries, 42, '25.00', PaymentService::METHOD_STRIPE, 'pay_x2', 'evt_svc_x2');
        self::assertTrue($service->markRefunded('pay_x2', 'evt_svc_xrr'));

        self::assertSame('refunded', DB::table('payments')->where('provider_ref', 'pay_x2')->value('status'));
        foreach ($this->entries as $id) {
            $entry = DB::table('brewing')->where('id', $id)->first();
            self::assertNotNull($entry);
            self::assertSame(0, (int) $entry->brewPaid);
            self::assertSame(0, (int) $entry->brewConfirmed);
        }
    }

    public function test_apply_routes_paid_result_through_mark_paid(): void
    {
        $service = new PaymentService;

        self::assertTrue($service->apply(
            new PaymentResult(PaymentEvent::Paid, self::EVENT, 'pay_a', '25.00'),
            $this->entries, 44, PaymentService::METHOD_STRIPE, '30.00',
        ));

        $row = $this->paymentRow(self::EVENT);
        // Result amount wins over the caller fallback.
        self::assertSame('25.00', (string) $row->amount);
        self::assertSame(1, (int) DB::table('brewing')->where('id', $this->entries[1])->value('brewPaid'));
    }

    private function paymentRow(string $eventId): \stdClass
    {
        $row = DB::table('payments')->where('event_id', $eventId)->first();
        self::assertNotNull($row);

        return $row;
    }
}
