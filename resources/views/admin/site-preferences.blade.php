@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $df = $ctx->prefsStr('prefsDateFormat');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    $dt = fn (string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $contest[$key] ?? null, $tz, $df, $tf, 'system', false,
    );
    $go = $go ?? 'default';
    $tabs = ['default' => 'Display', 'entries' => 'Entries', 'email' => 'Email', 'payment' => 'Payment', 'best' => 'Best Brewer/Club'];
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
            <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @php $p = fn (string $k) => (string) ($ctx->prefsStr($k) ?? ''); @endphp

        @if ($go === 'default')
            <form method="post" action="{{ url('/admin/site-preferences/default') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Pro Edition</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsProEdition" value="1" id="proYes" @checked($p('prefsProEdition') === '1')><label class="form-check-label" for="proYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsProEdition" value="0" id="proNo" @checked($p('prefsProEdition') !== '1')><label class="form-check-label" for="proNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Show Homebrew Club Points Page</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsMHPDisplay" value="1" id="mhpYes" @checked($p('prefsMHPDisplay') === '1')><label class="form-check-label" for="mhpYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsMHPDisplay" value="0" id="mhpNo" @checked($p('prefsMHPDisplay') !== '1')><label class="form-check-label" for="mhpNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Display Winners</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsDisplayWinners" value="Y" id="dwY" @checked($p('prefsDisplayWinners') === 'Y')><label class="form-check-label" for="dwY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsDisplayWinners" value="N" id="dwN" @checked($p('prefsDisplayWinners') !== 'Y')><label class="form-check-label" for="dwN">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsWinnerDelay" class="col-sm-4 col-form-label">Winners Display Date/Time</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsWinnerDelay" name="prefsWinnerDelay" type="text" style="width:auto;" value="{{ \App\Support\Tenant\DateFmt::dateTime($ctx->prefsStr('prefsWinnerDelay'), $tz, $df, $tf, 'system', false) }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsWinnerMethod" class="col-sm-4 col-form-label">Winner Place Distribution Method</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsWinnerMethod" name="prefsWinnerMethod" style="width:auto;">
                            <option value="0" @selected($p('prefsWinnerMethod') === '0')>By Table/Medal Group</option>
                            <option value="1" @selected($p('prefsWinnerMethod') === '1')>By Style</option>
                            <option value="2" @selected($p('prefsWinnerMethod') === '2')>By Sub-Style</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTheme" class="col-sm-4 col-form-label">Theme</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsTheme" name="prefsTheme" style="width:auto;">
                            @foreach (['default', 'cerulean', 'superhero', 'slate', 'darkly'] as $theme)
                                <option value="{{ $theme }}" @selected($p('prefsTheme') === $theme)>{{ ucfirst($theme) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Use Custom Modules?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsUseMods" value="1" id="modsYes" @checked($p('prefsUseMods') === '1')><label class="form-check-label" for="modsYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsUseMods" value="0" id="modsNo" @checked($p('prefsUseMods') !== '1')><label class="form-check-label" for="modsNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsDropOff" class="col-sm-4 col-form-label">Entry Drop-Off</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsDropOff" name="prefsDropOff" style="width:auto;">
                            <option value="Y" @selected($p('prefsDropOff') === 'Y')>Enabled</option>
                            <option value="N" @selected($p('prefsDropOff') !== 'Y')>Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsShipping" class="col-sm-4 col-form-label">Shipping</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsShipping" name="prefsShipping" style="width:auto;">
                            <option value="Y" @selected($p('prefsShipping') === 'Y')>Enabled</option>
                            <option value="N" @selected($p('prefsShipping') !== 'Y')>Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsLanguage" class="col-sm-4 col-form-label">Language</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsLanguage" name="prefsLanguage" style="width:auto;">
                            @foreach ($languages as $code => $name)
                                <option value="{{ $code }}" @selected($p('prefsLanguage') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsDateFormat" class="col-sm-4 col-form-label">Date Format</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsDateFormat" name="prefsDateFormat" style="width:auto;">
                            <option value="0" @selected($p('prefsDateFormat') === '0')>YYYY/MM/DD</option>
                            <option value="1" @selected($p('prefsDateFormat') === '1')>MM/DD/YYYY</option>
                            <option value="2" @selected($p('prefsDateFormat') === '2')>DD/MM/YYYY</option>
                            <option value="999" @selected($p('prefsDateFormat') === '999')>System</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTimeFormat" class="col-sm-4 col-form-label">Time Format</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsTimeFormat" name="prefsTimeFormat" style="width:auto;">
                            <option value="0" @selected($p('prefsTimeFormat') === '0')>12-hour</option>
                            <option value="1" @selected($p('prefsTimeFormat') === '1')>24-hour</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTimeZone" class="col-sm-4 col-form-label">Time Zone</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsTimeZone" name="prefsTimeZone" style="width:auto;">
                            @foreach ($timezones as $value => $label)
                                <option value="{{ $value }}" @selected($p('prefsTimeZone') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsCAPTCHA" class="col-sm-4 col-form-label">Enable CAPTCHA</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCAPTCHA" value="1" id="capY" @checked($p('prefsCAPTCHA') === '1')><label class="form-check-label" for="capY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCAPTCHA" value="0" id="capN" @checked($p('prefsCAPTCHA') !== '1')><label class="form-check-label" for="capN">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsRecordPaging" class="col-sm-4 col-form-label">Records Displayed</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="prefsRecordPaging" name="prefsRecordPaging" type="text" style="width:auto;" placeholder="12" value="{{ $p('prefsRecordPaging') }}">
                        <span class="help-block">The number of records displayed per page when viewing lists.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsGoogleAccount" class="col-sm-4 col-form-label">reCAPTCHA Account(s)</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="prefsGoogleAccount" name="prefsGoogleAccount" type="text" value="{{ $p('prefsGoogleAccount') }}">
                        <span class="help-block">reCAPTCHA site key|secret key (pipe-separated) if CAPTCHA is enabled.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsSEF" class="col-sm-4 col-form-label">Search Engine Friendly URLs</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSEF" value="Y" id="sefY" @checked($p('prefsSEF') === 'Y')><label class="form-check-label" for="sefY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSEF" value="N" id="sefN" @checked($p('prefsSEF') !== 'Y')><label class="form-check-label" for="sefN">Disable</label></div>
                        <span class="help-block">If you enable this and receive 404 errors, navigate to the login screen at <a class="hide-loader" href="{{ url('/login') }}" target="_blank" rel="noopener">{{ url('/login') }}</a> to log back in and &ldquo;turn off&rdquo; this feature.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsAutoPurge" class="col-sm-4 col-form-label">Automatically Purge Unconfirmed Entries and Perform Data Clean Up</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsAutoPurge" value="1" id="apY" @checked($p('prefsAutoPurge') === '1')><label class="form-check-label" for="apY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsAutoPurge" value="0" id="apN" @checked($p('prefsAutoPurge') !== '1')><label class="form-check-label" for="apN">Disable</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsLanguageToggle" class="col-sm-4 col-form-label">Runtime Language Toggle</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsLanguageToggle" value="Y" id="ltY" @checked($p('prefsLanguageToggle') === 'Y')><label class="form-check-label" for="ltY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsLanguageToggle" value="N" id="ltN" @checked($p('prefsLanguageToggle') !== 'Y')><label class="form-check-label" for="ltN">Disable</label></div>
                        <span class="help-block">Requires additional language files in the lang directory.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsLanguageOptions" class="col-sm-4 col-form-label">Available Languages</label>
                    <div class="col-sm-9">
                        @foreach ($languages as $code => $name)
                            <div class="form-check">
                                <input class="checkbox" type="checkbox" name="prefsLanguageOptions[]" value="{{ $code }}" id="langOpt-{{ $code }}" @checked(in_array($code, $langOptions, true))>
                                <label class="form-check-label" for="langOpt-{{ $code }}">{{ $name }}</label>
                            </div>
                        @endforeach
                        <span class="help-block">Which languages visitors may choose from when the runtime language toggle is enabled.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsSponsorLogos" class="col-sm-4 col-form-label">Sponsor Logo Display</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSponsorLogos" value="Y" id="slY" @checked($p('prefsSponsorLogos') === 'Y')><label class="form-check-label" for="slY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSponsorLogos" value="N" id="slN" @checked($p('prefsSponsorLogos') !== 'Y')><label class="form-check-label" for="slN">Disable</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Sponsors</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSponsors" value="Y" id="sponY" @checked($p('prefsSponsors') === 'Y')><label class="form-check-label" for="sponY">Enabled</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSponsors" value="N" id="sponN" @checked($p('prefsSponsors') !== 'Y')><label class="form-check-label" for="sponN">Disabled</label></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        @elseif ($go === 'entries')
            <form method="post" action="{{ url('/admin/site-preferences/entries') }}">
                @csrf
                @method('put')
                <h3>Fees (stored on contest info)</h3>
                @php $c = fn (string $k) => (string) ($ctx->contestStr($k) ?? ''); @endphp
                <div class="mb-4 row">
                    <label for="contestEntryFee" class="col-sm-4 col-form-label">Per Entry Fee</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFee" name="contestEntryFee" type="text" style="width:auto;" value="{{ $c('contestEntryFee') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFee2" class="col-sm-4 col-form-label">Discounted Entry Fee</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFee2" name="contestEntryFee2" type="text" style="width:auto;" value="{{ $c('contestEntryFee2') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeeDiscountNum" class="col-sm-4 col-form-label">Minimum Entries for Discount</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFeeDiscountNum" name="contestEntryFeeDiscountNum" type="number" min="1" style="width:auto;" value="{{ $c('contestEntryFeeDiscountNum') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryCap" class="col-sm-4 col-form-label">Fee Cap</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryCap" name="contestEntryCap" type="number" min="1" style="width:auto;" value="{{ $c('contestEntryCap') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeeDiscount" class="col-sm-4 col-form-label">Discount Multiple Entries</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="contestEntryFeeDiscount" value="Y" id="discY" @checked($c('contestEntryFeeDiscount') === 'Y')><label class="form-check-label" for="discY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="contestEntryFeeDiscount" value="N" id="discN" @checked($c('contestEntryFeeDiscount') !== 'Y')><label class="form-check-label" for="discN">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeePassword" class="col-sm-4 col-form-label">Member Discount Password</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="contestEntryFeePassword" name="contestEntryFeePassword" type="text" value="{{ $c('contestEntryFeePassword') }}">
                        <span class="help-block">Password for participants to enter to receive discounted entry fees.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeePasswordNum" class="col-sm-4 col-form-label">Member Discount Fee</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFeePasswordNum" name="contestEntryFeePasswordNum" type="number" min="1" style="width:auto;" value="{{ $c('contestEntryFeePasswordNum') }}"></div>
                </div>

                <h3>Limits</h3>
                <div class="mb-4 row">
                    <label for="prefsStyleSet" class="col-sm-4 col-form-label">Style Set</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsStyleSet" name="prefsStyleSet" style="width:auto;">
                            @foreach (['BJCP2021', 'BJCP2025', 'AABC2025', 'NWCiderCup'] as $set)
                                <option value="{{ $set }}" @selected($p('prefsStyleSet') === $set)>{{ $set }}</option>
                            @endforeach
                        </select>
                        <span class="help-block">Changing the set rebuilds the accepted-styles list.</span>
                    </div>
                </div>
                @foreach ([
                    'prefsEntryLimit' => 'Total Entry Limit &ndash; Paid/Unpaid',
                    'prefsEntryLimitPaid' => 'Total Entry Limit &ndash; Paid',
                    'prefsUserEntryLimit' => 'Overall Entry Limit per Participant',
                    'prefsUserSubCatLimit' => 'Per Participant Sub-Style Entry Limit',
                ] as $field => $label)
                    <div class="mb-4 row">
                        <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                        <div class="col-sm-9"><input class="input input-bordered" id="{{ $field }}" name="{{ $field }}" type="number" min="1" style="width:auto;" value="{{ $p($field) }}"></div>
                    </div>
                @endforeach
                <div class="mb-4 row">
                    <label for="prefsUSCLExLimit" class="col-sm-4 col-form-label">Per Participant Entry Limit For <em>Excepted</em> Sub-Styles</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsUSCLExLimit" name="prefsUSCLExLimit" style="width:auto;">
                            <option value="" @selected($p('prefsUSCLExLimit') === '')></option>
                            @foreach (range(1, 50) as $i)
                                <option value="{{ $i }}" @selected($p('prefsUSCLExLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="prefsEntryForm" class="col-sm-4 col-form-label">Printed Entry Bottle/Can Labels</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsEntryForm" name="prefsEntryForm" style="width:auto;">
                            @foreach ([
                                '7' => 'Standard',
                                '10' => 'Standard - Larger Printed Number and Style',
                                '5' => 'Standard with Barcode/QR Code',
                                '11' => 'Standard - Larger Printed Number and Style with Barcode/QR Code',
                                '8' => 'Anonymous - Smaller Printed Entry Number',
                                '6' => 'Anonymous - Smaller Printed Entry Number with Barcode/QR Code',
                                '9' => 'Anonymous - Smaller Printed Random Number',
                            ] as $val => $label)
                                <option value="{{ $val }}" @selected($p('prefsEntryForm') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="help-block">
                            <a class="btn btn-xs btn-info hide-loader" data-fancybox="gallery" rel="group-bottle-labels" href="{{ asset('images/label_standard.png') }}" data-caption="Standard">Examples</a>
                            <p class="mt-2">Both label types are available with or without a barcode and QR code corresponding to the unique identification number.</p>
                            <p>The Barcode options are intended to be used with a USB barcode scanner and the <a class="hide-loader" href="{{ url('/admin/judging/checkin') }}">barcode entry check-in function</a>.</p>
                            <p>The QR code options are intended to be used with a mobile device and <a class="hide-loader" href="{{ url('/qr') }}" target="_blank" rel="noopener">QR code entry check-in function</a> (requires a QR code reading app).</p>
                        </span>
                        <div class="hidden">
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
                <div class="mb-4 row">
                    <label for="prefsSpecific" class="col-sm-4 col-form-label">Hide Brewer's Specifics Field</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSpecific" value="1" id="specY" @checked($p('prefsSpecific') === '1')><label class="form-check-label" for="specY">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsSpecific" value="0" id="specN" @checked($p('prefsSpecific') !== '1')><label class="form-check-label" for="specN">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsSpecialCharLimit" class="col-sm-4 col-form-label">Character Limit for Text Entry</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsSpecialCharLimit" name="prefsSpecialCharLimit" style="width:auto;">
                            @foreach (range(25, 255, 5) as $i)
                                <option value="{{ $i }}" @selected($p('prefsSpecialCharLimit') === (string) $i)>{{ $i }}</option>
                            @endforeach
                        </select>
                        <span class="help-block">Limit for special ingredients, optional ingredients, and brewer's specifics. 65 or less suggested when attaching bottle labels at sorting.</span>
                    </div>
                </div>

                <h3>Per-style limits</h3>
                <div class="mb-4 row">
                    <label for="choose-style-entry-limits" class="col-sm-4 col-form-label">Entry Limits by Style or Table/Medal Group</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="choose-style-entry-limits" name="choose-style-entry-limits" style="width:auto;">
                            <option value="0" @selected($p('prefsStyleLimits') === '')>Disable</option>
                            <option value="1" @selected(str_starts_with($p('prefsStyleLimits'), '{'))>Enable By Style</option>
                            <option value="2" @selected($p('prefsStyleLimits') === '2')>Enable By Table or Medal Group</option>
                        </select>
                        <span class="help-block">Limiting by table or medal group requires Tables Planning Mode and defined tables/medal groups. Limiting entries by style allows a numerical limit on overall styles or style groups.</span>
                    </div>
                </div>
                <section id="define-style-entry-limits">
                    <div class="mb-4 row">
                        <label for="styleLimitsEdit" class="col-sm-4 col-form-label">Entry Limits per {{ $styleSet }} Style</label>
                        <div class="col-sm-9">
                            @foreach ($styleLimitRows as $row)
                                <div class="row mb-1 small">
                                    <div class="col-sm-3 col-md-2">{{ $row['label'] }}</div>
                                    <div class="col-sm-9 col-md-5">
                                        <input type="number" min="0" class="input input-bordered" name="styleEntryLimit-{{ $styleSet }}-{{ $row['key'] }}" value="{{ $row['value'] }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <button type="submit" class="btn btn-primary">Save Entry Preferences</button>
            </form>
        @elseif ($go === 'email')
            <form method="post" action="{{ url('/admin/site-preferences/email') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Allow BCOE&amp;M to Send Emails</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailSMTP" value="1" id="smtpYes" @checked($p('prefsEmailSMTP') === '1')><label class="form-check-label" for="smtpYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailSMTP" value="0" id="smtpNo" @checked($p('prefsEmailSMTP') !== '1')><label class="form-check-label" for="smtpNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailHost" class="col-sm-4 col-form-label">Host</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailHost" name="prefsEmailHost" type="text" value="{{ $p('prefsEmailHost') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailPort" class="col-sm-4 col-form-label">Port</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailPort" name="prefsEmailPort" type="number" style="width:auto;" value="{{ $p('prefsEmailPort') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailEncrypt" class="col-sm-4 col-form-label">Encryption</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsEmailEncrypt" name="prefsEmailEncrypt" style="width:auto;">
                            @foreach (['tls', 'ssl', 'none'] as $enc)
                                <option value="{{ $enc }}" @selected(strtolower($p('prefsEmailEncrypt')) === $enc)>{{ strtoupper($enc) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailUsername" class="col-sm-4 col-form-label">SMTP Username</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailUsername" name="prefsEmailUsername" type="text" value="{{ $p('prefsEmailUsername') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Change Password?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="change-email-password-choice" value="0" id="pwKeep" @checked(true)>
                            <label class="form-check-label" for="pwKeep">Keep stored password</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="change-email-password-choice" value="1" id="pwChange">
                            <label class="form-check-label" for="pwChange">Set new password below</label>
                        </div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailPassword" class="col-sm-4 col-form-label">SMTP Password</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailPassword" name="prefsEmailPassword" type="password" autocomplete="new-password"></div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsEmailFrom" class="col-sm-4 col-form-label">Originating Email Address</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailFrom" name="prefsEmailFrom" type="text" value="{{ $p('prefsEmailFrom') }}"></div>
                </div>
                            <input class="radio" type="radio" name="prefsContact" value="Y" id="contactY" @checked($p('prefsContact') === 'Y')><label class="form-check-label" for="contactY">Enable Contact Form</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsContact" value="N" id="contactN" @checked($p('prefsContact') === 'N')><label class="form-check-label" for="contactN">Disable Contact Form - List Contacts</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsContact" value="X" id="contactX" @checked($p('prefsContact') === 'X')><label class="form-check-label" for="contactX">Disable Contact Form - Do Not List Contacts</label></div>
                    </div>
                </div>
                            <input class="radio" type="radio" name="prefsContact" value="Y" id="contactY" @checked($p('prefsContact') === 'Y')><label class="form-check-label" for="contactY">Enabled</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsContact" value="N" id="contactN" @checked($p('prefsContact') !== 'Y')><label class="form-check-label" for="contactN">Disabled</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Registration Confirmation Emails</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailRegConfirm" value="1" id="regYes" @checked($p('prefsEmailRegConfirm') === '1')><label class="form-check-label" for="regYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailRegConfirm" value="0" id="regNo" @checked($p('prefsEmailRegConfirm') !== '1')><label class="form-check-label" for="regNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Contact Form CC</label>
                <div class="mb-4 row">
                    <label for="send-test-email" class="col-sm-4 col-form-label">SMTP Settings Test</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="send-test-email" value="1" id="testEmailYes"><label class="form-check-label" for="testEmailYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="send-test-email" value="0" id="testEmailNo" checked><label class="form-check-label" for="testEmailNo">No</label></div>
                        {{-- Legacy sends the test email directly from
                             send_test_email.admin.php (fancybox iframe);
                             ported as SendTestEmailController. --}}
                        <a data-fancybox data-type="iframe" class="modal-window-link hide-loader btn btn-primary" href="{{ route('admin.send_test_email.show') }}">Test Current Email Sending Settings</a>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Email Preferences</button>
                <button type="submit" class="btn btn-primary">Save Email Preferences</button>
            </form>
        @elseif ($go === 'payment')
            <form method="post" action="{{ url('/admin/site-preferences/payment') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label for="prefsCurrency" class="col-sm-4 col-form-label">Currency</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsCurrency" name="prefsCurrency" style="width:auto;">
                            @foreach (['$', 'R$', 'pound', 'czkoruna', 'euro', 'A$', 'C$', 'H$', 'N$', 'S$', 'T$', 'Ft', 'shekel', 'yen', 'nkr', 'kr', 'RM', 'M$', 'phpeso', 'pol', 'p.', 'skr', 'sfranc'] as $curr)
                                <option value="{{ $curr }}" @selected($p('prefsCurrency') === $curr)>{{ $curr }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsTransFee" class="col-sm-4 col-form-label">Checkout Fees Paid by Entrant</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsTransFee" value="Y" id="tfY" @checked($p('prefsTransFee') === 'Y')><label class="form-check-label" for="tfY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsTransFee" value="N" id="tfN" @checked($p('prefsTransFee') !== 'Y')><label class="form-check-label" for="tfN">Disable</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Pay to Print?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPayToPrint" value="1" id="ptpYes" @checked($p('prefsPayToPrint') === '1')><label class="form-check-label" for="ptpYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPayToPrint" value="0" id="ptpNo" @checked($p('prefsPayToPrint') !== '1')><label class="form-check-label" for="ptpNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Accept Cash?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCash" value="1" id="cashYes" @checked($p('prefsCash') === '1')><label class="form-check-label" for="cashYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCash" value="0" id="cashNo" @checked($p('prefsCash') !== '1')><label class="form-check-label" for="cashNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Accept Checks?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCheck" value="1" id="checkYes" @checked($p('prefsCheck') === '1')><label class="form-check-label" for="checkYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsCheck" value="0" id="checkNo" @checked($p('prefsCheck') !== '1')><label class="form-check-label" for="checkNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsCheckPayee" class="col-sm-4 col-form-label">Checks Payable To</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsCheckPayee" name="prefsCheckPayee" type="text" value="{{ $p('prefsCheckPayee') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Accept PayPal?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPaypal" value="1" id="ppYes" @checked($p('prefsPaypal') === '1')><label class="form-check-label" for="ppYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPaypal" value="0" id="ppNo" @checked($p('prefsPaypal') !== '1')><label class="form-check-label" for="ppNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsPaypalAccount" class="col-sm-4 col-form-label">PayPal Account</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsPaypalAccount" name="prefsPaypalAccount" type="text" value="{{ $p('prefsPaypalAccount') }}"></div>
                <div class="mb-4 row">
                    <label for="prefsPaypalIPN" class="col-sm-4 col-form-label">PayPal IPN</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPaypalIPN" value="1" id="ipnY" @checked($p('prefsPaypalIPN') === '1')><label class="form-check-label" for="ipnY">Enable</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsPaypalIPN" value="0" id="ipnN" @checked($p('prefsPaypalIPN') !== '1')><label class="form-check-label" for="ipnN">Disable</label></div>
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
                    <label for="prefsShowBestBrewer" class="col-sm-4 col-form-label">Best Brewer Display? Up to which Position?</label>
                    <div class="col-sm-8">
                        <select class="select select-bordered" name="prefsShowBestBrewer" id="prefsShowBestBrewer">{!! $positionOptions($p('prefsShowBestBrewer')) !!}</select>
                        <p class="help-block">Indicate whether you want to display the list of best brewers according to the points and tie break rules defined below and, if so, up to which position. They will be showed at the same time indicated above for the Winners Display.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestBrewerTitle" class="col-sm-4 col-form-label">Best Brewer Title</label>
                    <div class="col-sm-8">
                        <input class="input input-bordered" id="prefsBestBrewerTitle" name="prefsBestBrewerTitle" type="text" value="{{ $p('prefsBestBrewerTitle') }}">
                        <p class="help-block">Enter the title for the Best Brewer award (e.g., Heavy Medal, Ninkasi Award).</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsShowBestClub" class="col-sm-4 col-form-label">Best Club Display? Up to which Position?</label>
                    <div class="col-sm-8">
                        <select class="select select-bordered" name="prefsShowBestClub" id="prefsShowBestClub">{!! $positionOptions($p('prefsShowBestClub')) !!}</select>
                        <p class="help-block">Indicate whether you want to display the list of best clubs according to the points and tie break rules defined below and, if so, up to which position. They will be showed at the same time indicated above for the Winners Display. Applies ONLY to the amateur edition.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestClubTitle" class="col-sm-4 col-form-label">Best Club Title</label>
                    <div class="col-sm-8">
                        <input class="input input-bordered" id="prefsBestClubTitle" name="prefsBestClubTitle" type="text" value="{{ $p('prefsBestClubTitle') }}">
                        <p class="help-block">Enter the title for the Best Club award.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Include BOS in Calculations?</label>
                    <div class="col-sm-8">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsBestUseBOS" value="1" id="bbosYes" @checked($p('prefsBestUseBOS') === '1')><label class="form-check-label" for="bbosYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsBestUseBOS" value="0" id="bbosNo" @checked($p('prefsBestUseBOS') !== '1')><label class="form-check-label" for="bbosNo">No</label></div>
                        <p class="help-block">Indicate whether you wish to include any Best of Show (BOS) places in Best Brewer and Best Club calculations.</p>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Use Circuit of America Calculations?</label>
                    <div class="col-sm-8">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsScoringCOA" value="1" id="coaYes" @checked($p('prefsScoringCOA') === '1')><label class="form-check-label" for="coaYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsScoringCOA" value="0" id="coaNo" @checked($p('prefsScoringCOA') !== '1')><label class="form-check-label" for="coaNo">No</label></div>
                        <p class="help-block">Indicate whether you wish use the Master Homebrewer Program's <a href="https://www.masterhomebrewerprogram.com/circuit-of-america" target="_blank">Circuit of America</a> scoring methodolgy for all Best Brewer and Best Club calculations. <strong>Indicating "Yes" here will override all other calculation preferences.</strong></p>
                        <button type="button" class="btn btn-info btn-xs" data-open-modal="coa-info-modal">Circuit of America Calculations Info</button>
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
                            <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                            <div class="col-sm-8">
                                <select class="select select-bordered" name="{{ $field }}" id="{{ $field }}">
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
                        <label for="prefsTieBreakRule{{ $i }}" class="col-sm-4 col-form-label">Tie Break Rule #{{ $i }}</label>
                        <div class="col-sm-8">
                            <select class="select select-bordered" name="prefsTieBreakRule{{ $i }}" id="prefsTieBreakRule{{ $i }}">
                                @foreach ($tieBreakRules as $rule => $label)
                                    <option value="{{ $rule }}" @selected((string) $p('prefsTieBreakRule'.$i) === (string) $rule)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">Save Best Brewer/Club Preferences</button>
            </form>
            <dialog class="modal" id="coa-info-modal">
                <div class="modal-box">
                    <h3 class="font-bold">Circuit of America Scoring Info</h3>
                    <p>Use the Master Homebrewer Program's Circuit of America scoring methodology to determine Best Brewer and Best Club results.</p>
                    <div class="modal-action">
                        <form method="dialog"><button class="btn">Close</button></form>
                    </div>
                </div>
            </dialog>
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
