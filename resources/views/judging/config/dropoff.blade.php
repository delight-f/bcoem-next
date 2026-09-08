<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Drop-Off Locations</h1>

        <p class="d-print-none">
            <a class="btn btn-primary" href="{{ route('admin.judging.dropoff.create') }}">Add a Drop-Off Location</a>
            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#dropoffHelpModal">Drop-Off Locations Help</button>
        </p>
        <div class="modal fade" id="dropoffHelpModal" tabindex="-1" role="dialog" aria-labelledby="dropoffHelpModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="dropoffHelpModalLabel">Drop-Off Locations Help</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Define one or more entry drop-off locations for participants to hand-deliver their entries. Drop-off locations are displayed on the Info with a link* to a map and driving directions.</p>
                        <p>A drop-off location may or may not be the same as the Shipping Location, which is defined in Competition Info. There is only one shipping location defined for the competition, whereas there can be multiple drop-off locations.</p>
                        <p>Select the &ldquo;Add a Drop-Off Location&rdquo; button to enter a drop-off location.</p>
                        <p class="small">* The mapping features will only work if an address is stored for the location.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        @if ($locations->isEmpty())
            <p>No drop-off locations have been specified.</p>
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
