{{-- checklist_output.eval.php port: factor list, descriptor list and
     comments per section, then the overall impression + verdicts. --}}
@php($sections = [
    'Aroma' => 'evalAroma',
    'Appearance' => 'evalAppearance',
    'Flavor' => 'evalFlavor',
    'Mouthfeel' => 'evalMouthfeel',
])

@foreach ($sections as $label => $prefix)
    <h5 class="mt-3">{{ $label }}<span class="float-end">{{ $evaluation->{$prefix.'Score'} }} <small>/ {{ $points[strtolower($label)] ?? '' }}</small></span></h5>
    <hr>
    @if (! empty($evaluation->{$prefix.'Checklist'}))
        <p>{{ $evaluation->{$prefix.'Checklist'} }}</p>
    @endif
    @if (! empty($evaluation->{$prefix.'ChecklistDesc'}))
        <p>{{ $evaluation->{$prefix.'ChecklistDesc'} }}</p>
    @endif
    @if (! empty($evaluation->{$prefix.'Comments'}))
        <p>{{ $evaluation->{$prefix.'Comments'} }}</p>
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
