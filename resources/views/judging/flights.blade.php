<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4 mb-3">
        <h1>Define/Edit Flights</h1>

        @if ($tables->isEmpty())
            <p>No tables have been defined. Tables must be defined before flights can be assigned to them.</p>
        @else
            <table class="table table-striped table-bordered">
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
