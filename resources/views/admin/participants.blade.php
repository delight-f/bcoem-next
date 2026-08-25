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
                        <th>Club</th>
                        <th>Steward?</th>
                        <th>Judge?</th>
                        <th>@if ($filter === 'with_entries') Entries @else Assigned As @endif</th>
                        <th class="print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        <tr>
                            <td>{{ $p->brewerFirstName }} {{ $p->brewerLastName }}</td>
                            <td>{{ $p->userLevel }}</td>
                            <td>{{ $p->brewerClubs }}</td>
                            <td>@if ($p->brewerSteward === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                            <td>@if ($p->brewerJudge === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                            <td>
                                @if ($filter === 'with_entries')
                                    {{ $entryCounts[$p->uid] ?? 0 }}
                                @else
                                    {{ $p->brewerAssignment }}
                                @endif
                            </td>
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
