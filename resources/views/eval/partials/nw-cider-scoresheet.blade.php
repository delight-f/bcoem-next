{{-- nw_structured_cider.eval.php port (jPrefsScoresheet=4, cider entries):
     colour radios, clarity/carbonation scales, aroma and flavour
     characteristics with intensity/quality scales, and mouthfeel scales.
     Legacy used bootstrap-slider; the port ships no slider JS, so these are
     plain selects. Values stay 0-4 and are stored json_encode'd per section
     in eval{Section}Checklist (legacy reads them back with json_decode).

     ponytail: slider→select; add a slider only if the UX is actually missed. --}}
@php($appearance = json_decode((string) ($evaluation?->evalAppearanceChecklist ?? ''), true) ?: [])
@php($aroma = json_decode((string) ($evaluation?->evalAromaChecklist ?? ''), true) ?: [])
@php($flavor = json_decode((string) ($evaluation?->evalFlavorChecklist ?? ''), true) ?: [])
@php($mouthfeel = json_decode((string) ($evaluation?->evalMouthfeelChecklist ?? ''), true) ?: [])

@php($scale = function (string $name, array $labels, mixed $selected): void {
    echo '<select class="form-select form-select-sm" name="'.e($name).'">';
    echo '<option value=""></option>';
    foreach ($labels as $i => $label) {
        $text = $label !== '' ? $label.' ('.$i.')' : '— ('.$i.')';
        echo '<option value="'.$i.'"'.((string) $selected === (string) $i ? ' selected' : '').'>'.e($text).'</option>';
    }
    echo '</select>';
})
@php($inappr = function (string $name, bool $checked): void {
    echo '<div class="form-check form-check-inline align-middle">';
    echo '<input class="form-check-input" type="checkbox" name="'.e($name).'" value="1" id="'.e($name).'"'.($checked ? ' checked' : '').'>';
    echo '<label class="form-check-label" for="'.e($name).'">Inappropriate</label></div>';
})

<fieldset class="mb-4">
    <legend>Appearance</legend>

    <div class="row mb-1">
        <div class="col-md-3"><strong>Colour</strong></div>
        <div class="col-md-9">
            @php($storedColor = (string) ($appearance['evalAppearanceColor'] ?? ''))
            @foreach ($nwCider['colors'] as $i => $color)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="evalAppearanceColorChoice"
                           value="{{ $color }}" id="color-{{ $i }}"
                           @checked($storedColor === $color)>
                    <label class="form-check-label" for="color-{{ $i }}">{{ $color }}</label>
                </div>
            @endforeach
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="evalAppearanceColorChoice" value="999"
                       id="color-other" @checked($storedColor !== '' && ! in_array($storedColor, $nwCider['colors'], true))>
                <label class="form-check-label" for="color-other">Other</label>
            </div>
            <input class="form-control form-control-sm d-inline-block w-auto" type="text" name="evalAppearanceColorOther"
                   maxlength="50" placeholder="Other" value="{{ $appearance['evalAppearanceColorOther'] ?? '' }}">
            {{ $inappr('evalAppearanceColorInappr', (bool) ($appearance['evalAppearanceColorInappr'] ?? false)) }}
        </div>
    </div>

    <div class="row mb-1">
        <div class="col-md-3"><strong>Clarity</strong></div>
        <div class="col-md-9">
            {{ $scale('evalAppearanceClarity', $nwCider['clarity'], $appearance['evalAppearanceClarity'] ?? null) }}
            {{ $inappr('evalAppearanceClarityInappr', (bool) ($appearance['evalAppearanceClarityInappr'] ?? false)) }}
        </div>
    </div>

    <div class="row mb-1">
        <div class="col-md-3"><strong>Carbonation</strong></div>
        <div class="col-md-9">
            {{ $scale('evalAppearanceCarb', $nwCider['carb'], $appearance['evalAppearanceCarb'] ?? null) }}
            {{ $inappr('evalAppearanceCarbInappr', (bool) ($appearance['evalAppearanceCarbInappr'] ?? false)) }}
        </div>
    </div>
</fieldset>

<fieldset class="mb-4">
    <legend>Aroma</legend>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Characteristics</strong></div>
        <div class="col-md-9">
            <input class="form-control" type="text" name="evalAromaCharacteristics" required
                   value="{{ $aroma['evalAromaCharacteristics'] ?? '' }}">
        </div>
    </div>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Intensity</strong></div>
        <div class="col-md-9">
            {{ $scale('evalAromaIntensity', $nwCider['intensity'], $aroma['evalAromaIntensity'] ?? null) }}
            {{ $inappr('evalAromaIntensityInappr', (bool) ($aroma['evalAromaIntensityInappr'] ?? false)) }}
        </div>
    </div>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Quality</strong></div>
        <div class="col-md-9">
            {{ $scale('evalAromaQuality', $nwCider['quality'], $aroma['evalAromaQuality'] ?? null) }}
            {{ $inappr('evalAromaQualityInappr', (bool) ($aroma['evalAromaQualityInappr'] ?? false)) }}
        </div>
    </div>
</fieldset>

<fieldset class="mb-4">
    <legend>Flavour</legend>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Characteristics</strong></div>
        <div class="col-md-9">
            <input class="form-control" type="text" name="evalFlavorCharacteristics" required
                   value="{{ $flavor['evalFlavorCharacteristics'] ?? '' }}">
        </div>
    </div>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Intensity</strong></div>
        <div class="col-md-9">
            {{ $scale('evalFlavorIntensity', $nwCider['intensity'], $flavor['evalFlavorIntensity'] ?? null) }}
            {{ $inappr('evalFlavorIntensityInappr', (bool) ($flavor['evalFlavorIntensityInappr'] ?? false)) }}
        </div>
    </div>
    <div class="row mb-1">
        <div class="col-md-3"><strong>Quality</strong></div>
        <div class="col-md-9">
            {{ $scale('evalFlavorQuality', $nwCider['quality'], $flavor['evalFlavorQuality'] ?? null) }}
            {{ $inappr('evalFlavorQualityInappr', (bool) ($flavor['evalFlavorQualityInappr'] ?? false)) }}
        </div>
    </div>
</fieldset>

<fieldset class="mb-4">
    <legend>Mouthfeel</legend>
    @foreach (['evalMouthfeelBody' => 'Body', 'evalMouthfeelSweetness' => 'Sweetness', 'evalMouthfeelAcidity' => 'Acidity', 'evalMouthfeelTanninBitter' => 'Tannin (Bitter)', 'evalMouthfeelTanninAstringency' => 'Tannin (Astringency)', 'evalMouthfeelBalance' => 'Balance', 'evalMouthfeelLength' => 'Length'] as $field => $label)
        @php($scaleKey = match ($field) {
            'evalMouthfeelBody' => 'body',
            'evalMouthfeelSweetness' => 'sweetness',
            'evalMouthfeelAcidity' => 'acidity',
            'evalMouthfeelTanninBitter', 'evalMouthfeelTanninAstringency' => 'tannin',
            'evalMouthfeelBalance' => 'balance',
            default => 'length',
        })
        <div class="row mb-1">
            <div class="col-md-3"><strong>{{ $label }}</strong></div>
            <div class="col-md-9">
                {{ $scale($field, $nwCider[$scaleKey], $mouthfeel[$field] ?? null) }}
                {{ $inappr($field.'Inappr', (bool) ($mouthfeel[$field.'Inappr'] ?? false)) }}
            </div>
        </div>
    @endforeach
</fieldset>
