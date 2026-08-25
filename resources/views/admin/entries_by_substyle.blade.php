<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }} entry count broken down by sub-style.</h1>

        <a class="btn btn-outline btn-primary mb-4" href="{{ url('/backoffice/count-by-style') }}">View Entry Count by Style</a>

        @if ($filter === 'no_zeros')
            <a class="btn btn-outline btn-secondary mb-4" href="{{ url('/backoffice/count-by-substyle') }}">Show Sub-Styles with Zero Entries</a>
        @else
            <a class="btn btn-outline btn-secondary mb-4" href="{{ url('/backoffice/count-by-substyle', ['filter' => 'no_zeros']) }}">Hide Sub-Styles with Zero Entries</a>
        @endif

        <h3>Breakdown By Sub-Style</h3>
        @if ($rows->isNotEmpty())
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Sub-Style</th>
                        <th>Style</th>
                        <th>Logged</th>
                        <th>Paid &amp; Received</th>
                        <th>Style Type</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->label }}</td>
                            <td>{{ $row->category }}</td>
                            <td>{{ $row->logged }}</td>
                            <td>{{ $row->paidReceived }}</td>
                            <td>{{ $row->type }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Totals</th>
                        <td>{{ $totals->logged }}</td>
                        <td>{{ $totals->paidReceived }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </section>
</x-public-layout>
