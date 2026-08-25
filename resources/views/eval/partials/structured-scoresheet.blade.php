{{-- structured_scoresheet.eval.php port: per-section score + checklist
     ticks + comments, plus a flaws checklist. Tick labels post as
     arrays and are stored comma-joined in the eval*Checklist columns. --}}
@php($sections = [
    'aroma' => $styleType === 1 ? 'Aroma' : 'Bouquet/Aroma',
    'appearance' => 'Appearance',
    'flavor' => 'Flavor',
])
@if ($styleType === 1)
    @php($sections['mouthfeel'] = 'Mouthfeel')
@endif

@foreach ($sections as $section => $label)
    <fieldset class="mb-3">
        <legend>{{ $label }} ({{ $points[$section] }} possible)</legend>

        @forelse ($ticks[$section] ?? [] as $tickLabel => $field)
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="{{ $section }}Ticks[]"
                       value="{{ $tickLabel }}" id="{{ $field }}"
                       @checked(str_contains((string) $evaluation?->{'eval'.ucfirst($section).'Checklist'}, $tickLabel))>
                <label class="form-check-label" for="{{ $field }}">{{ $tickLabel }}</label>
            </div>
        @empty
        @endforelse

        <div class="row g-2 mb-2 mt-1">
            <div class="col-sm-3">
                <label class="form-label" for="eval{{ ucfirst($section) }}Score">Score</label>
                <select class="form-select" id="eval{{ ucfirst($section) }}Score"
                        name="eval{{ ucfirst($section) }}Score" required>
                    <option value=""></option>
                    @for ($i = $points[$section]; $i >= 1; $i--)
                        <option value="{{ $i }}"
                            @selected((int) ($evaluation?->{'eval'.ucfirst($section).'Score'} ?? 0) === $i)>{{ $i }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <label class="form-label" for="eval{{ ucfirst($section) }}Comments">Comments</label>
        <textarea class="form-control" id="eval{{ ucfirst($section) }}Comments"
                  name="eval{{ ucfirst($section) }}Comments" rows="4">{{ $evaluation?->{'eval'.ucfirst($section).'Comments'} }}</textarea>
    </fieldset>
@endforeach

<fieldset class="mb-3">
    <legend>Flaws (mark all that apply)</legend>
    @foreach ($flaws as $flaw)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="flaws[]" value="{{ $flaw }}"
                   id="flaw-{{ $loop->index }}"
                   @checked(str_contains((string) $evaluation?->evalFlaws, $flaw))>
            <label class="form-check-label" for="flaw-{{ $loop->index }}">{{ $flaw }}</label>
        </div>
    @endforeach
</fieldset>

@if ($descriptors !== [])
    <fieldset class="mb-3">
        <legend>Descriptors (mark all that apply)</legend>
        @foreach ($descriptors as $descriptor => $description)
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="evalDescriptors[]"
                       value="{{ $descriptor }}" id="descr-{{ $loop->index }}"
                       @checked(str_contains((string) $evaluation?->evalDescriptors, $descriptor))>
                <label class="form-check-label" for="descr-{{ $loop->index }}">
                    <strong>{{ $descriptor }}</strong> — {{ $description }}
                </label>
            </div>
        @endforeach
    </fieldset>
@endif
