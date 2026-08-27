<x-public-layout :ctx="$ctx" :show-hero="false">
    <p class="lead">{{ $ctx->contestStr('contestName') }}: PayPal Payments</p>

    @if (request('msg') === 'deleted')
        <div class="alert alert-success">Payment record deleted.</div>
    @endif

    @if ($payments->isEmpty())
        <p>No payments have been recorded in the database.</p>
    @else
        <table class="table table-responsive table-striped table-bordered" id="sortable">
            <thead>
                <tr>
                    <th nowrap>Payer <span class="hidden-xs hidden-sm">Name</span></th>
                    <th class="hidden-xs">Item</th>
                    <th>Am<span class="hidden-xs">ount</span></th>
                    <th>St<span class="hidden-xs">atus</span></th>
                    <th nowrap><span class="hidden-xs">Transaction</span> ID</th>
                    <th class="hidden-xs"><span class="hidden-sm">For</span> Entries...</th>
                    <th>Date</th>
                    <th>Act<span class="hidden-xs">ions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        {{-- Legacy cell format: LAST, First --}}
                        <td>{{ ucwords((string) $payment->brewerLastName) }}, {{ ucwords((string) $payment->brewerFirstName) }}</td>
                        <td class="hidden-xs">Entry Fees</td>
                        <td>{{ $payment->amount }} {{ $payment->currency }}</td>
                        <td>{{ $payment->status }}</td>
                        <td>{{ $payment->provider_ref !== '' && $payment->provider_ref !== null ? $payment->provider_ref : $payment->event_id }}</td>
                        <td class="hidden-xs">{{ \App\Http\Controllers\Admin\PaymentsController::entryList($payment->entry_ids) }}</td>
                        <td>{{ \App\Http\Controllers\Admin\PaymentsController::paymentDate($ctx, $payment->created_at) }}</td>
                        <td nowrap>
                            <form method="post" action="{{ route('admin.payments.destroy', ['id' => $payment->id]) }}" class="inline"
                                  onsubmit="return confirm('Are you sure you want to delete this payment? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-link" style="margin:0; padding:0;" data-toggle="tooltip" data-placement="top" title="Delete this payment"><span class="fa fa-lg fa-trash-o"></span></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-public-layout>
