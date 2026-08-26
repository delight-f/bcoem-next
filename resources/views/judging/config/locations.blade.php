<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $nonJudging ? 'Non-Judging Sessions' : 'Judging Sessions' }}</h1>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <p class="print:hidden">
            <a class="btn btn-primary" href="{{ route($nonJudging ? 'admin.judging.non_judging.create' : 'admin.judging.locations.create') }}">Add a {{ $nonJudging ? 'Non-Judging Session' : 'Judging Session' }}</a>
        </p>

        @if ($nonJudging)
            {{-- non-judging_locations.admin.php:158-161 — definition + staff
                 availability copy. --}}
            <p>Non-judging sessions are scheduled periods of time that necessitate staffing, such as entry pick-up, entry sorting, judge check-in, awards preparation, etc.</p>
            <p>Anyone with an account who inicates they are willing to serve as staff will also have the option to indictate their availability for each non-judging session.</p>
        @endif

        @if ($locations->isEmpty())
            <p>No {{ $nonJudging ? 'non-judging sessions' : 'judging sessions' }} have been defined.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        @if (! $nonJudging)
                            <th>Type</th>
                        @endif
                        <th>Start Date/Time</th>
                        @if (! $nonJudging)
                            <th>End Date/Time</th>
                        @endif
                        <th>{{ $nonJudging ? 'Address' : 'Address or Entry Distribution Info' }}</th>
                        @if (! $nonJudging)
                            <th>Rounds</th>
                            <th>Notes</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($locations as $location)
                        <tr>
                            <td>{{ $location->judgingLocName }}</td>
                            @if (! $nonJudging)
                                <td>{{ ((int) $location->judgingLocType) === 1 ? 'Distributed' : 'Traditional' }}</td>
                            @endif
                            <td>{{ \App\Support\Tenant\DateFmt::dateTime($location->judgingDate, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', withZone: false) ?? '' }}</td>
                            @if (! $nonJudging)
                                <td>{{ isset($location->judgingDateEnd) && $location->judgingDateEnd !== null ? (\App\Support\Tenant\DateFmt::dateTime($location->judgingDateEnd, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', withZone: false) ?? '') : 'N/A' }}</td>
                            @endif
                            <td>{{ $location->judgingLocation }}</td>
                            @if (! $nonJudging)
                                <td>{{ $location->judgingRounds }}</td>
                                <td>{{ $location->judgingLocNotes }}</td>
                            @endif
                            <td class="print:hidden">
                                <a href="{{ route($nonJudging ? 'admin.judging.non_judging.edit' : 'admin.judging.locations.edit', ['id' => $location->id]) }}">Edit</a>
                                <form method="post" action="{{ route($nonJudging ? 'admin.judging.non_judging.destroy' : 'admin.judging.locations.destroy', ['id' => $location->id]) }}" class="inline" onsubmit="return confirm('Delete this session? This cannot be undone.')">
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
