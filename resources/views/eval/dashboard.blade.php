<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Judging Dashboard</h1>

        @if ((int) request('msg') === 3)
            <div class="alert alert-success">Evaluation saved.</div>
        @elseif ((int) request('msg') === 2)
            <div class="alert alert-success">Evaluation updated.</div>
        @endif

        @if (request('msg') === 'closed')
            <div class="alert alert-warning">Judging is closed for this session.</div>
        @endif

        {{-- warnings.eval.php port: countdown timers to judging close --}}
        @include('eval.partials.warnings')

        <p class="fs-5 fw-light">Evaluations are not official until an administrator imports
        matching consensus scores entered by two or more judges.</p>

        @if ($admin !== null)
            {{-- Admin panel: judging_dashboard/judging_admin folded in
                 (ledger port verdict). Import button posts to the
                 consensus importer; singles cannot be imported. --}}
            <div class="card border-secondary mb-6">
                <div class="card-header"><strong>Admin — Consensus Scoring</strong></div>
                <div class="card-body">
                    <p>
                        {{ $admin['totalEvaluations'] }} evaluated entries,
                        {{ $admin['consensusReady'] }} with two or more evaluations,
                        {{ $admin['officialScores'] }} official score rows.
                    </p>

                    @if ($admin['singles'] !== [])
                        <div class="alert alert-warning">
                            Entries with a single evaluation (not importable):
                            {{ implode(', ', $admin['singles']) }}
                        </div>
                    @endif

                    <form method="post" action="{{ route('eval.import.run') }}" id="import-scores-form">
                        @csrf
                        <button type="submit" class="btn btn-success">Import Score Data</button>
                    </form>
                </div>
            </div>
        @endif

        @if ($tables === [])
            <p>You have no table assignments yet. Contact the competition organizer
            if you expected an assignment.</p>
        @else
            @foreach ($tables as $row)
                <h2 class="mt-6">Table {{ $row['table']->tableNumber }} — {{ $row['table']->tableName }}</h2>
                @if ($row['entries'] === [])
                    <p>No entries flighted to this table.</p>
                @else
                    <table class="table table-striped table-sm align-middle">
                        <thead>
                            <tr><th>#</th><th>Entry</th><th>Style</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($row['entries'] as $entry)
                                <tr>
                                    <td>{{ $entry->id }}</td>
                                    <td>{{ $entry->brewName }}</td>
                                    <td>{{ $entry->brewCategorySort }}{{ $entry->brewSubCategory }} {{ $entry->brewStyle }}</td>
                                    <td>
                                        <a class="btn btn-sm btn-primary"
                                           href="{{ route('eval.scoresheet', ['entryId' => $entry->id, 'archive' => $archive ?: null]) }}">
                                            Evaluate ({{ $variant }})
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('eval.output', ['entryId' => $entry->id, 'archive' => $archive ?: null]) }}">
                                            View output
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        @endif
    </section>
</x-public-layout>
