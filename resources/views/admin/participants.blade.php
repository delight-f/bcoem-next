<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Participants</h1>

        @if (request('msg') === 'deleted')
            <div class="alert alert-success">Participant deleted (all entries, scores, assignments and staff roles removed).</div>
        @elseif (request('msg') === 'updated')
            <div class="alert alert-success">Participant updated.</div>
        @elseif (request('msg') === 'self')
            <div class="alert alert-warning">Silly, you cannot delete yourself.</div>
        @elseif (request('msg') === 'not-found')
            <div class="alert alert-warning">Participant not found.</div>
        @endif

        {{-- Legacy filters: default / judges / stewards / with_entries --}}
        <ul class="nav nav-pills mb-4">
            @foreach ([['default', 'All'], ['judges', 'Available Judges'], ['stewards', 'Available Stewards'], ['with_entries', 'Participants with Entries']] as [$f, $label])
                <li class="nav-item">
                    <a class="nav-link {{ $filter === $f ? 'active' : '' }}"
                       href="{{ url('/backoffice/participants', array_filter(['filter' => $f !== 'default' ? $f : null, 'q' => $q !== '' ? $q : null])) }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>

        <form method="get" action="{{ url('/backoffice/participants') }}" class="row row-cols-auto g-2 mb-4">
            @if ($filter !== 'default')
                <input type="hidden" name="filter" value="{{ $filter }}">
            @endif
            <input type="hidden" name="q" value="{{ $q }}">
            <input class="input input-bordered" name="q" placeholder="Search participants…"
                   value="{{ $q }}">
            <button type="submit" class="btn btn-outline btn-secondary">Search</button>
</form>
        @if ($participants->isEmpty())
            <p>No participants found.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>User Level</th>
                        @if ($filter === 'judges' || $filter === 'stewards')
                            <th>Location(s) Available</th>
                        @else
                            <th>Club</th>
                        @endif
                        @if ($filter === 'default')
                            <th>Steward?</th>
                            <th>Judge?</th>
                        @endif
                        @if ($filter === 'with_entries')
                            <th>Entries</th>
                        @else
                            <th>Assigned As</th>
                        @endif
                        @if ($filter === 'judges')
                            <th>ID</th>
                            <th>Rank</th>
                        @endif
                        <th class="print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        <tr>
                            <td>{{ $p->brewerFirstName }} {{ $p->brewerLastName }}</td>
                            <td>{{ $p->userLevel }}</td>
                            @if ($filter === 'judges' || $filter === 'stewards')
                                <td>{{ $locationDisplay($filter === 'judges' ? $p->brewerJudgeLocation : $p->brewerStewardLocation) }}</td>
                            @else
                                <td>{{ $p->brewerClubs }}</td>
                            @endif
                            @if ($filter === 'default')
                                <td>@if ($p->brewerSteward === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                                <td>@if ($p->brewerJudge === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                            @endif
                            @if ($filter === 'with_entries')
                                <td>{{ $entryCounts[$p->uid] ?? 0 }}</td>
                            @else
                                <td>{{ $p->brewerAssignment }}</td>
                            @endif
                            @if ($filter === 'judges')
                                <td>{{ $p->brewerJudgeID }}</td>
                                <td>{{ $p->brewerJudgeRank }}</td>
                            @endif
                            <td class="print:hidden">
                                <a href="{{ route('backoffice.participants.edit', ['uid' => $p->uid]) }}">Edit</a>
                                <a href="{{ url('/backoffice/entries', ['bid' => $p->uid]) }}">Entries</a>
                                <form method="post" action="{{ route('backoffice.participants.destroy', ['uid' => $p->uid]) }}" class="inline"
                                      onsubmit="return confirm('Delete the participant account for {{ $p->brewerFirstName }} {{ $p->brewerLastName }}? ALL entries for this participant WILL BE DELETED as well. This cannot be undone.');">
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
