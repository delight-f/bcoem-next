{{-- Shared entry-form fields for create (ticket 09) and edit (ticket 10).
     Expects (from the parent view): $styles, $variantFlags; $entry is
     nullable (create). All inputs fall back to old() first. --}}
@php($current = old('brewStyle', $entry !== null
    ? ltrim((string) $entry->brewCategorySort, '0').'-'.$entry->brewSubCategory
    : ''))

<div class="mb-4 row">
    <label for="brewName" class="col-sm-3 col-form-label"><strong>{{ __('site.entry_name') }} *</strong></label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="brewName" name="brewName" type="text" required
               value="{{ old('brewName', $entry->brewName ?? '') }}">
        @error('brewName')<div class="text-error">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-4 row">
    <label for="brewStyle" class="col-sm-3 col-form-label"><strong>{{ __('site.style') }} *</strong></label>
    <div class="col-sm-9">
        <select class="select select-bordered" name="brewStyle" id="brewStyle" required>
            <option value="">{{ __('site.select_style') }}</option>
            @foreach ($styles as $style)
                <option value="{{ \App\Http\Controllers\BrewController::styleValue($style) }}"
                        @selected(\App\Http\Controllers\BrewController::styleValue($style) === $current)>
                    {{ \App\Http\Controllers\BrewController::styleLabel($style) }}
                </option>
            @endforeach
        </select>
        @error('brewStyle')<div class="text-error">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-4 row">
    <label for="brewCoBrewer" class="col-sm-3 col-form-label">{{ __('site.co_brewer') }}</label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="brewCoBrewer" name="brewCoBrewer" type="text"
               value="{{ old('brewCoBrewer', $entry->brewCoBrewer ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="brewInfo" class="col-sm-3 col-form-label"><strong>{{ __('site.entry_info') }}</strong></label>
    <div class="col-sm-9">
        <textarea class="textarea textarea-bordered" rows="6" name="brewInfo"
                  id="brewInfo">{{ old('brewInfo', $entry->brewInfo ?? '') }}</textarea>
        <div class="form-text">{{ __('site.entry_info_text') }}</div>
    </div>
</div>

<div class="mb-4 row">
    <label for="brewInfoOptional" class="col-sm-3 col-form-label">{{ __('site.optional_info') }}</label>
    <div class="col-sm-9">
        <textarea class="textarea textarea-bordered" rows="4" name="brewInfoOptional"
                  id="brewInfoOptional">{{ old('brewInfoOptional', $entry->brewInfoOptional ?? '') }}</textarea>
    </div>
</div>

{{-- Mead/cider variant fields: rendered only when the chosen style's row
     asks for carbonation/sweetness/strength (process_brewing.inc.php
     keeps only the values the flags allow either way). --}}
@if ($variantFlags !== null && (int) $variantFlags->brewStyleCarb === 1)
    <fieldset class="mb-4">
        <legend class="col-form-label pt-0"><strong>{{ __('site.carbonation') }}</strong></legend>
        @foreach (['Low Carbonation' => 'site.low', 'Medium Carbonation' => 'site.medium', 'High Carbonation' => 'site.high'] as $value => $key)
            <div class="form-check form-check-inline">
                <input class="radio" type="radio" name="brewMead1" value="{{ $value }}" id="mead1_{{ $loop->index }}"
                       @checked(old('brewMead1', $entry->brewMead1 ?? '') === $value)>
                <label class="form-check-label" for="mead1_{{ $loop->index }}">{{ __($key) }}</label>
            </div>
        @endforeach
    </fieldset>
@endif

@if ($variantFlags !== null && (int) $variantFlags->brewStyleSweet === 1)
    <fieldset class="mb-4">
        <legend class="col-form-label pt-0"><strong>{{ __('site.sweetness') }}</strong></legend>
        @foreach (['Low/None Sweetness' => 'site.low', 'Medium Sweetness' => 'site.medium', 'High Sweetness' => 'site.high'] as $value => $key)
            <div class="form-check form-check-inline">
                <input class="radio" type="radio" name="brewMead2-mead" value="{{ $value }}"
                       id="sweet_{{ $loop->index }}"
                       @checked(old('brewMead2-mead', $entry->brewMead2 ?? '') === $value)>
                <label class="form-check-label" for="sweet_{{ $loop->index }}">{{ __($key) }}</label>
            </div>
        @endforeach
    </fieldset>
