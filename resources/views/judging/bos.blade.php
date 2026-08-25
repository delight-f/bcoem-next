<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4 mb-3">
        <h1>Best of Show (BOS) Entries and Places</h1>

        <p><a href="{{ route('admin.judging.scores.index') }}">&larr; All Scores</a></p>

        @foreach ($groups as $group)
            @php($rows = $group->rows)
            @php($type = $group->type)
            <h3>BOS Entries and Places for {{ $type->styleTypeName }}</h3>
            @if (count($rows) === 0)
                <p>No entries are eligible.</p>
            @else
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Entry</th>
                            <th>Judging</th>
                            <th>Table</th>
                            <th>Style</th>
                            <th>Table Score</th>
                            <th>Table Place</th>
                            <th>BOS Place</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ str_pad((string) $row->eid, 6, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $row->brewJudgingNumber ?? '' }}</td>
                                <td>{{ $row->tableNumber }}: {{ $row->tableName }}</td>
                                <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewName }}</td>
                                <td>{{ $row->scoreEntry }}</td>
                                {{-- BOS surface treats '5' and literal 'HM' as equivalent (scoring ledger). --}}
                                <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                                <td>{{ $row->bosPlace === null ? '' : \App\Support\Results\Place::label($row->bosPlace) }}</td>
                                <td><a href="{{ route('admin.judging.bos.edit', ['styleType' => $type->id]) }}">Enter Places</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </section>
</x-public-layout>
