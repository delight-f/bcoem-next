<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Scores</h1>

        @if ($scores->isEmpty())
            <p>No scores have been entered. Use the tables screen to define tables, then add scores per table.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Entry</th>
                        <th>Judging</th>
                        <th>Table</th>
                        <th>Entry Name</th>
                        <th>Score</th>
                        <th>Place</th>
                        <th>Mini-BOS?</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scores as $score)
                        <tr>
                            <td>{{ str_pad((string) $score->eid, 6, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $score->brewJudgingNumber ?? '' }}</td>
                            <td>{{ $score->tableNumber }}: {{ $score->tableName }}</td>
                            <td>{{ $score->brewName }}</td>
                            <td>{{ $score->scoreEntry }}</td>
                            {{-- '5' is the stored HM code (scoring ledger #1). --}}
                            <td>{{ \App\Support\Results\Place::label($score->scorePlace) }}</td>
                            <td>@if ((int) $score->scoreMiniBOS === 1)<span class="text-success">&#10003;</span>@endif</td>
                            <td class="print:hidden">
                                <a href="{{ route('admin.judging.scores.edit', ['table' => $score->scoreTable]) }}">Edit</a>
                                <form method="post" action="{{ route('admin.judging.scores.destroy', ['id' => $score->id]) }}" class="inline"
                                      onsubmit="return confirm('Delete this score? This cannot be undone.');">
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
