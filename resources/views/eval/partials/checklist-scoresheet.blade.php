{{-- checklist_scoresheet.eval.php port (jPrefsScoresheet=2, beer only):
     per section a set of factor radios (None/Low/Medium/High) posted as
     named scalars and stored "Label: Level" joined in eval{Section}Checklist,
     plus grouped descriptor checkboxes stored in eval{Section}ChecklistDesc. --}}
@php($levels = ['None', 'Low', 'Medium', 'High'])

@foreach ($checklist as $section => $groups)
    @php($key = ucfirst($section))
    @php($stored = (string) ($evaluation?->{'eval'.$key.'Checklist'} ?? ''))
    @php($storedDesc = (string) ($evaluation?->{'eval'.$key.'ChecklistDesc'} ?? ''))
    <fieldset class="mb-4">
        <legend>{{ ucfirst($section) }} ({{ $points[$section] }} possible)</legend>

        @foreach ($groups['factors'] as $factor => $field)
            <div class="row mb-1">
                <div class="col-md-3"><strong>{{ $factor }}</strong></div>
                <div class="col-md-9">
                    @foreach ($levels as $level)
                        @php($token = $factor.': '.$level)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="{{ $field }}" value="{{ $token }}"
                                   id="{{ $field }}-{{ $loop->index }}" required
                                   @checked($stored !== '' && str_contains($stored, $token))>
                            <label class="form-check-label" for="{{ $field }}-{{ $loop->index }}">{{ $level }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="row g-2 mb-2 mt-1">
            <div class="col-md-3">
                <label class="form-label" for="eval{{ $key }}Score">Score</label>
                <select class="form-select" id="eval{{ $key }}Score" name="eval{{ $key }}Score" required>
                    <option value=""></option>
                    @for ($i = $points[$section]; $i >= 1; $i--)
                        <option value="{{ $i }}" @selected((int) ($evaluation?->{'eval'.$key.'Score'} ?? 0) === $i)>{{ $i }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <label class="form-label" for="eval{{ $key }}Comments">Comments</label>
        <textarea class="form-control" id="eval{{ $key }}Comments" name="eval{{ $key }}Comments" rows="4">{{ $evaluation?->{'eval'.$key.'Comments'} }}</textarea>

        <p class="mt-2 mb-1"><strong>Descriptors</strong></p>
        @foreach ($groups['descriptors'] as $group => $items)
            @foreach ($items as $item)
                @php($token = $group.': '.$item)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="eval{{ $key }}ChecklistDesc[]"
                           value="{{ $token }}" id="desc-{{ $section }}-{{ $group }}-{{ $loop->index }}"
                           @checked($storedDesc !== '' && str_contains($storedDesc, $token))>
                    <label class="form-check-label" for="desc-{{ $section }}-{{ $group }}-{{ $loop->index }}">{{ $group }}: {{ $item }}</label>
                </div>
            @endforeach
        @endforeach
    </fieldset>
@endforeach

<fieldset class="mb-4">
    <legend>Flaws (mark all that apply)</legend>
    @foreach ($flaws as $flaw)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="evalFlaws[]" value="{{ $flaw }}"
                   id="flaw-{{ $loop->index }}"
                   @checked(str_contains((string) $evaluation?->evalFlaws, $flaw))>
            <label class="form-check-label" for="flaw-{{ $loop->index }}">{{ $flaw }}</label>
        </div>
    @endforeach
</fieldset>
