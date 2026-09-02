<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $variant === 'structured' ? 'Structured' : 'Full' }} Scoresheet</h1>

        @include('eval.partials.scoresheet-head', ['style' => $style])

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post"
              action="{{ $evaluation === null ? route('eval.process') : route('eval.process.update', ['evaluationId' => $evaluation->id]) }}">
            @csrf

            <input type="hidden" name="eid" value="{{ $entry->id }}">
            <input type="hidden" name="uid" value="{{ $entry->brewBrewerID }}">
            <input type="hidden" name="evalStyle" value="{{ $style->id ?? ($evaluation->evalStyle ?? '') }}">
            <input type="hidden" name="evalTable" value="{{ $evaluation->evalTable ?? '' }}">

            {{-- Variant sections: full = score + comments per section;
                 structured adds checklist ticks. Both share the overall
                 block and the final consensus score. --}}
            @include($variant === 'structured'
                ? 'eval.partials.structured-scoresheet'
                : 'eval.partials.full-scoresheet')

            <fieldset class="mb-4">
                <legend>Overall Impression ({{ $points['overall'] }} possible)</legend>
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <label class="form-label" for="evalOverallScore">Score</label>
                        <select class="form-select" id="evalOverallScore" name="evalOverallScore" required>
                            <option value=""></option>
                            @for ($i = $points['overall']; $i >= 1; $i--)
                                <option value="{{ $i }}" @selected(($evaluation?->evalOverallScore ?? 0) === $i)>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
                <label class="form-label" for="evalOverallComments">Comments</label>
                <textarea class="form-control" id="evalOverallComments" name="evalOverallComments" rows="4">{{ $evaluation?->evalOverallComments }}</textarea>

                @foreach ([
                    'evalStyleAccuracy' => 'Style Accuracy (1 = not classic, 5 = classic example)',
                    'evalTechMerit' => 'Technical Merit (1 = significant flaws, 5 = flawless)',
                    'evalIntangibles' => 'Intangibles (1 = lifeless, 5 = wonderful)',
                ] as $field => $label)
                    <div class="mt-2">
                        <span class="form-label d-block">{{ $label }}</span>
                        @for ($i = 5; $i >= 1; $i--)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="{{ $field }}"
                                       value="{{ $i }}" id="{{ $field.$i }}"
                                       @checked((int) ($evaluation?->{$field} ?? 0) === $i) required>
                                <label class="form-check-label" for="{{ $field.$i }}">{{ $i }}</label>
                            </div>
                        @endfor
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="mb-4">
                <legend>Consensus</legend>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="evalFinalScore">Final Score</label>
                        <input type="number" class="form-control" id="evalFinalScore" name="evalFinalScore"
                               min="0" max="50" required value="{{ $evaluation?->evalFinalScore }}">
                    </div>
                    <div class="col-md-3 form-check ms-2">
                        <input class="form-check-input" type="checkbox" value="1" id="evalMiniBOS" name="evalMiniBOS"
                               @checked((int) ($evaluation?->evalMiniBOS ?? 0) === 1)>
                        <label class="form-check-label" for="evalMiniBOS">Mini-BOS</label>
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary">Save Evaluation</button>
        </form>
    </section>
</x-public-layout>
