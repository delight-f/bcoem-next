<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $table->tableNumber }}: {{ $table->tableName }} — Enter Scores</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p><a href="{{ route('admin.judging.scores.index') }}">&larr; All Scores</a></p>

        <form method="post" action="{{ route('admin.judging.scores.update', ['table' => $table->id]) }}">
            @csrf
            @method('PUT')

            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Entry</th>
                        <th>Judging</th>
                        <th>Style</th>
                        <th>Mini-BOS?</th>
                        <th>Score</th>
                        <th>Place</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            {{-- One posted slot per entry; the save wipes the table and re-inserts every row (process_judging_scores.inc.php). --}}
                            <input type="hidden" name="score_id[]" value="{{ $entry->id }}">
                            <input type="hidden" name="eid{{ $entry->id }}" value="{{ $entry->id }}">
                            <input type="hidden" name="bid{{ $entry->id }}" value="{{ $entry->bid }}">
                            <input type="hidden" name="scoreType{{ $entry->id }}" value="{{ $entry->scoreType }}">
                            <td>{{ str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ str_pad((string) $entry->brewJudgingNumber, 6, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $entry->styleDisplay }}</td>
                            <td>
                                <input type="checkbox" name="scoreMiniBOS{{ $entry->id }}" value="1"
                                       @checked($entry->score !== null && (int) $entry->score->scoreMiniBOS === 1)>
                            </td>
                            <td>
                                <input class="input input-bordered" type="number" step="0.01" min="0" max="50"
                                       name="scoreEntry{{ $entry->id }}"
                                       value="{{ old('scoreEntry'.$entry->id, $entry->score->scoreEntry ?? '') }}">
                            </td>
                            <td>
                                {{-- '5' is the storage code for HM; it must stay '5' so the public winners filter sees it. --}}
                                <select class="select select-bordered" name="scorePlace{{ $entry->id }}">
                                    <option value=""></option>
                                    @foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'] as $value => $label)
                                        <option value="{{ $value }}"
                                            @selected((string) old('scorePlace'.$entry->id, $entry->score->scorePlace ?? '') === (string) $value)>{{ $label }}</option>
                                    @endforeach
                                    <option value="5"
                                        @selected((string) old('scorePlace'.$entry->id, $entry->score->scorePlace ?? '') === '5')>Hon. Men.</option>
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Update Scores</button>
        </form>
    </section>
</x-public-layout>
