<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\FeeCalculator;
use App\Support\Payments\ManualGateway;
use App\Support\Payments\PaymentService;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Manual payment marking (spec P3.5c, ticket 13): the admin confirms
 * off-line collection (check/cash/dropoff/bank-transfer) through the SAME
 * PaymentService state machine as the Stripe path, so the resulting
 * `payments` + `brewing` rows are identical (transport-blindness).
 *
 * Gating: userLevel<=1 only (same in-controller gate as
 * StripeConnectController — no admin middleware exists yet). Already-paid
 * entries in a batch are skipped as an idempotent no-op (pinned in
 * tests/Feature/ManualPaymentTest and ledger/payments.md "Port decisions");
 * only currently unpaid+confirmed entries can be marked.
 *
 * The controller routes its intent through ManualGateway::handleCallback()
 * (fail-closed translation, contract-tested like every adapter) and then
 * PaymentService::markPaid() — apply() is bypassed only because manual
 * marking carries pay_method/reference that PaymentResult does not.
 */
final class ManualPaymentController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.payments', [
            'ctx' => TenantContext::load(),
            'unpaid' => $this->unpaidEntries(),
            'fee' => TenantContext::load()->contestStr('contestEntryFee') ?? '0',
            'payMethods' => ManualGateway::PAY_METHODS,
        ]);
    }

    public function markPaid(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['integer'],
            'pay_method' => ['required', 'in:'.implode(',', ManualGateway::PAY_METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Idempotent no-op on already-paid entries: intersect the request
        // with what is still markable; nothing left → say so and stop.
        $markable = $this->unpaidEntries()->pluck('id')->all();
        $batch = array_values(array_intersect($data['entry_ids'], $markable));

        if ($batch === []) {
            return redirect('/admin/payments/mark?msg=already-paid');
        }

        $gateway = new ManualGateway;
        $service = app(PaymentService::class);
        $ctx = TenantContext::load();
        $adminUid = (int) Auth::id();

        // A batch may span entrants; each entrant gets one payments row.
        $rows = DB::table('brewing')->whereIn('id', $batch)->get(['id', 'brewBrewerID']);
        foreach ($rows->groupBy('brewBrewerID') as $uid => $entries) {
            $ids = array_values(array_map(intval(...), $entries->pluck('id')->all()));

            // Amount is computed server-side from the legacy fee model
            // (tiers/cap/special rate — payments plan W4, ledger #9): the
            // admin form never posts money values.
            $amount = FeeCalculator::forEntrant($ctx, (int) $uid, count($ids));

            $result = $gateway->handleCallback([
                'outcome' => 'paid',
                'event_id' => 'manual_'.$adminUid.'-'.implode('-', $ids).'-'.time(),
                'ref' => $data['reference'] ?? null,
                'amount' => $amount,
                'note' => $data['note'] ?? '',
            ]);

            if (! $result->isPaid()) {
                continue;
            }

            $service->markPaid(
                $ids,
                (int) $uid,
                $result->amount ?? $amount,
                $gateway->method(),
                $result->providerRef,
                $result->eventId,
                $result->note,
                $adminUid,
                $data['pay_method'],
                $data['reference'] ?? '',
            );
        }

        return redirect('/admin/payments/mark?msg=marked');
    }

    /**
     * Confirmed, unpaid entries in the legacy list order — same shape the
     * public pay page lists (PayController::unpaidEntries).
     *
     * @return Collection<int, \stdClass>
     */
    private function unpaidEntries(): Collection
    {
        return DB::table('brewing')
            ->where('brewConfirmed', '1')
            ->where('brewPaid', '!=', 1)
            ->orderBy('brewCategorySort')
            ->orderBy('brewSubCategory')
            ->get(['id', 'brewName', 'brewBrewerID', 'brewCategory', 'brewSubCategory', 'brewStyle']);
    }
}
