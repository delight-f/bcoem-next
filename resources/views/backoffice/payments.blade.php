<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Payments</h1>

        @if (request('msg') === 'deleted')
            <div class="alert alert-success">Payment record deleted.</div>
        @endif

        <p class="text-muted">
            Ledger of all recorded payments (Stripe and manual marking share this
            table). Mark entries paid on the
            <a href="{{ url('/admin/payments') }}">manual marking screen</a>.
        </p>

        @if ($payments->isEmpty())
            <p>No payments have been recorded in the database.</p>
        @else
            <table class="table table-responsive table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Payer Name</th>
                        <th>Item</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Transaction ID</th>
                        <th>For Entries...</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            {{-- Legacy cell format: LAST, First --}}
                            <td>{{ ucfirst((string) $payment->brewerLastName) }}, {{ ucfirst((string) $payment->brewerFirstName) }}</td>
                            <td>Entry Fees</td>
                            <td>{{ $payment->amount }} {{ $payment->currency }}</td>
                            <td>{{ $payment->status }}</td>
                            <td>{{ $payment->provider_ref !== '' && $payment->provider_ref !== null ? $payment->provider_ref : $payment->event_id }}</td>
                            <td>{!! \App\Http\Controllers\Admin\PaymentsController::entryList($payment->entry_ids) !!}</td>
                            <td>{{ \App\Http\Controllers\Admin\PaymentsController::paymentDate($ctx, $payment->created_at) }}</td>
                            <td>
                                <form method="post" action="{{ route('backoffice.payments.destroy', ['id' => $payment->id]) }}"
                                      onsubmit="return confirm('Delete this payment record? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link btn-sm p-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
