{{-- Shared entry-form fields for create (P3.3a) and edit (P3.3b).
     Spec: pub/brew.pub.php. Expects (from the parent view): $ctx,
     $styles, $variantFlags, $optionalStyles, $styleFlagMap; $entry is
     nullable (create). All inputs fall back to old() first.
     Variant fieldsets + required-info toggle client-side via app.js
     (entry.min.js showHide parity) using $styleFlagMap; the server-side
     $variantFlags render keeps them visible on validation round-trips
     without JS. --}}
@php($current = old('brewStyle', $entry !== null
    ? ltrim((string) $entry->brewCategorySort, '0').'-'.$entry->brewSubCategory
    : ''))
@php($charLimit = (int) $ctx->prefsStr('prefsSpecialCharLimit'))
@php($pouring = isset($entry) && is_string($entry->brewPouring) && str_starts_with($entry->brewPouring, '{')
    ? json_decode($entry->brewPouring, true)
    : [])
@php($gravity = isset($entry) && is_string($entry->brewSweetnessLevel) && str_starts_with($entry->brewSweetnessLevel, '{')
    ? json_decode($entry->brewSweetnessLevel, true)
    : [])

<div class="mb-4 row">
    <label for="brewerName" class="col-sm-3 col-form-label"><strong>{{ __('site.brewer') }}</strong></label>
    <div class="col-sm-9">
        <input type="text" readonly class="form-control" id="brewerName"
               value="{{ ($entry->brewBrewerFirstName ?? $brewer->brewerFirstName ?? '').' '.($entry->brewBrewerLastName ?? $brewer->brewerLastName ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="brewCoBrewer" class="col-sm-3 col-form-label"><strong>{{ __('site.co_brewer') }}</strong></label>
    <div class="col-sm-9">
        <input class="form-control" id="brewCoBrewer" name="brewCoBrewer" type="text"
               value="{{ old('brewCoBrewer', $entry->brewCoBrewer ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="brewName" class="col-sm-3 col-form-label text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.entry_name') }} *</strong></label>
    <div class="col-sm-9">
        <input class="form-control" id="brewName" name="brewName" type="text" required autofocus
               value="{{ old('brewName', $entry->brewName ?? '') }}">
        @error('brewName')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
</div>
<div class="mb-4 row">
    <label for="brewStyle" class="col-sm-3 col-form-label text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.style') }} *</strong></label>
    <div class="col-sm-9">
        <select class="form-select" name="brewStyle" id="brewStyle" required>
            <option value="">{{ __('site.select_style') }}</option>
            @foreach ($styles as $style)
                @php($value = \App\Http\Controllers\BrewController::styleValue($style))
                @php($markers = '')
                @if ((int) $style->brewStyleReqSpec === 1) @php($markers .= ' '.html_entity_decode('&spades;')) @endif
                @if ((int) $style->brewStyleStrength === 1) @php($markers .= ' '.html_entity_decode('&diams;')) @endif
                @if ((int) $style->brewStyleCarb === 1) @php($markers .= ' '.html_entity_decode('&clubs;')) @endif
                @if ((int) $style->brewStyleSweet === 1) @php($markers .= ' '.html_entity_decode('&hearts;')) @endif
                <option value="{{ $value }}" @selected($value === $current)>
                    {{ \App\Http\Controllers\BrewController::styleLabel($style).$markers }}
                </option>
            @endforeach
        </select>
        @error('brewStyle')<div class="text-danger">{{ $message }}</div>@enderror
        <div id="req-special" class="mt-1 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleReqSpec === 1) hidden @endunless">{!! __('site.req_special_legend') !!}</div>
        <div id="req-strength" class="mt-1 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleStrength === 1) hidden @endunless">{!! __('site.req_strength_legend') !!}</div>
        <div id="req-carbonation" class="mt-1 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleCarb === 1) hidden @endunless">{!! __('site.req_carb_legend') !!}</div>
        <div id="req-sweetness" class="mt-1 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleSweet === 1) hidden @endunless">{!! __('site.req_sweet_legend') !!}</div>
        <script type="application/json" id="style-flag-map">@json($styleFlagMap)</script>
        <script type="application/json" id="optional-info-styles">@json($optionalStyles)</script>
    </div>
</div>

<div id="specialInfo" class="mb-4 row @unless ($variantFlags !== null && trim((string) ($variantFlags->brewStyleEntry ?? '')) !== '') hidden @endunless">
    <div class="offset-sm-3 col-sm-9">
        <p class="alert alert-teal" id="specialInfoText">{{ $variantFlags->brewStyleEntry ?? '' }}</p>
    </div>
