{{-- structured_scoresheet.eval.php read-back: per-section score, the stored
     comma-joined checklist ticks and the section comments, then the overall
     impression + verdicts (mirrors checklist-output.blade.php). --}}

@foreach (['Aroma', 'Appearance', 'Flavor', 'Mouthfeel'] as $section)
    @php($score = $evaluation->{'eval'.$section.'Score'})
    @if ($score !== null)
        <h5 class="mt-3">{{ $section }}<span class="float-end">{{ $score }} <small>/ {{ $points[strtolower($section)] ?? '' }}</small></span></h5>
        <hr>
        @if (! empty($evaluation->{'eval'.$section.'Checklist'}))
            <p>{{ $evaluation->{'eval'.$section.'Checklist'} }}</p>
        @endif
        @if (! empty($evaluation->{'eval'.$section.'Comments'}))
            <p>{{ $evaluation->{'eval'.$section.'Comments'} }}</p>
        @endif
    @endif
@endforeach

<h5 class="mt-3">Overall Impression<span class="float-end">{{ $evaluation->evalOverallScore }} <small>/ {{ $points['overall'] }}</small></span></h5>
<hr>
@if (! empty($evaluation->evalOverallComments))
    <p>{{ $evaluation->evalOverallComments }}</p>
@endif
<ul class="list-inline mb-2">
    @foreach (['evalStyleAccuracy' => 'Style accuracy', 'evalTechMerit' => 'Technical merit', 'evalIntangibles' => 'Intangibles'] as $field => $label)
        <li class="list-inline-item"><strong>{{ $label }}:</strong> {{ $evaluation->{$field} ?? '—' }}/5</li>
    @endforeach
</ul>
@if (! empty($evaluation->evalFlaws))
    <p><strong>Flaws:</strong> {{ $evaluation->evalFlaws }}</p>
@endif
@if (! empty($evaluation->evalDescriptors))
    <p><strong>Descriptors:</strong> {{ $evaluation->evalDescriptors }}</p>
@endif
