@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    // D1: prefsWinnerDelay is a flatpickr .date-time-picker-system input
    // (legacy site_preferences.admin.php:940); prefill matches the picker
    // dateFormat ('Y-m-d H:i' 24h / 'Y-m-d h:i K' 12h).
    $tf24 = ((int) $tf) === 1;
    $go = $go ?? 'default';
    $tabs = ['default' => 'General', 'entries' => 'Entries', 'email' => 'Email & Contact', 'payment' => 'Currency and payments', 'best' => 'Best Brewer and/or Club'];
    $langOptions = json_decode((string) $ctx->prefsStr('prefsLanguageOptions'), true);
    if (! is_array($langOptions)) {
        $langOptions = array_keys($languages);
    }
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Preferences</h1>

        <ul class="nav nav-tabs mb-4">
            @foreach ($tabs as $tabGo => $label)
                <li class="nav-item">
                    <a class="nav-link {{ $go === $tabGo ? 'active' : '' }}" href="{{ url('/admin/site-preferences/'.$tabGo) }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Preferences updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @php $p = fn (string $k) => (string) ($ctx->prefsStr($k) ?? ''); @endphp

        @if ($go === 'default')
            <h3>General</h3>
            <form data-time-24hr="{{ $tf24 ? '1' : '0' }}" method="post" action="{{ url('/admin/site-preferences/default') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label for="prefsProEdition" class="col-md-4 col-form-label">Competition Type</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsProEdition" value="0" id="proNo" @checked($p('prefsProEdition') !== '1')><label class="form-check-label" for="proNo">Amateur</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsProEdition" value="1" id="proYes" @checked($p('prefsProEdition') === '1')><label class="form-check-label" for="proYes">Professional</label></div>
                        <span class="form-text">Indicate whether the participants in the competition will be individual amateur brewers or licensed breweries with designated points of contact.</span>
                    </div>
                </div>
                <div class="mb-4 row" id="mhp-display">
                    <label for="prefsMHPDisplay" class="col-md-4 col-form-label">Master Homebrewer Program (MHP) Fields and Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsMHPDisplay" value="1" id="mhpYes" @checked($p('prefsMHPDisplay') === '1')><label class="form-check-label" for="mhpYes">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsMHPDisplay" value="0" id="mhpNo" @checked($p('prefsMHPDisplay') !== '1')><label class="form-check-label" for="mhpNo">Disable</label></div>
                        <span class="form-text">Enable or disable the ability for entrants to enter their Master Homebrewer Program (MHP) number when adding or editing their account information. When they do so, if this function is enabled, a tag will display beside their name if one or more of their entries place. This will also enable the Winners: Master Homebrewer Program Member Data CSV download available from the Data Exports section of the Administration Dashboard.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTheme" class="col-md-4 col-form-label">Theme</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsTheme" name="prefsTheme" style="width:auto;">
                            @foreach (['default' => 'Default', 'bcoem-brux' => 'Bruxellensis (dark)'] as $theme => $label)
                                <option value="{{ $theme }}" @selected($p('prefsTheme') === $theme)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">Palette for the public site. Admin pages always use the Bruxellensis palette.</span>
                    </div>
                </div>

                <h4>Results</h4>
                <div class="mb-4 row">
                    <label for="prefsDisplayWinners" class="col-md-4 col-form-label">Results Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsDisplayWinners" value="Y" id="dwY" @checked($p('prefsDisplayWinners') === 'Y')><label class="form-check-label" for="dwY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsDisplayWinners" value="N" id="dwN" @checked($p('prefsDisplayWinners') !== 'Y')><label class="form-check-label" for="dwN">Disable</label></div>
                        <span class="form-text">Indicate if the results of the competition for each category and Best of Show Style Type will be displayed.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsWinnerDelay" class="col-md-4 col-form-label">Results Display Date/Time</label>
                    <div class="col-md-8">
                        <input class="form-control date-time-picker-system" id="prefsWinnerDelay" name="prefsWinnerDelay" type="text" style="width:auto;" value="{{ \App\Support\Tenant\DateFmt::dateTimeInput($ctx->prefsStr('prefsWinnerDelay'), $tz, $tf24) ?? '' }}">
                        <span class="form-text">Date and time when the system will display winners if Results Display is enabled.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsWinnerMethod" class="col-md-4 col-form-label">Winner Place Distribution Method</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsWinnerMethod" value="0" id="prefsWinnerMethod_0" @checked($p('prefsWinnerMethod') === '0')><label class="form-check-label" for="prefsWinnerMethod_0">By Table/Medal Group</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsWinnerMethod" value="1" id="prefsWinnerMethod_1" @checked($p('prefsWinnerMethod') === '1')><label class="form-check-label" for="prefsWinnerMethod_1">By Style</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsWinnerMethod" value="2" id="prefsWinnerMethod_2" @checked($p('prefsWinnerMethod') === '2')><label class="form-check-label" for="prefsWinnerMethod_2">By Sub-Style</label></div>
                        <span class="form-text">How the competition will award places for winning entries.</span>
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="prefsSEF" class="col-md-4 col-form-label">Search Engine Friendly URLs</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSEF" value="Y" id="sefY" @checked($p('prefsSEF') === 'Y')><label class="form-check-label" for="sefY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSEF" value="N" id="sefN" @checked($p('prefsSEF') !== 'Y')><label class="form-check-label" for="sefN">Disable</label></div>
                        <span class="form-text">If you enable this and receive 404 errors, navigate to the login screen at <a class="hide-loader" href="{{ url('/login') }}" target="_blank" rel="noopener">{{ url('/login') }}</a> to log back in and &ldquo;turn off&rdquo; this feature.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsUseMods" class="col-md-4 col-form-label">Custom Modules</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsUseMods" value="Y" id="modsYes" @checked($p('prefsUseMods') === 'Y')><label class="form-check-label" for="modsYes">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsUseMods" value="N" id="modsNo" @checked($p('prefsUseMods') !== 'Y')><label class="form-check-label" for="modsNo">Disable</label></div>
                        <span class="form-text"><strong>FOR ADVANCED USERS.</strong> Utilize the ability to add custom modules that extend the site's core functionality.</span>
                    </div>
                </div>

                <h4>CAPTCHA</h4>
                <div class="mb-4 row">
                    <label for="prefsCAPTCHA" class="col-md-4 col-form-label">Enable Bot Protection</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCAPTCHA" value="1" id="capY" @checked($p('prefsCAPTCHA') === '1')><label class="form-check-label" for="capY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCAPTCHA" value="0" id="capN" @checked($p('prefsCAPTCHA') !== '1')><label class="form-check-label" for="capN">No</label></div>
                        <span class="form-text">Uses Cloudflare Turnstile on the registration form. Requires both keys below.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsGoogleAccount" class="col-md-4 col-form-label">Turnstile Keys</label>
                    <div class="col-md-8">
                        <input class="form-control @error('prefsGoogleAccount') is-invalid @enderror" id="prefsGoogleAccount" name="prefsGoogleAccount" type="text" value="{{ $p('prefsGoogleAccount') }}">
                        @error('prefsGoogleAccount')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <span class="form-text">Turnstile site key|secret key (pipe-separated), required when bot protection is enabled. Get free keys at <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare Turnstile</a>.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Email Verification</label>
                    <div class="col-md-8">
                        <span class="form-text">New signups must confirm their email before adding entries or paying. Set <code>EMAIL_VERIFICATION_ENABLED=true</code> in <code>.env</code> to turn it on (default off) &mdash; requires working outbound email on your server, so test your email settings first.</span>
                    </div>
                </div>

                <h4>Performance and Data Clean-Up</h4>
                <div class="mb-4 row">
                    <label for="prefsRecordPaging" class="col-md-4 col-form-label">Records Displayed</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsRecordPaging" name="prefsRecordPaging" type="text" style="width:auto;" placeholder="12" value="{{ $p('prefsRecordPaging') }}">
                        <span class="form-text">The number of records displayed per page when viewing lists.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsSessionTimeout" class="col-md-4 col-form-label">Session Timeout (Minutes)</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsSessionTimeout" name="prefsSessionTimeout" type="number" min="3" step="1" style="width:auto;" placeholder="{{ $sessionTimeoutDefault }}" value="{{ $p('prefsSessionTimeout') }}">
                        <span class="form-text">How many minutes of inactivity before an admin or participant is automatically logged out. Leave blank to use the installation default ({{ $sessionTimeoutDefault }} minutes).</span>
                        <span class="form-text">Must be a whole number of 3 or more minutes &ndash; the logout warning popups need that much time or more to show normally.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsAutoPurge" class="col-md-4 col-form-label">Automatically Purge Unconfirmed Entries and Perform Data Clean Up</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsAutoPurge" value="1" id="apY" @checked($p('prefsAutoPurge') === '1')><label class="form-check-label" for="apY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsAutoPurge" value="0" id="apN" @checked($p('prefsAutoPurge') !== '1')><label class="form-check-label" for="apN">Disable</label></div>
                        <span class="form-text">Automatically purge any entries flagged as unconfirmed or that require special ingredients but do not 24 hours after entry, as well as any data clean-up functions. If disabled, Admins will have the option to manually purge the entries.</span>
                    </div>
                </div>

                <h4>Localization</h4>
                <div class="mb-4 row">
                    <label for="prefsLanguage" class="col-md-4 col-form-label">Language</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsLanguage" name="prefsLanguage" style="width:auto;">
                            @foreach ($languages as $code => $name)
                                <option value="{{ $code }}" @selected($p('prefsLanguage') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">The language to display on all <em>public</em> areas of your installation (e.g., entry information, volunteers, account pages, etc.).</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsLanguageToggle" class="col-md-4 col-form-label">Runtime Language Toggle</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsLanguageToggle" value="Y" id="ltY" @checked($p('prefsLanguageToggle') === 'Y')><label class="form-check-label" for="ltY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsLanguageToggle" value="N" id="ltN" @checked($p('prefsLanguageToggle') !== 'Y')><label class="form-check-label" for="ltN">Disable</label></div>
                        <span class="form-text">Let visitors switch their own display language independently of the <strong>Language</strong> setting above, via a menu in the navigation bar. Locking the site to a single language (Disable) is unaffected either way.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsLanguageOptions" class="col-md-4 col-form-label">Available Languages</label>
                    <div class="col-md-8">
                        @foreach ($languages as $code => $name)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="prefsLanguageOptions[]" value="{{ $code }}" id="langOpt-{{ $code }}" @checked(in_array($code, $langOptions, true))>
                                <label class="form-check-label" for="langOpt-{{ $code }}">{{ $name }}</label>
                            </div>
                        @endforeach
                        <span class="form-text">Which languages visitors may choose from when the runtime language toggle above is enabled. At least one must remain selected.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsDateFormat" class="col-md-4 col-form-label">Date Format</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsDateFormat" name="prefsDateFormat" style="width:auto;">
                            <option value="0" @selected($p('prefsDateFormat') === '0')>YYYY/MM/DD</option>
                            <option value="1" @selected($p('prefsDateFormat') === '1')>MM/DD/YYYY</option>
                            <option value="2" @selected($p('prefsDateFormat') === '2')>DD/MM/YYYY</option>
                            <option value="999" @selected($p('prefsDateFormat') === '999')>System</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTimeFormat" class="col-md-4 col-form-label">Time Format</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsTimeFormat" name="prefsTimeFormat" style="width:auto;">
                            <option value="0" @selected($p('prefsTimeFormat') === '0')>12-hour</option>
                            <option value="1" @selected($p('prefsTimeFormat') === '1')>24-hour</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTimeZone" class="col-md-4 col-form-label">Time Zone</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsTimeZone" name="prefsTimeZone" style="width:auto;">
                            @foreach ($timezones as $value => $label)
                                <option value="{{ $value }}" @selected(number_format((float) $p('prefsTimeZone'), 3, '.', '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <h4>Sponsors Display</h4>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Sponsor Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSponsors" value="Y" id="sponY" @checked($p('prefsSponsors') === 'Y')><label class="form-check-label" for="sponY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSponsors" value="N" id="sponN" @checked($p('prefsSponsors') !== 'Y')><label class="form-check-label" for="sponN">Disable</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Sponsor Logo Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSponsorLogos" value="Y" id="slY" @checked($p('prefsSponsorLogos') === 'Y')><label class="form-check-label" for="slY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSponsorLogos" value="N" id="slN" @checked($p('prefsSponsorLogos') !== 'Y')><label class="form-check-label" for="slN">Disable</label></div>
                    </div>
                </div>

                <h4>Drop-Off and Shipping Display</h4>
                <div class="mb-4 row">
                    <label for="prefsDropOff" class="col-md-4 col-form-label">Drop-off Location Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsDropOff" value="1" id="dropYes" @checked((int) $p('prefsDropOff') === 1)><label class="form-check-label" for="dropYes">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsDropOff" value="0" id="dropNo" @checked((int) $p('prefsDropOff') !== 1)><label class="form-check-label" for="dropNo">Disable</label></div>
                        <span class="form-text">Disable if your competition does not have drop-off locations.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsShipping" class="col-md-4 col-form-label">Shipping Location Display</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsShipping" value="1" id="shipYes" @checked((int) $p('prefsShipping') === 1)><label class="form-check-label" for="shipYes">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsShipping" value="0" id="shipNo" @checked((int) $p('prefsShipping') !== 1)><label class="form-check-label" for="shipNo">Disable</label></div>
                        <span class="form-text">Disable if your competition does not have an entry shipping location.</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        @elseif ($go === 'entries')
            <h3>Entries</h3>
            <form method="post" action="{{ url('/admin/site-preferences/entries') }}">
                @csrf
                @method('put')
                @php
                    $c = fn (string $k) => (string) ($ctx->contestStr($k) ?? '');
                    $cur = $ctx->currencySymbol();
                    $usclChecked = array_filter(array_map('trim', explode(',', $p('prefsUSCLEx'))));
                    $entryOpenEpoch = (int) $entryOpen;
                @endphp
                <div class="mb-4 row">
                    <label for="prefsStyleSet" class="col-md-4 col-form-label">Style Set</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsStyleSet" name="prefsStyleSet" style="width:auto;">
                            @foreach ($styleSets as $setValue => $set)
                                <option value="{{ $setValue }}" @selected($p('prefsStyleSet') === $setValue)>{{ $set['short'] }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">Please note that every effort is made to keep the BA style data current; however, the latest <a class="hide-loader" href="https://www.brewersassociation.org/resources/brewers-association-beer-style-guidelines/" target="_blank" rel="noopener">BA style set</a> may <strong>not</strong> be available in this application.</span>
                        <span class="form-text">Please note that every effort is made to keep the AABC style data current; however, the latest <a class="hide-loader" href="https://aabc.asn.au" target="_blank" rel="noopener">AABC style set</a> may <strong>not</strong> be available for use in this application.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEntryForm" class="col-md-4 col-form-label">Printed Entry Bottle/Can Labels</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsEntryForm" name="prefsEntryForm" style="width:auto;">
                            <optgroup label="Print Multiple Entries at a Time">
                                @foreach ([
                                    '7' => 'Standard',
                                    '10' => 'Standard - Larger Printed Number and Style',
                                    '5' => 'Standard with Barcode/QR Code',
                                    '11' => 'Standard - Larger Printed Number and Style with Barcode/QR Code',
                                    '8' => 'Anonymous - Smaller Printed Entry Number',
                                    '6' => 'Anonymous - Smaller Printed Entry Number with Barcode/QR Code',
                                    '9' => 'Anonymous - Smaller Printed Random Number',
                                    '0' => 'Anonymous - Smaller Printed Random Number with Barcode/QR Code',
                                    '2' => 'Anonymous - Larger Printed Entry Number',
                                    '1' => 'Anonymous - Larger Printed Entry Number with Barcode/QR Code',
                                    '4' => 'Anonymous - Larger Printed Random Number',
                                    '3' => 'Anonymous - Larger Printed Random Number with Barcode/QR Code',
                                ] as $val => $label)
                                    <option value="{{ $val }}" @selected($p('prefsEntryForm') === $val)>{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                        <span class="form-text">
                            <p><strong>Standard Entry Labels</strong> feature the participant's name and contact info, the name of the entry, and the entry's style/category.</p>
                            <p><strong>Anonymous Entry Labels</strong> DO NOT list the participant's information. These labels are intended to be taped to bottles by entrants before submittal.</p>
                        </span>
                        <span class="form-text">
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#entryFormModal">Printed Entry Form and/or Bottle Labels Info</button>
                            <a class="btn btn-sm btn-info hide-loader" data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_standard.png') }}" data-caption="Standard">Examples</a>
                        </span>
                        <div class="d-none">
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_standard_large_number.png') }}" data-caption="Standard - Larger Printed Number and Style">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_standard_barcode.png') }}" data-caption="Standard with Barcode/QR Code">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_standard_large_number_barcode.png') }}" data-caption="Standard - Larger Printed Number and Style with Barcode/QR Code">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_anon.png') }}" data-caption="Anonymous - Smaller Printed Entry Number">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_anon_barcode.png') }}" data-caption="Anonymous - Smaller Printed Entry Number with Barcode/QR Code">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_anon_large_number.png') }}" data-caption="Anonymous - Larger Printed Entry Number">Link</a>
                            <a data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_anon_large_number_barcode.png') }}" data-caption="Anonymous - Larger Printed Entry Number with Barcode/QR Code">Link</a>
                        </div>
                    </div>
                </div>
                <div class="mb-4 row" id="prefsHideSpecific">
                    <label for="prefsSpecific" class="col-md-4 col-form-label">Hide Brewer&rsquo;s Specifics Field</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSpecific" value="1" id="specY" @checked($p('prefsSpecific') === '1')><label class="form-check-label" for="specY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsSpecific" value="0" id="specN" @checked($p('prefsSpecific') !== '1')><label class="form-check-label" for="specN">No</label></div>
                        <span class="form-text">
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#prefsSpecificModal">Hide Brewer&rsquo;s Specifics Field Info</button>
                        </span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsSpecialCharLimit" class="col-md-4 col-form-label">Character Limit for Text Entry</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsSpecialCharLimit" name="prefsSpecialCharLimit" style="width:auto;">
                            @foreach (range(25, 255, 5) as $i)
                                <option value="{{ $i }}" @selected($p('prefsSpecialCharLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">
                            <p>Indicate the limit of characters users can enter when specifying special ingredients, optional ingredients, and brewer's specifics. A limit of <strong>65 characters or less</strong> is suggested for competitions that attach &ldquo;Bottle Labels with Required Info&rdquo; to entry bottles at sorting.</p>
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#charLimitModal">Character Limit Info</button>
                        </span>
                    </div>
                </div>

                <h4>Fees and Discounts</h4>
                <div class="mb-4 row">
                    <label for="contestEntryFee" class="col-md-4 col-form-label">Per Entry Fee</label>
                    <div class="col-md-8">
                        <div class="input-group" style="width:auto;">
                            <span class="input-group-text">{{ $cur }}</span>
                            <input class="form-control" id="contestEntryFee" name="contestEntryFee" type="number" step=".01" style="width:auto;" value="{{ $c('contestEntryFee') }}">
                        </div>
                        <span class="form-text">Fee for a single entry. Enter a zero (0) for a free entry fee.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryCap" class="col-md-4 col-form-label">Fee Cap</label>
                    <div class="col-md-8">
                        <div class="input-group" style="width:auto;">
                            <span class="input-group-text">{{ $cur }}</span>
                            <input class="form-control" id="contestEntryCap" name="contestEntryCap" type="number" step=".01" style="width:auto;" value="{{ $c('contestEntryCap') }}">
                        </div>
                        <span class="form-text">Enter the maximum amount for each entrant. Leave blank if no cap.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeeDiscount" class="col-md-4 col-form-label">Discount Multiple Entries</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contestEntryFeeDiscount" value="Y" id="discY" @checked($c('contestEntryFeeDiscount') === 'Y')><label class="form-check-label" for="discY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contestEntryFeeDiscount" value="N" id="discN" @checked($c('contestEntryFeeDiscount') !== 'Y')><label class="form-check-label" for="discN">No</label></div>
                        <span class="form-text">Designate Yes or No if your competition offers a discounted entry fee after a certain number is reached.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeeDiscountNum" class="col-md-4 col-form-label">Minimum Entries for Discount</label>
                    <div class="col-md-8">
                        <input class="form-control" id="contestEntryFeeDiscountNum" name="contestEntryFeeDiscountNum" type="text" style="width:auto;" value="{{ $c('contestEntryFeeDiscountNum') }}">
                        <span class="form-text">The entry threshold participants must exceed to take advantage of the per entry fee discount (designated below). If no discounted fee exists, leave blank.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFee2" class="col-md-4 col-form-label">Discounted Entry Fee</label>
                    <div class="col-md-8">
                        <div class="input-group" style="width:auto;">
                            <span class="input-group-text">{{ $cur }}</span>
                            <input class="form-control" id="contestEntryFee2" name="contestEntryFee2" type="number" step=".01" style="width:auto;" value="{{ $c('contestEntryFee2') }}">
                        </div>
                        <span class="form-text">Fee for a single, discounted entry.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeePassword" class="col-md-4 col-form-label">Member Discount Password</label>
                    <div class="col-md-8">
                        <input class="form-control" id="contestEntryFeePassword" name="contestEntryFeePassword" type="text" value="{{ $c('contestEntryFeePassword') }}">
                        <span class="form-text">Designate a password for participants to enter to receive discounted entry fees. Useful if your competition provides a discount for members of the sponsoring club(s).</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeePasswordNum" class="col-md-4 col-form-label">Member Discount Fee</label>
                    <div class="col-md-8">
                        <div class="input-group" style="width:auto;">
                            <span class="input-group-text">{{ $cur }}</span>
                            <input class="form-control" id="contestEntryFeePasswordNum" name="contestEntryFeePasswordNum" type="number" step=".01" style="width:auto;" value="{{ $c('contestEntryFeePasswordNum') }}">
                        </div>
                        <span class="form-text">Fee for a single, discounted member entry. If you wish the member discount to be free, enter a zero (0). Leave blank for no discount.</span>
                    </div>
                </div>

                <h4>Limits</h4>
                <div class="mb-4 row">
                    <label for="prefsEntryLimit" class="col-md-4 col-form-label">Total Entry Limit &ndash; Paid/Unpaid</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsEntryLimit" name="prefsEntryLimit" type="text" style="width:auto;" value="{{ $p('prefsEntryLimit') }}">
                        <span class="form-text">Limit of <strong class="text-danger">total</strong> entries you will accept in the competition. Leave blank if no limit.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEntryLimitPaid" class="col-md-4 col-form-label">Total Entry Limit &ndash; Paid</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsEntryLimitPaid" name="prefsEntryLimitPaid" type="text" style="width:auto;" value="{{ $p('prefsEntryLimitPaid') }}">
                        <span class="form-text">
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#entryLimitPaidModal">Paid Entry Limit Info</button>
                            <p class="mt-2">Limit of <strong class="text-danger">paid</strong> entries you will accept in the competition. Leave blank if no limit.</p>
                        </span>
                    </div>
                </div>
                @if ($styleTypesBos->isNotEmpty())
                    @foreach ($styleTypesBos as $st)
                        <div class="mb-4 row">
                            <label for="styleTypeEntryLimit-{{ $st->id }}" class="col-md-4 col-form-label">Entry Limit &ndash; {{ $st->styleTypeName }}</label>
                            <div class="col-md-8">
                                <input class="form-control" id="styleTypeEntryLimit-{{ $st->id }}" name="styleTypeEntryLimit-{{ $st->id }}" type="number" min="0" style="width:auto;" value="{{ $st->styleTypeEntryLimit }}">
                            </div>
                        </div>
                    @endforeach
                    <div class="mb-4 row">
                        <div class="col-md-8 offset-md-4">
                            <span class="form-text">Individual style type entry limits above are only for those that have BOS enabled. <a href="{{ url('/admin/style-types') }}">Manage your competition style types</a> to specify entry limits for others.</span>
                        </div>
                    </div>
                    <input type="hidden" name="style_type_entry_limits" value="{{ $styleTypesBos->pluck('id')->implode(',') }}">
                @endif
                <h4>Entry Limits by Style or Table/Medal Group</h4>
                <div class="mb-4 row">
                    <label for="choose-style-entry-limits" class="col-md-4 col-form-label">Entry Limit Method</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="choose-style-entry-limits" value="0" id="csel_0" @checked($p('prefsStyleLimits') === '')><label class="form-check-label" for="csel_0">Disable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="choose-style-entry-limits" value="2" id="csel_2" @checked($p('prefsStyleLimits') === '2')><label class="form-check-label" for="csel_2">Enable By Table or Medal Group</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="choose-style-entry-limits" value="1" id="csel_1" @checked(str_starts_with($p('prefsStyleLimits'), '{'))><label class="form-check-label" for="csel_1">Enable By Style</label></div>
                        <span class="form-text"><strong class="text-primary">Please note:</strong> If you choose a different entry limit method than what is currently defined, any limits set previously will be deleted and all styles previously disabled due to limits will be enabled.</span>
                        <span class="form-text"><strong>Limiting by table or medal group</strong> requires that your installation be placed into <strong>Tables Planning Mode</strong> and <a href="{{ url('/admin/judging/tables') }}">tables/medal groups defined</a> (limits are set when creating or editing tables/medal groups).</span>
                        <span class="form-text"><strong>Limiting entries by style</strong> allows you to define a numerical limit on overall styles or style groups. Define your per-style limits below.</span>
                    </div>
                </div>
                <section id="define-style-entry-limits">
                    <div class="mb-4 row">
                        <label for="styleLimitsEdit" class="col-md-4 col-form-label">Entry Limits per {{ $styleSet }} Style</label>
                        <div class="col-md-8">
                            <button class="btn btn-sm btn-default" type="button" data-bs-toggle="collapse" data-bs-target="#style-limits-list" aria-expanded="false" aria-controls="style-limits-list">Expand/Collapse the {{ $styleSet }} Style List ({{ count($styleLimitRows) }} styles)</button>
                            <div class="collapse" id="style-limits-list">
                                <div class="border rounded p-2 mt-2" style="max-height:24rem; overflow:auto;">
                                    @foreach ($styleLimitRows as $row)
                                        <div class="row mb-1 small">
                                            <div class="col-md-2">{{ $row['label'] }}</div>
                                            <div class="col-md-5">
                                                <input type="number" min="0" class="form-control" name="styleEntryLimit-{{ $styleSet }}-{{ $row['key'] }}" value="{{ $row['value'] }}">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <h4>Per Participant Limits</h4>
                <div class="mb-4 row">
                    <label for="prefsUserEntryLimit" class="col-md-4 col-form-label">Overall Entry Limit per Participant</label>
                    <div class="col-md-8">
                        <select class="form-select" name="prefsUserEntryLimit" id="prefsUserEntryLimit" style="width:auto;">
                            <option value="" @selected($p('prefsUserEntryLimit') === '')></option>
                            @foreach (range(1, 10) as $i)
                                <option value="{{ $i }}" @selected($p('prefsUserEntryLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">Overall limit of entries that each participant can enter. Will override any incremental limit defined below IF this number is lower. Leave blank if no OVERALL entry limit.</span>
                    </div>
                </div>
                @foreach (range(1, 4) as $i)
                    <section id="user-entry-limit-increment-{{ $i }}">
                        <div class="mb-4 row">
                            <label for="user-entry-limit-number-{{ $i }}" class="col-md-4 col-form-label">#{{ $i }} Incremental Entry Limit per Participant</label>
                            <div class="col-md-8">
                                <select class="form-select" name="user-entry-limit-number-{{ $i }}" id="user-entry-limit-number-{{ $i }}" style="width:auto;">
                                    <option value=""></option>
                                    @foreach (range(1, 10) as $a)
                                        <option value="{{ $a }}" @selected((string) ($incrementalLimits[$i]['limit-number'] ?? '') === (string) $a)>{{ $a }}</option>
                                    @endforeach
                                </select>
                                <span class="form-text">Numerical limit of entries per participant for the specified number of days AFTER the entry window opening date.</span>
                            </div>
                        </div>
                        <div class="mb-4 row">
                            <label for="user-entry-limit-expire-days-{{ $i }}" class="col-md-4 col-form-label">#{{ $i }} Incremental Entry Limit per Participant <span class="text-primary">Days</span></label>
                            <div class="col-md-8">
                                <select class="form-select" id="user-entry-limit-expire-days-{{ $i }}" name="user-entry-limit-expire-days-{{ $i }}" style="width:auto;">
                                    <option value=""></option>
                                    @foreach (range(1, 30) as $b)
                                        <option value="{{ $b }}" @selected((string) ($incrementalLimits[$i]['limit-days'] ?? '') === (string) $b)>{{ $b }}@if ($entryOpenEpoch > 0) - {{ \App\Support\Tenant\DateFmt::dateTime($entryOpenEpoch + $b * 86400, $tz, $ctx->prefsStr('prefsDateFormat'), $tf) }}@endif</option>
                                    @endforeach
                                </select>
                                <span class="form-text">Number of days AFTER the entry window opening date that the #{{ $i }} per participant limit will EXPIRE.</span>
                            </div>
                        </div>
                        @if ($i < 4)<hr>@endif
                    </section>
                @endforeach
                <div class="mb-4 row">
                    <label for="prefsUserSubCatLimit" class="col-md-4 col-form-label">Per Participant Sub-Style Entry Limit</label>
                    <div class="col-md-8">
                        <select class="form-select" name="prefsUserSubCatLimit" id="prefsUserSubCatLimit" style="width:auto;">
                            <option value="" @selected($p('prefsUserSubCatLimit') === '')></option>
                            @foreach (range(1, 10) as $i)
                                <option value="{{ $i }}" @selected($p('prefsUserSubCatLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">Limit of entries that each participant can enter into a single sub-style. Leave blank if no limit.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsUSCLExLimit" class="col-md-4 col-form-label">Per Participant Entry Limit For <em>Excepted</em> Sub-Styles</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsUSCLExLimit" name="prefsUSCLExLimit" style="width:auto;">
                            <option value="" @selected($p('prefsUSCLExLimit') === '')></option>
                            @foreach (range(1, 10) as $i)
                                <option value="{{ $i }}" @selected($p('prefsUSCLExLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                        <span class="form-text">
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#exceptdSubstylesModal">Per Participant Entry Limit For <em>Excepted</em> Sub-Styles Info</button>
                        </span>
                    </div>
                </div>
                <div class="mb-4 row" id="subStyleExeptionsEdit">
                    <label for="prefsUSCLEx" class="col-md-4 col-form-label">Exceptions to Per Participant Sub-Style Entry Limit</label>
                    <div class="col-md-8">
                        <button class="btn btn-sm btn-default" type="button" data-bs-toggle="collapse" data-bs-target="#sub-style-list" aria-expanded="false" aria-controls="sub-style-list">Expand/Collapse the Sub-Style List ({{ count($styleExceptions) }} styles)</button>
                        <div class="collapse" id="sub-style-list">
                            <div class="d-flex flex-wrap gap-2 align-items-center my-2">
                                <input type="search" class="form-control form-control-sm" id="usclExFilter" placeholder="Filter sub-styles&hellip;" style="max-width:18rem;" autocomplete="off">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="usclExAll">Select all shown</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="usclExNone">Clear all</button>
                                <span class="text-muted small"><span id="usclExCount">0</span> selected</span>
                            </div>
                            <div class="border rounded p-2" id="usclExList" style="max-height:22rem; overflow:auto;">
                                @foreach (collect($styleExceptions)->groupBy('group') as $group => $rows)
                                    <div class="uscl-ex-group">
                                        <div class="text-uppercase text-muted small fw-bold mt-2">Group {{ $group }}</div>
                                        @foreach ($rows as $ex)
                                            <div class="form-check uscl-ex-row">
                                                <input class="form-check-input" type="checkbox" name="prefsUSCLEx[]" value="{{ $ex['id'] }}" id="usclEx-{{ $ex['id'] }}" @checked(in_array((string) $ex['id'], $usclChecked, true))>
                                                <label class="form-check-label" for="usclEx-{{ $ex['id'] }}">{{ $ex['label'] }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    // The exception list runs to ~150 styles; a flat wall of
                    // checkboxes is unusable. Filter + group + bulk actions.
                    (function () {
                        var list = document.getElementById('usclExList');
                        if (!list) { return; }
                        var filter = document.getElementById('usclExFilter');
                        var count = document.getElementById('usclExCount');
                        var rows = function () { return Array.prototype.slice.call(list.querySelectorAll('.uscl-ex-row')); };
                        var refreshCount = function () {
                            count.textContent = list.querySelectorAll('input[type=checkbox]:checked').length;
                        };
                        filter.addEventListener('input', function () {
                            var q = (filter.value || '').trim().toLowerCase();
                            list.querySelectorAll('.uscl-ex-group').forEach(function (group) {
                                var any = false;
                                group.querySelectorAll('.uscl-ex-row').forEach(function (row) {
                                    var hit = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                                    row.style.display = hit ? '' : 'none';
                                    if (hit) { any = true; }
                                });
                                group.style.display = any ? '' : 'none';
                            });
                        });
                        document.getElementById('usclExAll').addEventListener('click', function () {
                            rows().forEach(function (row) {
                                if (row.style.display !== 'none') { row.querySelector('input').checked = true; }
                            });
                            refreshCount();
                        });
                        document.getElementById('usclExNone').addEventListener('click', function () {
                            list.querySelectorAll('input[type=checkbox]').forEach(function (b) { b.checked = false; });
                            refreshCount();
                        });
                        list.addEventListener('change', refreshCount);
                        refreshCount();
                    })();
                </script>

                {{-- Modals (legacy entryFormModal / prefsSpecificModal / charLimitModal / entryLimitPaidModal / exceptdSubstylesModal). --}}
                <div class="modal fade" id="entryFormModal" tabindex="-1" aria-labelledby="entryFormModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="entryFormModalLabel">Printed Entry Labels</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>There are two types of entry labels available:</p>
                                <ul>
                                    <li>Standard Entry Labels feature the participant's name and contact info, the name of the entry, and the entry's style/category.</li>
                                    <li>Anonymous Entry Labels DO NOT list the participant's information. These labels are intended to be taped to bottles by entrants before submittal, thereby saving the labor and waste of removing rubberbanded labels by competition staff when sorting. <strong>It is recommended that you specify taping these labels to entries in your Entry Acceptance Rules,</strong> specified via the <a href="{{ url('/admin/competition-info') }}">Edit Competition Info function</a>.</li>
                                </ul>
                                <p>Both label types are available with or without a barcode and QR code corresponding to the unique identification number.</p>
                                <p>The Barcode options are intended to be used with a USB barcode scanner and the <a href="{{ url('/admin/judging/checkin') }}">barcode entry check-in function</a>.</p>
                                <p>The QR code options are intended to be used with a mobile device and <a class="hide-loader" href="{{ url('/qr') }}" target="_blank" rel="noopener">QR code entry check-in function</a> (requires a QR code reading app).</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="prefsSpecificModal" tabindex="-1" aria-labelledby="prefsSpecificModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="prefsSpecificModalLabel">Hide Brewer&rsquo;s Specifics Field</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Indicate if the Brewer&rsquo;s Specifics field on the Add Entry or Edit Entry screens will be displayed to users. The field is sometimes confused with the required &ldquo;Special Ingredients&rdquo; field.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="charLimitModal" tabindex="-1" aria-labelledby="charLimitModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="charLimitModalLabel">Character Limit Info</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Limit of characters allowed for the Required Info section when adding an entry.</p>
                                <p><strong>65 characters</strong> is the maximum recommended when utilizing the &ldquo;Bottle Labels with Required Info&rdquo; report. This ensures that the required and optional information added by the entrant will fit on a single address-size label.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="entryLimitPaidModal" tabindex="-1" aria-labelledby="entryLimitPaidLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="entryLimitPaidLabel">Paid Entry Limit Info</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>This option should be used with caution as it depends upon one or more factors for successful implementation:</p>
                                <ol>
                                    <li>Whether or not the competition is accepting online payments.</li>
                                    <li>Whether or not the competition organization facilitates multiple pickups from drop-off sites <em>before</em> the drop-off deadline date (so that Admins can mark entries as paid before sorting day).</li>
                                    <li>Whether or not the competition is employing multiple sorting dates to check-in entries and mark them as paid.</li>
                                </ol>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="exceptdSubstylesModal" tabindex="-1" aria-labelledby="exceptdSubstylesModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="exceptdSubstylesModalLabel">Entry Limit For Excepted Sub-Styles Info</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Limit of entries that each participant can enter into one of the sub-styles that have been checked. Leave blank if no limit <strong>for the sub-styles that have been checked</strong>.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Entry Preferences</button>
            </form>
        @elseif ($go === 'email')
            <form method="post" action="{{ url('/admin/site-preferences/email') }}">
                @csrf
                @method('put')
                @php($emailOn = (string) $p('prefsEmailSMTP') !== '0')
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Allow BCOE&amp;M to Send Emails</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailSMTP" value="1" id="smtpYes" @checked($emailOn)><label class="form-check-label" for="smtpYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailSMTP" value="0" id="smtpNo" @checked(! $emailOn)><label class="form-check-label" for="smtpNo">No</label></div>
                        <div class="form-text">With "No", the platform sends nothing at all — no confirmations, resets or receipts.</div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailTransport" class="col-md-4 col-form-label">How Emails Are Sent</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsEmailTransport" name="prefsEmailTransport" style="max-width:32rem;">
                            <option value="" @selected(\App\Support\Mail\MailSettings::transport($ctx) === null)>
                                Application default (from .env)
                            </option>
                            @foreach (\App\Support\Mail\MailSettings::TRANSPORTS as $t)
                                <option value="{{ $t }}" @selected((string) $p('prefsEmailTransport') === $t)>
                                    {{ \App\Support\Mail\MailSettings::label($t) }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            If your host blocks outgoing SMTP (common on shared/cPanel hosting,
                            e.g. nfshost), choose the server's own mail program, or an HTTPS
                            provider such as Resend or Postmark — those send over the web, not
                            through a mail port.
                        </div>
                    </div>
                </div>
                <div id="mail-group-smtp">
                <div class="mb-4 row">
                    <label for="prefsEmailHost" class="col-md-4 col-form-label">Host</label>
                    <div class="col-md-8"><input class="form-control" id="prefsEmailHost" name="prefsEmailHost" type="text" value="{{ $p('prefsEmailHost') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailPort" class="col-md-4 col-form-label">Port</label>
                    <div class="col-md-8"><input class="form-control" id="prefsEmailPort" name="prefsEmailPort" type="number" style="width:auto;" value="{{ $p('prefsEmailPort') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailEncrypt" class="col-md-4 col-form-label">Encryption</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsEmailEncrypt" name="prefsEmailEncrypt" style="width:auto;">
                            @foreach (['tls', 'ssl', 'none'] as $enc)
                                <option value="{{ $enc }}" @selected(strtolower($p('prefsEmailEncrypt')) === $enc)>{{ strtoupper($enc) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailUsername" class="col-md-4 col-form-label">SMTP Username</label>
                    <div class="col-md-8"><input class="form-control" id="prefsEmailUsername" name="prefsEmailUsername" type="text" value="{{ $p('prefsEmailUsername') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Change Password?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="change-email-password-choice" value="0" id="pwKeep" @checked(true)>
                            <label class="form-check-label" for="pwKeep">Keep stored password</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="change-email-password-choice" value="1" id="pwChange">
                            <label class="form-check-label" for="pwChange">Set new password below</label>
                        </div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailPassword" class="col-md-4 col-form-label">SMTP Password</label>
                    <div class="col-md-8"><input class="form-control" id="prefsEmailPassword" name="prefsEmailPassword" type="password" autocomplete="new-password"></div>
                </div>
                </div>{{-- /mail-group-smtp --}}
                <div id="mail-group-api">
                    <div class="mb-4 row">
                        <label for="prefsEmailApiKey" class="col-md-4 col-form-label">Provider API Key</label>
                        <div class="col-md-8">
                            <input class="form-control" id="prefsEmailApiKey" name="prefsEmailApiKey" type="password" autocomplete="new-password"
                                   placeholder="{{ (string) $p('prefsEmailApiKey') !== '' ? 'Saved — leave blank to keep' : '' }}">
                            <div class="form-text">From your Resend or Postmark dashboard. Leave blank to keep the stored key.</div>
                        </div>
                    </div>
                </div>
                <div id="mail-group-sendmail">
                    <div class="mb-4 row">
                        <label class="col-md-4 col-form-label">Local Mail Program</label>
                        <div class="col-md-8">
                            <div class="form-text mt-0">
                                Messages are handed to this server's own mail program — the same one
                                behind PHP's <code>mail()</code> — so no outgoing SMTP port is needed.
                                The program path is set with <code>MAIL_SENDMAIL_PATH</code> in
                                <code>.env</code> (cPanel hosts usually want
                                <code>/usr/sbin/sendmail -t -i</code>).
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailFrom" class="col-md-4 col-form-label">Originating Email Address</label>
                    <div class="col-md-8"><input class="form-control" id="prefsEmailFrom" name="prefsEmailFrom" type="text" value="{{ $p('prefsEmailFrom') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Contact Form</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsContact" value="Y" id="contactY" @checked($p('prefsContact') === 'Y')><label class="form-check-label" for="contactY">Enable Contact Form</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsContact" value="N" id="contactN" @checked($p('prefsContact') === 'N')><label class="form-check-label" for="contactN">Disable Contact Form - List Contacts</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsContact" value="X" id="contactX" @checked($p('prefsContact') === 'X')><label class="form-check-label" for="contactX">Disable Contact Form - Do Not List Contacts</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Contact Form CC</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailCC" value="1" id="ccYes" @checked($p('prefsEmailCC') === '1')><label class="form-check-label" for="ccYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailCC" value="0" id="ccNo" @checked($p('prefsEmailCC') !== '1')><label class="form-check-label" for="ccNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Registration Confirmation Emails</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailRegConfirm" value="1" id="regYes" @checked($p('prefsEmailRegConfirm') === '1')><label class="form-check-label" for="regYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsEmailRegConfirm" value="0" id="regNo" @checked($p('prefsEmailRegConfirm') !== '1')><label class="form-check-label" for="regNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="send-test-email" class="col-md-4 col-form-label">SMTP Settings Test</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="send-test-email" value="1" id="testEmailYes"><label class="form-check-label" for="testEmailYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="send-test-email" value="0" id="testEmailNo" checked><label class="form-check-label" for="testEmailNo">No</label></div>
                        {{-- Legacy sends the test email directly from
                             send_test_email.admin.php (fancybox iframe);
                             ported as SendTestEmailController. Kept on its own
                             line: inline with the radios it read as a label. --}}
                        <div class="mt-3">
                            <a data-fancybox data-type="iframe" class="modal-window-link hide-loader btn btn-primary" href="{{ route('admin.send_test_email.show') }}">Test Current Email Sending Settings</a>
                        </div>
                        @unless (\App\Support\Mail\MailSettings::delivers($ctx))
                            <div class="alert alert-warning mt-3 mb-0">
                                The current settings do not deliver mail. Sending is
                                {{ \App\Support\Mail\MailSettings::disabled($ctx) ? 'switched off above' : 'set to log only' }},
                                so the test will report success without an email arriving.
                            </div>
                        @endunless
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Email Preferences</button>
            </form>
            <script>
                // Show only the fields that belong to the selected transport.
                // Hidden inputs still submit, so switching back keeps values.
                (function () {
                    var select = document.getElementById('prefsEmailTransport');
                    if (!select) { return; }
                    var groups = {
                        smtp: document.getElementById('mail-group-smtp'),
                        api: document.getElementById('mail-group-api'),
                        sendmail: document.getElementById('mail-group-sendmail')
                    };
                    function sync() {
                        var v = select.value;
                        groups.smtp.hidden = v !== 'smtp';
                        groups.api.hidden = v !== 'resend' && v !== 'postmark';
                        groups.sendmail.hidden = v !== 'sendmail';
                    }
                    select.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @elseif ($go === 'payment')
            <form method="post" action="{{ url('/admin/site-preferences/payment') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label for="prefsCurrency" class="col-md-4 col-form-label">Currency</label>
                    <div class="col-md-8">
                        <select class="form-select" id="prefsCurrency" name="prefsCurrency" style="width:auto;">
                            @foreach (['$', 'R$', 'pound', 'czkoruna', 'euro', 'A$', 'C$', 'H$', 'N$', 'S$', 'T$', 'Ft', 'shekel', 'yen', 'nkr', 'kr', 'RM', 'M$', 'phpeso', 'pol', 'p.', 'skr', 'sfranc', 'baht', 'tlira', 'R', 'rupee', 'krw'] as $curr)
                                <option value="{{ $curr }}" @selected($p('prefsCurrency') === $curr)>{{ $curr }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTransFee" class="col-md-4 col-form-label">Checkout Fees Paid by Entrant</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsTransFee" value="Y" id="tfY" @checked($p('prefsTransFee') === 'Y')><label class="form-check-label" for="tfY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsTransFee" value="N" id="tfN" @checked($p('prefsTransFee') !== 'Y')><label class="form-check-label" for="tfN">Disable</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Pay to Print?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsPayToPrint" value="1" id="ptpYes" @checked($p('prefsPayToPrint') === '1')><label class="form-check-label" for="ptpYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsPayToPrint" value="0" id="ptpNo" @checked($p('prefsPayToPrint') !== '1')><label class="form-check-label" for="ptpNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Accept Cash?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCash" value="1" id="cashYes" @checked($p('prefsCash') === '1')><label class="form-check-label" for="cashYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCash" value="0" id="cashNo" @checked($p('prefsCash') !== '1')><label class="form-check-label" for="cashNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Accept Checks?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCheck" value="1" id="checkYes" @checked($p('prefsCheck') === '1')><label class="form-check-label" for="checkYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsCheck" value="0" id="checkNo" @checked($p('prefsCheck') !== '1')><label class="form-check-label" for="checkNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsCheckPayee" class="col-md-4 col-form-label">Checks Payable To</label>
                    <div class="col-md-8"><input class="form-control" id="prefsCheckPayee" name="prefsCheckPayee" type="text" value="{{ $p('prefsCheckPayee') }}"></div>
                </div>
                {{-- PayPal retired (payments plan W3): online payments are
                     Stripe Connect — status + manage link below. --}}
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Online payments</label>
                    <div class="col-md-8 col-form-label">
                        @if (str_contains($p('prefsStripe'), 'account_id'))
                            <span class="text-success-emphasis">Stripe — Connected.</span>
                        @else
                            <span class="text-danger-emphasis">Stripe — Not connected.</span>
                        @endif
                        <a href="{{ route('admin.stripe') }}">Manage</a>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Payment Preferences</button>
            </form>
        @else
            @php($ordinal = fn (int $i) => ($i % 100 >= 11 && $i % 100 <= 13) ? 'th' : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$i % 10])
            @php($positionOptions = fn (?string $current) => collect(range(-1, 50))
                ->map(fn ($i) => '<option value="'.$i.'"'.((string) $i === (string) $current ? ' selected' : '').'>'
                    .($i === -1 ? 'Display all' : ($i === 0 ? 'Do not display' : 'Up to '.$i.$ordinal($i).' position'))
                    .'</option>')
                ->implode('\n'))
            @php($tieBreakRules = [
                '' => 'Unused.',
                'TBTotalPlaces' => 'The highest total number of first, second, and third places.',
                'TBTotalExtendedPlaces' => 'The highest total number of first, second, third, fourth (if applicable), and honorable mention places.',
                'TBFirstPlaces' => 'The highest number of first places.',
                'TBNumEntries' => 'The lowest number of entries.',
                'TBMinScore' => 'The highest minimum score.',
                'TBMaxScore' => 'The highest maximum score.',
                'TBAvgScore' => 'The highest average score.',
            ])
            <h3>Best Brewer and/or Club</h3>
            <form method="post" action="{{ url('/admin/site-preferences/best') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label for="prefsShowBestBrewer" class="col-md-4 col-form-label">Best Brewer Display? Up to which Position?</label>
                    <div class="col-md-8">
                        <select class="form-select" name="prefsShowBestBrewer" id="prefsShowBestBrewer">{!! $positionOptions($p('prefsShowBestBrewer')) !!}</select>
                        <p class="form-text">Indicate whether you want to display the list of best brewers according to the points and tie break rules defined below and, if so, up to which position. They will be showed at the same time indicated above for the Winners Display.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestBrewerTitle" class="col-md-4 col-form-label">Best Brewer Title</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsBestBrewerTitle" name="prefsBestBrewerTitle" type="text" value="{{ $p('prefsBestBrewerTitle') }}">
                        <p class="form-text">Enter the title for the Best Brewer award (e.g., Heavy Medal, Ninkasi Award).</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsShowBestClub" class="col-md-4 col-form-label">Best Club Display? Up to which Position?</label>
                    <div class="col-md-8">
                        <select class="form-select" name="prefsShowBestClub" id="prefsShowBestClub">{!! $positionOptions($p('prefsShowBestClub')) !!}</select>
                        <p class="form-text">Indicate whether you want to display the list of best clubs according to the points and tie break rules defined below and, if so, up to which position. They will be showed at the same time indicated above for the Winners Display. Applies ONLY to the amateur edition.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestClubTitle" class="col-md-4 col-form-label">Best Club Title</label>
                    <div class="col-md-8">
                        <input class="form-control" id="prefsBestClubTitle" name="prefsBestClubTitle" type="text" value="{{ $p('prefsBestClubTitle') }}">
                        <p class="form-text">Enter the title for the Best Club award.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Include BOS in Calculations?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsBestUseBOS" value="1" id="bbosYes" @checked($p('prefsBestUseBOS') === '1')><label class="form-check-label" for="bbosYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsBestUseBOS" value="0" id="bbosNo" @checked($p('prefsBestUseBOS') !== '1')><label class="form-check-label" for="bbosNo">No</label></div>
                        <p class="form-text">Indicate whether you wish to include any Best of Show (BOS) places in Best Brewer and Best Club calculations.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-md-4 col-form-label">Use Circuit of America Calculations?</label>
                    <div class="col-md-8">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsScoringCOA" value="1" id="coaYes" @checked($p('prefsScoringCOA') === '1')><label class="form-check-label" for="coaYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="prefsScoringCOA" value="0" id="coaNo" @checked($p('prefsScoringCOA') !== '1')><label class="form-check-label" for="coaNo">No</label></div>
                        <p class="form-text">Indicate whether you wish use the Master Homebrewer Program's <a href="https://www.masterhomebrewerprogram.com/circuit-of-america" target="_blank">Circuit of America</a> scoring methodolgy for all Best Brewer and Best Club calculations. <strong>Indicating "Yes" here will override all other calculation preferences.</strong></p>
                        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#coa-info-modal">Circuit of America Calculations Info</button>
                    </div>
                </div>
                <section id="non-COA-scoring" @if ($p('prefsScoringCOA') === '1') hidden @endif>
                    @foreach ([
                        'prefsFirstPlacePts' => 'Points for First Place',
                        'prefsSecondPlacePts' => 'Points for Second Place',
                        'prefsThirdPlacePts' => 'Points for Third Place',
                        'prefsFourthPlacePts' => 'Points for Fourth Place',
                        'prefsHMPts' => 'Points for Honorable Mention',
                    ] as $field => $label)
                        <div class="mb-4 row">
                            <label for="{{ $field }}" class="col-md-4 col-form-label">{!! $label !!}</label>
                            <div class="col-md-8">
                                <select class="form-select" name="{{ $field }}" id="{{ $field }}">
                                    @foreach (range(0, 25) as $i)
                                        <option value="{{ $i }}" @selected((string) $i === (string) $p($field))>{{ $i }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach
                </section>
                @foreach (range(1, 6) as $i)
                    <div class="mb-4 row">
                        <label for="prefsTieBreakRule{{ $i }}" class="col-md-4 col-form-label">Tie Break Rule #{{ $i }}</label>
                        <div class="col-md-8">
                            <select class="form-select" name="prefsTieBreakRule{{ $i }}" id="prefsTieBreakRule{{ $i }}">
                                @foreach ($tieBreakRules as $rule => $label)
                                    <option value="{{ $rule }}" @selected((string) $p('prefsTieBreakRule'.$i) === (string) $rule)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">Save Best Brewer/Club Preferences</button>
            </form>
            <div class="modal fade" id="coa-info-modal" tabindex="-1" aria-labelledby="coa-info-modal-label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="coa-info-modal-label">Circuit of America Scoring Info</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Use the Master Homebrewer Program's Circuit of America scoring methodology to determine Best Brewer and Best Club results.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const sync = () => {
                        const coa = document.querySelector('input[name="prefsScoringCOA"]:checked');
                        const section = document.getElementById('non-COA-scoring');
                        if (coa && section) section.hidden = coa.value === '1';
                    };
                    document.querySelectorAll('input[name="prefsScoringCOA"]').forEach((r) => r.addEventListener('click', sync));
                    sync();
                });
            </script>
        @endif
    </section>
</x-public-layout>
