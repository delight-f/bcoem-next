<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Define/Edit Flights</h1>

        {{-- Legacy control row (judging_flights.admin.php:102-141). --}}
        <div class="mb-4 flex flex-wrap gap-2 items-start">
            <a class="btn btn-secondary" href="{{ url('/admin/judging/tables') }}"><span class="fa fa-arrow-circle-left"></span> All Tables</a>
            <a class="btn btn-secondary" href="{{ url('/admin/judging/flights/rounds') }}"><span class="fa fa-check-circle"></span> Assign Flights to Rounds</a>

            @if ($tables->isNotEmpty())
                <div class="dropdown">
                    <button type="button" class="btn btn-secondary dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                        Choose a Table <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($tables as $t)
                            <li class="small"><a class="dropdown-item"
                                 href="{{ route('admin.judging.flights.show', ['id' => $t->id]) }}?filter=define">Table {{ $t->tableNumber }}: {{ $t->tableName }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if ($tables->isEmpty())
            <p>No tables have been defined. Tables must be defined before flights can be assigned to them.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Proposed Flights</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tables as $table)
                        <tr>
                            <td>{{ $table->tableNumber }}</td>
                            <td>{{ $table->tableName }}</td>
                            <td>{{ $counts[$table->id] }}</td>
                            <td><a href="{{ route('admin.judging.flights.show', ['id' => $table->id]) }}">Assign entries to flights</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
