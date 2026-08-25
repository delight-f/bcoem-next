<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4 mb-3">
        <h1>Judging Tables</h1>

        <p class="d-print-none">
            <a class="btn btn-primary" href="{{ route('admin.judging.tables.create') }}">Add a Table</a>
        </p>

        @if ($tables->isEmpty())
            <p>No tables have been defined.</p>
        @else
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Styles</th>
                        <th>Location</th>
                        <th>Entry Limit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tables as $table)
                        <tr>
                            <td>{{ $table->tableNumber }}</td>
                            <td>{{ $table->tableName }}</td>
                            <td>{{ $table->tableStyles }}</td>
                            <td>{{ $table->tableLocation }}</td>
                            <td>{{ $table->tableEntryLimit ?? '' }}</td>
                            <td class="d-print-none">
                                <a href="{{ route('admin.judging.tables.edit', ['id' => $table->id]) }}">Edit</a>
                                <form method="post" action="{{ route('admin.judging.tables.destroy', ['id' => $table->id]) }}" class="d-inline" onsubmit="return confirm('Delete this table? All of its scores and flights are removed. This cannot be undone.')">
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