</div>

<div id="special" class="mb-4 row @unless ($variantFlags !== null && (int) $variantFlags->brewStyleReqSpec === 1) hidden @endunless">
    <label for="brewInfo" class="col-sm-3 col-form-label text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.required_info') }} *</strong></label>
    <div class="col-sm-9">
        <textarea class="form-control" rows="8" name="brewInfo" id="brewInfo"
                  maxlength="{{ $charLimit }}">{{ old('brewInfo', $entry->brewInfo ?? '') }}</textarea>
        <div class="form-text">{{ $charLimit }}{{ __('site.character_limit') }}<span id="countInfo">{{ mb_strlen(old('brewInfo', $entry->brewInfo ?? '')) }}</span></div>
        @error('brewInfo')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
</div>

<div id="optional" class="mb-4 row @unless ($variantFlags !== null && in_array(ltrim((string) $variantFlags->brewStyleGroup, '0').'-'.$variantFlags->brewStyleNum, $optionalStyles, true)) hidden @endunless">
    <label for="brewInfoOptional" class="col-sm-3 col-form-label"><strong>{{ __('site.optional_info') }}</strong></label>
    <div class="col-sm-9">
        <textarea class="form-control" rows="4" name="brewInfoOptional"
                  id="brewInfoOptional" maxlength="{{ $charLimit }}">{{ old('brewInfoOptional', $entry->brewInfoOptional ?? '') }}</textarea>
        <div class="form-text">{{ $charLimit }}{{ __('site.character_limit') }}<span id="countInfoOptional">{{ mb_strlen(old('brewInfoOptional', $entry->brewInfoOptional ?? '')) }}</span></div>
    </div>
</div>

