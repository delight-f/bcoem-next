<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments ledger back office — spec §7 P5.5, ticket P5.5.
 * Legacy: admin/payments.admin.php (the PayPal IPN ledger: payer, item,
 * gross, status, txn id, entries, date, delete).
 *
 * The legacy PayPal transport is NOT ported (spec §9/D7); this screen
 * lists the SAME ledger shape from the transport-blind `payments` table
 * written by the Stripe + manual paths. Manual marking itself lives at
 * /admin/payments (ManualPaymentController, P3.5c); the convergence of
 * both writers is pinned in tests/Feature/BackofficeTest.php.
 */
final class PaymentsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $payments = DB::table('payments')
            ->leftJoin('brewer', 'brewer.uid', '=', 'payments.entrant_uid')
            ->orderBy('payments.id')
            ->get([
                'payments.id', 'payments.entrant_uid', 'payments.entry_ids',
                'payments.amount', 'payments.currency', 'payments.method',
                'payments.provider_ref', 'payments.event_id', 'payments.status',
                'payments.created_at',
                'brewer.brewerFirstName', 'brewer.brewerLastName',
            ]);

        return view('backoffice.payments', [
            'ctx' => TenantContext::load(),
            'payments' => $payments,
        ]);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Legacy delete: removes the ledger row only, no flag reversal.
        DB::table('payments')->where('id', $id)->delete();

        return redirect('/admin/payments?msg=deleted');
    }

    /**
     * Ledger "For Entries" cell: stored JSON ids rendered as the legacy
     * six-digit-padded, comma-separated list.
     */
    public static function entryList(?string $json): string
    {
        $ids = json_decode((string) $json, true);

        if (! is_array($ids) || $ids === []) {
            return '';
        }

        return implode(', ', array_map(
            fn ($id): string => str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            $ids,
        ));
    }

    /** Date column: tenant-formatted created_at (legacy payment_time). */
    public static function paymentDate(TenantContext $ctx, ?string $createdAt): string
    {
        if ($createdAt === null || $createdAt === '') {
            return '';
        }

        return DateFmt::dateTime(
            strtotime($createdAt) ?: 0,
            $ctx->prefsStr('prefsTimeZone'),
            $ctx->prefsStr('prefsDateFormat'),
            $ctx->prefsStr('prefsTimeFormat'),
            'short',
            false,
        ) ?? '';
    }
}
