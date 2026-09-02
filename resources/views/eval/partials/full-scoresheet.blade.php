{{-- full_scoresheet.eval.php port: score + comments per section.
     Mouthfeel is beer-only in legacy (cider/mead sheets omit it). --}}
@php($sections = [
    'aroma' => 'Aroma',
    'appearance' => 'Appearance',
    'flavor' => 'Flavor',
])
@if ($styleType === 1)
    @php($sections['mouthfeel'] = 'Mouthfeel')
@endif

@foreach ($sections as $section => $label)
    <fieldset class="mb-4">
        <legend>{{ $label }} ({{ $points[$section] }} possible)</legend>
        <div class="row g-2 mb-2">
            <div class="col-md-3">
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