@endif

@if ($variantFlags !== null && (int) $variantFlags->brewStyleStrength === 1)
    <fieldset class="mb-4">
        <legend class="col-form-label pt-0"><strong>{{ __('site.strength') }}</strong></legend>
        @foreach (['Table Strength' => 'site.table_strength', 'Standard Strength' => 'site.standard_strength', 'Super Strength' => 'site.super_strength'] as $value => $key)
            <div class="form-check form-check-inline">
                <input class="radio" type="radio" name="brewMead3" value="{{ $value }}" id="strength_{{ $loop->index }}"
                       @checked(old('brewMead3', $entry->brewMead3 ?? '') === $value)>
                <label class="form-check-label" for="strength_{{ $loop->index }}">{{ __($key) }}</label>
            </div>
        @endforeach
    </fieldset>
@endif

<div class="mb-4 row">
    <label for="brewABV" class="col-sm-3 col-form-label">{{ __('site.abv') }}</label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="brewABV" name="brewABV" type="number" min="0" step="0.01"
               value="{{ old('brewABV', $entry->brewABV ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="og" class="col-sm-3 col-form-label">{{ __('site.original_gravity') }}</label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="og" name="brewOriginalGravity" type="number" min="0" step="0.001"
               value="{{ old('brewOriginalGravity', isset($entry) && is_string($entry->brewSweetnessLevel) && str_starts_with($entry->brewSweetnessLevel, '{') ? json_decode($entry->brewSweetnessLevel, true)['OG'] ?? '' : '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="fg" class="col-sm-3 col-form-label">{{ __('site.final_gravity') }}</label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="fg" name="brewFinalGravity" type="number" min="0" step="0.001"
               value="{{ old('brewFinalGravity', isset($entry) && is_string($entry->brewSweetnessLevel) && str_starts_with($entry->brewSweetnessLevel, '{') ? json_decode($entry->brewSweetnessLevel, true)['FG'] ?? '' : '') }}">
    </div>
</div>

<fieldset class="mb-4">
    <legend class="col-form-label pt-0"><strong>{{ __('site.packaging') }}</strong></legend>
    @foreach ([['750', '750 ml'], ['500', '500 ml'], ['12', '12 oz'], ['22', '22 oz'], ['375', '375 ml'], ['16', '16 oz'], ['19.2', '19.2 oz'], ['Other-Bottle', __('site.other_bottle')], ['Other-Can', __('site.other_can')]] as [$value, $label])
        <div class="form-check form-check-inline">
            <input class="radio" type="radio" name="brewPackaging" value="{{ $value }}"
                   id="pkg_{{ $loop->index }}"
                   @checked(old('brewPackaging', $entry->brewPackaging ?? '') === $value)>
            <label class="form-check-label" for="pkg_{{ $loop->index }}">{{ $label }}</label>
        </div>
    @endforeach
</fieldset>

<div class="mb-4 row">
    <label for="brewPossAllergens" class="col-sm-3 col-form-label">{{ __('site.possible_allergens') }}</label>
    <div class="col-sm-9">
        <input class="input input-bordered" id="brewPossAllergens" name="brewPossAllergens" type="text"
               value="{{ old('brewPossAllergens', $entry->brewPossAllergens ?? '') }}">
        <div class="form-text">{{ __('site.possible_allergens_text') }}</div>
    </div>
</div>

<div class="mb-4 row">
    <label for="brewComments" class="col-sm-3 col-form-label">{{ __('site.comments_to_organizer') }}</label>
    <div class="col-sm-9">
        <textarea class="textarea textarea-bordered" rows="4" name="brewComments"
                  id="brewComments">{{ old('brewComments', $entry->brewComments ?? '') }}</textarea>
    </div>
</div>

<input type="hidden" name="brewConfirmed" value="1">
