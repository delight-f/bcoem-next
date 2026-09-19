<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Scoresheet Output</h1>

        @include('eval.partials.scoresheet-head', ['style' => $style])

        @if ($disagree)
            <div class="alert alert-warning">
                The judges' final scores differ by more than the configured maximum
                difference for consensus scores ({{ $dispersion }}).
            </div>
        @endif

        @if ($evaluations === [])
            <p>No evaluations recorded for this entry yet.</p>
        @endif

        @foreach ($evaluations as $evaluation)
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between">
                    <span>Evaluation #{{ $evaluation->id }} by judge uid {{ $evaluation->evalJudgeInfo }}</span>
                    <span>
                        Final score: <strong>{{ $evaluation->evalFinalScore }}</strong>
                        @if ((int) ($evaluation->evalMiniBOS ?? 0) === 1)
                            &middot; Mini-BOS
                        @endif
                    </span>
                </div>
                <div class="card-body">
                    @if ((int) $evaluation->evalScoresheet === 2)
                        @include('eval.partials.checklist-output')
                    @elseif ((int) $evaluation->evalScoresheet === 3)
                        @include('eval.partials.structured-output')
                    @elseif ((int) $evaluation->evalScoresheet === 4)
                        @include('eval.partials.nw-cider-output')
                    @else
                    <table class="table table-sm mb-4">
                        <thead><tr><th>Section</th><th>Score</th><th>Max</th></tr></thead>
                        <tbody>
                            @foreach (['aroma', 'appearance', 'flavor', 'mouthfeel'] as $section)
                                @php($score = $evaluation?->{'eval'.ucfirst($section).'Score'})
                                @if ($score !== null)
                                    <tr>
                                        <td>{{ ucfirst($section) }}</td>
                                        <td>{{ $score }}</td>
                                        <td>{{ $points[$section] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                            <tr><td>Overall</td><td>{{ $evaluation->evalOverallScore }}</td><td>{{ $points['overall'] }}</td></tr>
                        </tbody>
                    </table>

                    @foreach ([
                        'aroma' => 'Aroma',
                        'appearance' => 'Appearance',
                        'flavor' => 'Flavor',
                        'mouthfeel' => 'Mouthfeel',
                        'overall' => 'Overall Impression',
                    ] as $section => $label)
                        @php($comments = $evaluation?->{'eval'.ucfirst($section).'Comments'})
                        @if (! empty($comments))
                            <h3 class="h6">{{ $label }}</h3>
                            <p>{{ $comments }}</p>
                        @endif
                    @endforeach

                    @if (! empty($evaluation->evalFlaws))
                        <p class="fs-6"><strong>Flaws:</strong> {{ $evaluation->evalFlaws }}</p>
                    @endif
                    @if (! empty($evaluation->evalDescriptors))
                        <p class="fs-6"><strong>Descriptors:</strong> {{ $evaluation->evalDescriptors }}</p>
                    @endif
                    @endif
                </div>
            </div>
        @endforeach

        <a class="btn btn-outline-secondary" href="{{ route('eval.dashboard') }}">Back to dashboard</a>
    </section>
</x-public-layout>
