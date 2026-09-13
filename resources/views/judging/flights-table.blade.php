<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $planning ? 'Planning Mode' : 'Define Flights' }} for Table {{ $table->tableNumber }} &ndash; {{ $table->tableName }}</h1>

        <p><a href="{{ route('admin.judging.flights.index') }}">&larr; All Tables</a></p>

        @if ($flightCount === 0)
            <p>No {{ $planning ? 'entries' : 'received entries' }} match this table's styles yet.</p>
        @else
            @if ($flightCount === 1)
                <p>This table only requires one flight.</p>
            @else
                <p>This table can be divided into {{ $flightCount }} flights. For each entry below, designate the flight in which it will be judged.</p>
            @endif

            <form method="post" action="{{ route('admin.judging.flights.store', ['id' => $table->id]) }}">
                @csrf
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Judging #</th>
                            <th>Style</th>
                            @for ($i = 1; $i <= $flightCount; $i++)
                                <th>Flight {{ $i }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td>{{ $entry->brewJudgingNumber }}</td>
                                <td>{{ ltrim($entry->brewCategorySort, '0') }}{{ $entry->brewSubCategory }}: {{ $entry->brewStyle }}</td>
                                @for ($i = 1; $i <= $flightCount; $i++)
                                    <td>
                                        <input type="radio" name="flights[{{ $entry->id }}]" value="{{ $i }}"
                                               @checked((int) ($entry->flightNumber ?? 0) === $i || ((int) ($entry->flightId ?? 0) === 0 && $i === 1))>
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary">Save Flights</button>
            </form>
        @endif
    </section>
</x-public-layout>
