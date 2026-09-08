<x-public-layout :ctx="$ctx" :show-hero="false">
    <p class="lead">{{ $ctx->contestStr('contestName') }}: Payments</p>

    @if (request('msg') === 'deleted')
        <div class="alert alert-success">Payment record deleted.</div>
    @elseif (request('msg') === 'refunded')
        <div class="alert alert-success">Payment refunded; affected entries un-confirmed.</div>
    @elseif (request('msg') === 'refund-invalid')
        <div class="alert alert-danger">Refund could not be completed (already refunded, not a Stripe payment, or gateway error).</div>
    @endif

    @if ($payments->isEmpty())
        <p>No payments have been recorded in the database.</p>
    @else
        <table class="table table-responsive table-striped table-bordered" id="sortable">
            <thead>
                <tr>
                    <th nowrap>Payer <span class="d-none d-lg-inline">Name</span></th>
                    <th class="d-none d-md-table-cell">Item</th>
                    <th>Am<span class="d-none d-md-inline">ount</span></th>
                    <th>St<span class="d-none d-md-inline">atus</span></th>
                    <th nowrap><span class="d-none d-md-inline">Transaction</span> ID</th>
                    <th class="d-none d-md-table-cell"><span class="d-none d-lg-inline">For</span> Entries...</th>
                    <th>Date</th>
                    <th>Act<span class="d-none d-md-inline">ions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        {{-- Legacy cell format: LAST, First --}}
                        <td>{{ ucwords((string) $payment->brewerLastName) }}, {{ ucwords((string) $payment->brewerFirstName) }}</td>
                        <td class="d-none d-md-table-cell">Entry Fees</td>
                        <td>{{ $payment->amount }} {{ $payment->currency }}</td>
                        <td>{{ $payment->status }}</td>
                        <td>{{ $payment->provider_ref !== '' && $payment->provider_ref !== null ? $payment->provider_ref : $payment->event_id }}</td>
                        <td class="d-none d-md-table-cell">{{ \App\Http\Controllers\Admin\PaymentsController::entryList($payment->entry_ids) }}</td>
                        <td>{{ \App\Http\Controllers\Admin\PaymentsController::paymentDate($ctx, $payment->created_at) }}</td>
                        <td nowrap>
                            @if ($payment->method === 'stripe' && $payment->status === 'paid' && $payment->provider_ref !== null && $payment->provider_ref !== '')
                                <form method="post" action="{{ route('admin.payments.refund', ['id' => $payment->id]) }}" class="d-inline"
                                      onsubmit="return confirm('Refund this payment and un-confirm its entries? This cannot be undone.');">
                                    @csrf
                                    <button type="submit" class="btn btn-link" style="margin:0; padding:0;" data-bs-toggle="tooltip" data-bs-placement="top" title="Refund this payment"><span class="fa fa-lg fa-undo"></span></button>
                                </form>
                            @endif
                            <form method="post" action="{{ route('admin.payments.destroy', ['id' => $payment->id]) }}" class="d-inline"
                                  onsubmit="return confirm('Are you sure you want to delete this payment? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-link" style="margin:0; padding:0;" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete this payment"><span class="fa fa-lg fa-trash-o"></span></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-public-layout>
