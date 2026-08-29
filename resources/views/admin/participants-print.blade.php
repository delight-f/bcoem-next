{{-- Legacy ?section=admin&go=participants&action=print (participants
     .admin.php:52-160): printable filtered participant list — same
     rows as the manage screen with a print column set (Info instead of
     Actions; location-availability for judges/stewards). --}}
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4">
        <h2>{{ $ctx->contestStr('contestName') }} — Participants</h2>
        <p class="lead">
            @if ($filter === 'judges') Available Judges
            @elseif ($filter === 'stewards') Available Stewards
            @elseif ($filter === 'with_entries') Participants with Entries
            @else All Participants
            @endif
        </p>

        @if ($participants->isEmpty())
            <p>No participants match.</p>
        @else
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Info</th>
                        @if (in_array($filter, ['judges', 'stewards'], true))
                            <th>Location(s) Available</th>
                        @endif
                        @if ($filter === 'judges')
                            <th>Judge ID</th>
                            <th>Judge Rank</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        <tr>
                            <td>{{ $p->brewerLastName }}, {{ $p->brewerFirstName }}</td>
                            <td>
                                @if ((($filter === 'judges') && $p->brewerJudge === 'Y') || (($filter === 'stewards') && $p->brewerSteward === 'Y'))
                                    <span class="text-success">Available</span>
                                @else
                                    {{ $p->brewerClubs }}
                                @endif
                            </td>
                            @if (in_array($filter, ['judges', 'stewards'], true))
                                <td>{{ $locationDisplay[$p->uid] ?? '' }}</td>
                            @endif
                            @if ($filter === 'judges')
                                <td>{{ $p->brewerJudgeID }}</td>
                                <td>{{ $p->brewerJudgeRank }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
