{{-- nw_structured_cider_output.eval.php port: per-section rows read from
     the decoded JSON stored in eval{Section}Checklist. --}}
@php($nw = \App\Support\Eval\Descriptors::nwCider())
@php($appearance = json_decode((string) ($evaluation->evalAppearanceChecklist ?? ''), true) ?: [])
@php($aroma = json_decode((string) ($evaluation->evalAromaChecklist ?? ''), true) ?: [])
@php($flavor = json_decode((string) ($evaluation->evalFlavorChecklist ?? ''), true) ?: [])
@php($mouthfeel = json_decode((string) ($evaluation->evalMouthfeelChecklist ?? ''), true) ?: [])

@php($scaleLabel = fn (array $labels, mixed $value): string => $value === null || $value === ''
    ? '—'
    : (($labels[(int) $value] ?? '') !== '' ? $labels[(int) $value].' ('.(int) $value.')' : (string) $value))
@php($tick = fn (mixed $v): string => $v === '1' || $v === 1 ? '✔' : '✘')

<h5 class="mt-3">Appearance</h5>
<hr>
<div class="row mb-1"><div class="col-md-3"><strong>Colour</strong></div><div class="col-md-9">{{ $appearance['evalAppearanceColor'] ?? '—' }} <small>({{ $tick($appearance['evalAppearanceColorInappr'] ?? null) }})</small></div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Clarity</strong></div><div class="col-md-9">{{ $scaleLabel($nw['clarity'], $appearance['evalAppearanceClarity'] ?? null) }} <small>({{ $tick($appearance['evalAppearanceClarityInappr'] ?? null) }})</small></div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Carbonation</strong></div><div class="col-md-9">{{ $scaleLabel($nw['carb'], $appearance['evalAppearanceCarb'] ?? null) }} <small>({{ $tick($appearance['evalAppearanceCarbInappr'] ?? null) }})</small></div></div>

<h5 class="mt-3">Aroma</h5>
<hr>
<div class="row mb-1"><div class="col-md-3"><strong>Characteristics</strong></div><div class="col-md-9">{{ $aroma['evalAromaCharacteristics'] ?? '—' }}</div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Intensity</strong></div><div class="col-md-9">{{ $scaleLabel($nw['intensity'], $aroma['evalAromaIntensity'] ?? null) }} <small>({{ $tick($aroma['evalAromaIntensityInappr'] ?? null) }})</small></div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Quality</strong></div><div class="col-md-9">{{ $scaleLabel($nw['quality'], $aroma['evalAromaQuality'] ?? null) }} <small>({{ $tick($aroma['evalAromaQualityInappr'] ?? null) }})</small></div></div>

<h5 class="mt-3">Flavour</h5>
<hr>
<div class="row mb-1"><div class="col-md-3"><strong>Characteristics</strong></div><div class="col-md-9">{{ $flavor['evalFlavorCharacteristics'] ?? '—' }}</div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Intensity</strong></div><div class="col-md-9">{{ $scaleLabel($nw['intensity'], $flavor['evalFlavorIntensity'] ?? null) }} <small>({{ $tick($flavor['evalFlavorIntensityInappr'] ?? null) }})</small></div></div>
<div class="row mb-1"><div class="col-md-3"><strong>Quality</strong></div><div class="col-md-9">{{ $scaleLabel($nw['quality'], $flavor['evalFlavorQuality'] ?? null) }} <small>({{ $tick($flavor['evalFlavorQualityInappr'] ?? null) }})</small></div></div>

<h5 class="mt-3">Mouthfeel</h5>
<hr>
@foreach ([
    'evalMouthfeelBody' => ['Body', 'body'],
    'evalMouthfeelSweetness' => ['Sweetness', 'sweetness'],
    'evalMouthfeelAcidity' => ['Acidity', 'acidity'],
    'evalMouthfeelTanninBitter' => ['Tannin (Bitter)', 'tannin'],
    'evalMouthfeelTanninAstringency' => ['Tannin (Astringency)', 'tannin'],
    'evalMouthfeelBalance' => ['Balance', 'balance'],
    'evalMouthfeelLength' => ['Length', 'length'],
] as $field => [$label, $scaleKey])
    <div class="row mb-1">
        <div class="col-md-3"><strong>{{ $label }}</strong></div>
        <div class="col-md-9">
            {{ $scaleLabel($nw[$scaleKey], $mouthfeel[$field] ?? null) }}
            <small>({{ $tick($mouthfeel[$field.'Inappr'] ?? null) }})</small>
        </div>
    </div>
@endforeach

@if (! empty($evaluation->evalOverallComments))
    <h5 class="mt-3">Overall Impression</h5>
    <hr>
    <p>{{ $evaluation->evalOverallComments }}</p>
@endif
