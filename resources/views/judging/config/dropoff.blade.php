<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4 mb-3">
        <h1>Drop-Off Locations</h1>

        <p class="d-print-none">
            <a class="btn btn-primary" href="{{ route('admin.judging.dropoff.create') }}">Add a Drop-Off Location</a>
        </p>

        @if ($locations->isEmpty())
            <p>No drop-off locations have been defined.</p>
        @else
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($locations as $location)
                        <tr>
                            <td>{{ $location->dropLocationName }}</td>
                            <td>{{ $location->dropLocationPhone }}</td>
                            <td>{{ $location->dropLocation }}</td>
                            <td>{{ $location->dropLocationNotes }}</td>
                            <td class="d-print-none">
                                @if (! empty($location->dropLocationWebsite) && preg_match('#^https?://#i', (string) $location->dropLocationWebsite))
                                    <a href="{{ $location->dropLocationWebsite }}" target="_blank" rel="noopener">Website</a>
                                @endif
                                <a href="{{ route('admin.judging.dropoff.edit', ['id' => $location->id]) }}">Edit</a>
                                <form method="post" action="{{ route('admin.judging.dropoff.destroy', ['id' => $location->id]) }}" class="d-inline" onsubmit="return confirm('Delete this location? This cannot be undone.')">
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