<fieldset id="carbonation" class="mb-4 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleCarb === 1) hidden @endunless">
    <legend class="col-form-label pt-0 text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.carbonation') }} *</strong></legend>
    @foreach ([['Still', 'still'], ['Petillant', 'petillant'], ['Sparkling', 'sparkling']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewMead1" value="{{ $value }}" id="carb_{{ $loop->index }}"
                   @checked(old('brewMead1', $entry->brewMead1 ?? '') === $value)>
            <label class="form-check-label" for="carb_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
</fieldset>

<fieldset id="sweetness-mead" class="mb-4 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleSweet === 1 && (string) $variantFlags->brewStyleType === '3') hidden @endunless">
    <legend class="col-form-label pt-0 text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.sweetness') }} *</strong></legend>
    @foreach ([['Dry', 'dry'], ['Medium Dry', 'medium_dry'], ['Medium', 'medium'], ['Medium Sweet', 'medium_sweet'], ['Sweet', 'sweet']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewMead2-mead" value="{{ $value }}" id="sweet_mead_{{ $loop->index }}"
                   @checked(old('brewMead2-mead', $entry->brewMead2 ?? '') === $value)>
            <label class="form-check-label" for="sweet_mead_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
</fieldset>

<fieldset id="sweetness-cider" class="mb-4 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleSweet === 1 && (string) $variantFlags->brewStyleType === '2') hidden @endunless">
    <legend class="col-form-label pt-0 text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.sweetness') }} *</strong></legend>
    @foreach ([['Dry', 'dry'], ['Semi-Dry', 'semi_dry'], ['Medium', 'medium'], ['Semi-Sweet', 'semi_sweet'], ['Sweet', 'sweet']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewMead2-cider" value="{{ $value }}" id="sweet_cider_{{ $loop->index }}"
                   @checked(old('brewMead2-cider', $entry->brewMead2 ?? '') === $value)>
            <label class="form-check-label" for="sweet_cider_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
</fieldset>

<fieldset id="strength" class="mb-4 @unless ($variantFlags !== null && (int) $variantFlags->brewStyleStrength === 1) hidden @endunless">
    <legend class="col-form-label pt-0 text-teal"><strong><i class="fa fa-star me-1"></i>{{ __('site.strength') }} *</strong></legend>
    @foreach ([['Hydromel', 'hydromel'], ['Standard', 'standard'], ['Sack', 'sack']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewMead3" value="{{ $value }}" id="strength_{{ $loop->index }}"
                   @checked(old('brewMead3', $entry->brewMead3 ?? '') === $value)>
            <label class="form-check-label" for="strength_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
</fieldset>

<fieldset id="specify-pouring" class="mb-4 @unless ($variantFlags !== null && (string) $variantFlags->brewStyleType === '1') hidden @endunless">
    <legend class="col-form-label pt-0"><strong>{{ __('site.pouring_instructions') }}</strong></legend>
    <p class="mb-1">{{ __('site.pouring_inst') }}</p>
    @foreach ([['Fast', 'fast'], ['Normal', 'normal'], ['Slow', 'slow']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewPouringInst" value="{{ $value }}" id="pour_{{ $loop->index }}"
                   @checked(old('brewPouringInst', $pouring['pouring'] ?? 'Normal') === $value)>
            <label class="form-check-label" for="pour_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
    <p class="mt-2 mb-1">{{ __('site.rouse') }}</p>
    @foreach ([['Yes', 'yes'], ['No', 'no']] as [$value, $key])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewPouringRouse" value="{{ $value }}" id="rouse_{{ $loop->index }}"
                   @checked(old('brewPouringRouse', $pouring['pouring_rouse'] ?? '') === $value)>
            <label class="form-check-label" for="rouse_{{ $loop->index }}">{{ __($key) }}</label>
        </div>
    @endforeach
    <div class="mt-2 mb-4 row">
        <label for="brewPouringNotes" class="col-sm-3 col-form-label"><strong>{{ __('site.pouring_notes') }}</strong></label>
        <div class="col-sm-9">
            <input class="form-control" name="brewPouringNotes" id="brewPouringNotes" type="text" maxlength="255"
                   value="{{ old('brewPouringNotes', $pouring['pouring_notes'] ?? '') }}">
        </div>
    </div>
</fieldset>

<div class="mb-4 row">
    <label for="og" class="col-sm-3 col-form-label text-teal"><i class="fa fa-star me-1"></i><strong>{{ __('site.original_gravity') }}</strong></label>
    <div class="col-sm-9">
        <input class="form-control" id="og" name="brewOriginalGravity" type="number" min="0" step="0.001"
               value="{{ old('brewOriginalGravity', $gravity['OG'] ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="fg" class="col-sm-3 col-form-label text-teal"><i class="fa fa-star me-1"></i><strong>{{ __('site.final_gravity') }}</strong></label>
    <div class="col-sm-9">
        <input class="form-control" id="fg" name="brewFinalGravity" type="number" min="0" step="0.001"
               value="{{ old('brewFinalGravity', $gravity['FG'] ?? '') }}">
    </div>
</div>

<div class="mb-4 row">
    <label for="brewABV" class="col-sm-3 col-form-label"><strong><i class="fa fa-star me-1"></i>{{ __('site.abv') }}</strong></label>
    <div class="col-sm-9">
        <input class="form-control" id="brewABV" name="brewABV" type="number" min="0" step="0.01"
               value="{{ old('brewABV', $entry->brewABV ?? '') }}">
    </div>
</div>

<fieldset class="mb-4">
    <legend class="col-form-label pt-0"><strong>{{ __('site.packaging') }}</strong></legend>
    @foreach ([['750', '750 ml Bottle'], ['500', '500 ml Bottle'], ['Other-Bottle', __('site.other_bottle')], ['19.2', '19.2 ounce Can'], ['16', '16 ounce Can'], ['12', '12 ounce Can'], ['Other-Can', __('site.other_can')]] as [$value, $label])
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="brewPackaging" value="{{ $value }}"
                   id="pkg_{{ $loop->index }}"
                   @checked(old('brewPackaging', $entry->brewPackaging ?? '') === $value)>
            <label class="form-check-label" for="pkg_{{ $loop->index }}">{{ $label }}</label>
        </div>
    @endforeach
</fieldset>

<div class="mb-4 row">
    <label for="brewPossAllergens" class="col-sm-3 col-form-label">{{ __('site.possible_allergens') }}</label>
    <div class="col-sm-9">
        <input class="form-control" id="brewPossAllergens" name="brewPossAllergens" type="text"
               value="{{ old('brewPossAllergens', $entry->brewPossAllergens ?? '') }}">
        <div class="form-text">{{ __('site.possible_allergens_text') }}</div>
    </div>
</div>

<div class="mb-4 row">
    <label for="brewComments" class="col-sm-3 col-form-label"><strong>{{ __('site.brewer_specifics') }}</strong></label>
    <div class="col-sm-9">
        <textarea class="form-control" rows="6" name="brewComments"
                  id="brewComments" maxlength="{{ $charLimit }}">{{ old('brewComments', $entry->brewComments ?? '') }}</textarea>
        <div class="form-text">{{ $charLimit }}{{ __('site.character_limit') }}<span id="countComments">{{ mb_strlen(old('brewComments', $entry->brewComments ?? '')) }}</span></div>
    </div>
</div>

<input type="hidden" name="brewConfirmed" value="1">
