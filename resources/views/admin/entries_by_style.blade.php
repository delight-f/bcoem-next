<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }} entry count broken down by style.</h1>

        <a class="btn btn-outline btn-primary mb-4" href="{{ url('/backoffice/count-by-substyle') }}">View Entry Count by Sub-Style</a>

        @if ($filter === 'no_zeros')
            <a class="btn btn-outline btn-secondary mb-4" href="{{ url('/backoffice/count-by-style') }}">Show Categories with Zero Entries</a>
        @else
            <a class="btn btn-outline btn-secondary mb-4" href="{{ url('/backoffice/count-by-style', ['filter' => 'no_zeros']) }}">Hide Categories with Zero Entries</a>
        @endif

        @if ($rows->isNotEmpty())
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Logged</th>
                        <th>Paid &amp; Received</th>
                        <th>Style Type</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->label }} - {{ $row->name }}</td>
                            <td>{{ $row->logged }}</td>
                            <td>{{ $row->paidReceived }}</td>
                            <td>{{ $row->type }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th>Totals</th>
                        <td>{{ $totals->logged }}</td>
                        <td>{{ $totals->paidReceived }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </section>
</x-public-layout>
