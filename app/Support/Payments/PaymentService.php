<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Mail\PaymentConfirmMail;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Payment state machine (spec D7): converts verified gateway events into
 * `payments` rows + brewing flag flips, idempotently.
 *
 * Ledger mapping (ledger/payments.md):
 *   #5  success writes brewPaid=1 + brewUpdated=NOW per entry — the port
 *       ADDS brewConfirmed=1 (pin: a paid entry is a confirmed entry; see
 *       "Port decisions" in the ledger).
 *   #6  only explicit success events mark paid — apply() maps Failed /
 *       Cancelled / Refunded results to no-ops or reversal, never marking.
 *   #7  dedup on gateway event id: unique index on payments.event_id plus
 *       an exists check; re-delivery neither double-inserts nor re-flips.
 *   #8  refunds are NEW behavior (legacy had none): status=refunded +
 *       flag reversal, only on verified refund events.
 *   #9  reconcileAmount() compares posted vs owed fees (legacy IPN never
 *       validated mc_gross).
 */
final class PaymentService
{
    public const METHOD_STRIPE = 'stripe';

    public const METHOD_MANUAL = 'manual';

    /**
     * Single entry point for webhook/manual controllers: applies one
     * callback result. Returns true only when a state change was applied.
     *
     * @param  list<int>  $entries
     */
    public function apply(
        PaymentResult $result,
        array $entries,
        int $entrantUid,
        string $method,
        string $amount,
        ?int $adminUid = null,
    ): bool {
        return match ($result->event) {
            PaymentEvent::Paid => $this->markPaid(
                $entries,
                $entrantUid,
                $result->amount ?? $amount,
                $method,
                $result->providerRef,
                $result->eventId,
                $result->note,
                $adminUid,
            ),
            // A refund event's providerRef identifies the ORIGINAL payment.
            PaymentEvent::Refunded => $this->markRefunded(
                (string) $result->providerRef,
                $result->eventId,
                $result->note,
            ),
            PaymentEvent::Failed, PaymentEvent::Cancelled => false,
        };
    }

    /**
     * Marks an entry batch paid: inserts the ledger row and flips flags.
     * Idempotent on $eventId (#7). Returns false for duplicate events.
     *
     * @param  list<int>  $entries
     */
    public function markPaid(
        array $entries,
        int $entrantUid,
        string $amount,
        string $method,
        ?string $providerRef,
        string $eventId,
        string $note = '',
        ?int $adminUid = null,
        string $payMethod = '',
        string $reference = '',
    ): bool {
        if ($entries === [] || DB::table('payments')->where('event_id', $eventId)->exists()) {
            return false;
        }

        try {
            DB::transaction(function () use ($entries, $entrantUid, $amount, $method, $providerRef, $eventId, $note, $adminUid, $payMethod, $reference): void {
                DB::table('payments')->insert([
                    'entrant_uid' => $entrantUid,
                    'entry_ids' => json_encode(array_values($entries), JSON_THROW_ON_ERROR),
                    'amount' => $amount,
                    'method' => $method,
                    'provider_ref' => $providerRef,
                    'event_id' => $eventId,
                    'status' => 'paid',
                    'note' => $note !== '' ? $note : null,
                    'admin_uid' => $adminUid,
                    ...($payMethod !== '' ? ['pay_method' => $payMethod] : []),
                    ...($reference !== '' ? ['reference' => $reference] : []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                // Legacy IPN shape (#5): brewPaid=1 + brewUpdated=NOW per
                // entry. Port adds brewConfirmed=1.
                DB::table('brewing')->whereIn('id', $entries)->update([
                    'brewPaid' => 1,
                    'brewConfirmed' => 1,
                    'brewUpdated' => now()->format('Y-m-d H:i:s'),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate delivery won the race — already applied.
            return false;
        }

        // Payment confirmation (P3.6): legacy ppv.php mailed the entrant
        // on every verified success, provider-neutral in the port (no
        // PayPal wording — ledger/payments.md D7).
        $this->sendConfirmation($entries, $entrantUid, $amount);

        return true;
    }

    /**
     * Marks a settled payment refunded (#8, new behavior): flips the row to
     * status=refunded and reverses brewPaid/brewConfirmed on its entries —
     * except entries covered by another still-paid payment. Idempotent on
     * the refund event id. Returns false for duplicates/unknown refs.
     */
    public function markRefunded(string $paymentRef, string $refundEventId, string $note = ''): bool
    {
        if (DB::table('payments')->where('event_id', $refundEventId)->exists()) {
            return false;
        }

        $row = DB::table('payments')
            ->where('provider_ref', $paymentRef)
            ->where('status', 'paid')
            ->first();
        if ($row === null) {
            return false;
        }

        DB::transaction(function () use ($row, $refundEventId, $note): void {
            DB::table('payments')->where('id', $row->id)->update([
                'event_id' => $refundEventId,
                'status' => 'refunded',
                ...($note !== '' ? ['note' => $note] : []),
                'updated_at' => now(),
            ]);

            foreach ($this->entriesOnlyThisRowCovers($row) as $entryId) {
                DB::table('brewing')->where('id', $entryId)->update([
                    'brewPaid' => 0,
                    'brewConfirmed' => 0,
                    'brewUpdated' => now()->format('Y-m-d H:i:s'),
                ]);
            }
        });

        return true;
    }

    /**
     * Fee reconciliation (#9): does the posted amount equal owed fees?
     * Owed = flat contestEntryFee x entry count (the entry-creation fee
     * model); cents-exact compare, no float math. Pure — callers supply
     * the fee so this stays unit-testable and context-free.
     *
     * @param  list<int>  $entries
     */
    public static function reconcileAmount(array $entries, string $postedAmount, string $feePerEntry): bool
    {
        return self::cents($postedAmount) === count($entries) * self::cents($feePerEntry);
    }

    /**
     * Decimal string ('25', '25.5', '25.00') → integer cents. String math,
     * no float rounding surprises.
     */
    private static function cents(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');

        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction.'00', 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /**
     * Entrant confirmation mail for a settled batch (P3.6). Recipient and
     * display name come from brewer; currency from prefs.
     *
     * @param  list<int>  $entries
     */
    private function sendConfirmation(array $entries, int $entrantUid, string $amount): void
    {
        $brewer = DB::table('brewer')
            ->where('uid', $entrantUid)
            ->first(['brewerFirstName', 'brewerEmail']);

        if ($brewer === null || $brewer->brewerEmail === null) {
            return;
        }

        Mail::to($brewer->brewerEmail)->send(new PaymentConfirmMail(
            (string) $brewer->brewerFirstName,
            (string) TenantContext::load()->contestStr('contestName'),
            $entries,
            $amount,
            (string) (TenantContext::load()->prefsStr('prefsCurrency') ?? 'USD'),
        ));
    }

    /**
     * Entries on this payment row NOT covered by any other currently-paid
     * row. JSON containment is checked in PHP: entry_ids is a JSON column
     * and this keeps the query driver-agnostic (MySQL + SQLite).
     *
     * @return list<int>
     */
    private function entriesOnlyThisRowCovers(\stdClass $row): array
    {
        $own = json_decode((string) $row->entry_ids, true, 512, JSON_THROW_ON_ERROR);
        $covered = [];
        foreach (DB::table('payments')->where('status', 'paid')->where('id', '!=', $row->id)->get(['entry_ids']) as $other) {
            foreach (json_decode((string) $other->entry_ids, true, 512, JSON_THROW_ON_ERROR) as $entryId) {
                $covered[$entryId] = true;
            }
        }

        return array_values(array_diff($own, array_keys($covered)));
    }
}
