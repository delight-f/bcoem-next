<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }} Scores</h1>

        {{-- Legacy control set: admin/judging_scores.admin.php (dbTable=default, action=default). --}}
        <div class="bcoem-admin-element print:hidden mb-3">
            <div class="btn-group" role="group">
                <a class="btn btn-secondary" href="{{ route('admin.judging.tables.index') }}"><span class="fa fa-arrow-circle-left"></span> All Tables</a>
            </div>
            <div class="btn-group" role="group">
                <a class="btn btn-secondary" href="{{ route('admin.judging.bos.index') }}"><span class="fa fa-eye"></span> View BOS Entries and Places</a>
            </div>

            @if (count($tables) > 0)
                {{-- Position 2: Enter/Edit Dropdown Button Group. Legacy links
                    action=add vs action=edit per table; the port serves both
                    with one grid route (ScoreController::edit). --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-plus-circle"></span> Add or Update Scores For...
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($tables as $table)
                            @php($hasScores = in_array((int) $table->id, $scoredTableIds ?? [], true))
                            <li><a class="dropdown-item" href="{{ url('/admin/judging/scores') }}?action={{ $hasScores ? 'edit' : 'add' }}&id={{ $table->id }}">Table {{ $table->tableNumber }}: {{ $table->tableName }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($bosTypes) > 0)
                {{-- Position 4: Print Button Dropdown Group (id == "default").
                    Legacy items open output.inc.php?section=pullsheets&go=judging_scores_bos&id=<styleType>
                    (judging_scores.admin.php); the port pullsheet output
                    dispatches the same shape. --}}
                <div class="btn-group d-none d-lg-block print:hidden" role="group">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-print"></span> Print...
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($bosTypes as $type)
                            <li><a data-fancybox data-type="iframe" class="dropdown-item modal-window-link hide-loader menuItem" href="{{ route('outputs.pullsheets', ['go' => 'judging_scores_bos', 'id' => $type->id]) }}" title="Print the {{ $type->styleTypeName }} BOS Pullsheet">BOS Pullsheet for {{ $type->styleTypeName }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($evalOn)
                {{-- prefsEval == 1: legacy renders the evaluations jump button
                    plus its import UI; the port's import lives on /eval. --}}
                <div class="btn-group print:hidden" role="group">
                    <a class="btn btn-secondary" href="{{ route('eval.dashboard') }}"><span class="fa fa-chevron-circle-left"></span> Admin: Evaluations</a>
                </div>
            @endif
        </div>

        <p id="score-entered-status-default">Scores have been entered for {{ $scoresEntered }} of {{ $paidReceived }} entries marked as paid and received.</p>

        @if ($scores->isEmpty())
            <p id="no-scores-entered">No scores have been entered. If tables have been defined, use the &ldquo;Add or Update Scores for...&rdquo; menu above to add scores.</p>
        @else
            <table class="table table-striped table-bordered">
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
