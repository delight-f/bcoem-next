@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $df = $ctx->prefsStr('prefsDateFormat');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    $dt = fn (string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $contest[$key] ?? null, $tz, $df, $tf, 'system', false,
    );
    $go = $go ?? 'default';
    $tabs = ['default' => 'Display', 'entries' => 'Entries', 'email' => 'Email', 'payment' => 'Payment', 'best' => 'Best Brewer/Club'];
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
                    <label for="prefsWinnerMethod" class="col-sm-4 col-form-label">Winners Display Method</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="prefsWinnerMethod" name="prefsWinnerMethod" style="width:auto;">
                            <option value="0" @selected($p('prefsWinnerMethod') === '0')>All placing entries</option>
                            <option value="1" @selected($p('prefsWinnerMethod') === '1')>Winners only</option>
                            <option value="2" @selected($p('prefsWinnerMethod') === '2')>Winners + Best Brewer/Club</option>
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
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsLanguage" name="prefsLanguage" type="text" style="width:auto;" value="{{ $p('prefsLanguage') }}"></div>
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
                    <label for="prefsTimeZone" class="col-sm-4 col-form-label">Time Zone (UTC offset)</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsTimeZone" name="prefsTimeZone" type="text" style="width:auto;" value="{{ $p('prefsTimeZone') }}"></div>
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
                    <label for="contestEntryFee" class="col-sm-4 col-form-label">Entry Fee</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFee" name="contestEntryFee" type="text" style="width:auto;" value="{{ $c('contestEntryFee') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFee2" class="col-sm-4 col-form-label">Discounted Entry Fee</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFee2" name="contestEntryFee2" type="text" style="width:auto;" value="{{ $c('contestEntryFee2') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryFeeDiscountNum" class="col-sm-4 col-form-label">Discount Threshold (entries)</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryFeeDiscountNum" name="contestEntryFeeDiscountNum" type="number" min="1" style="width:auto;" value="{{ $c('contestEntryFeeDiscountNum') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label for="contestEntryCap" class="col-sm-4 col-form-label">Competition Entry Cap</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contestEntryCap" name="contestEntryCap" type="number" min="1" style="width:auto;" value="{{ $c('contestEntryCap') }}"></div>
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
                    'prefsEntryLimit' => 'Competition Entry Limit',
                    'prefsEntryLimitPaid' => 'Paid Entries Limit',
                    'prefsUserEntryLimit' => 'Entries per Participant',
                    'prefsUserSubCatLimit' => 'Entries per Subcategory',
                ] as $field => $label)
                    <div class="mb-4 row">
                        <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                        <div class="col-sm-9"><input class="input input-bordered" id="{{ $field }}" name="{{ $field }}" type="number" min="1" style="width:auto;" value="{{ $p($field) }}"></div>
                    </div>
                @endforeach

                <h3>Per-style limits</h3>
                <div class="mb-4 row">
                    <label for="choose-style-entry-limits" class="col-sm-4 col-form-label">Method</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="choose-style-entry-limits" name="choose-style-entry-limits" style="width:auto;">
                            <option value="0" @selected($p('prefsStyleLimits') === '')>No per-style limits</option>
                            <option value="1" @selected(str_starts_with($p('prefsStyleLimits'), '{'))>By medal group / style</option>
                            <option value="2" @selected($p('prefsStyleLimits') === '2')>By table or medal group</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Entry Preferences</button>
            </form>
        @elseif ($go === 'email')
            <form method="post" action="{{ url('/admin/site-preferences/email') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Use SMTP Email?</label>
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
                    <label for="prefsEmailUsername" class="col-sm-4 col-form-label">Username</label>
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
                    <label for="prefsEmailFrom" class="col-sm-4 col-form-label">From Address</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsEmailFrom" name="prefsEmailFrom" type="text" value="{{ $p('prefsEmailFrom') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Contact Form</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
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
                    <label class="col-sm-4 col-form-label">CC Admin on Emails</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailCC" value="1" id="ccYes" @checked($p('prefsEmailCC') === '1')><label class="form-check-label" for="ccYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsEmailCC" value="0" id="ccNo" @checked($p('prefsEmailCC') !== '1')><label class="form-check-label" for="ccNo">No</label></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Email Preferences</button>
            </form>
        @elseif ($go === 'payment')
            <form method="post" action="{{ url('/admin/site-preferences/payment') }}">
                @csrf
                @method('put')
                @foreach ([
                    'prefsCurrency' => ['Currency code', 'text'],
                    'prefsTransFee' => ['Transaction Fee', 'text'],
                ] as $field => [$label, $type])
                    <div class="mb-4 row">
                        <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                        <div class="col-sm-9"><input class="input input-bordered" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" style="width:auto;" value="{{ $p($field) }}"></div>
                    </div>
                @endforeach
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
                </div>
                <button type="submit" class="btn btn-primary">Save Payment Preferences</button>
            </form>
        @else
            <form method="post" action="{{ url('/admin/site-preferences/best') }}">
                @csrf
                @method('put')
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Show Best Brewer?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsShowBestBrewer" value="1" id="bbYes" @checked($p('prefsShowBestBrewer') === '1')><label class="form-check-label" for="bbYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsShowBestBrewer" value="0" id="bbNo" @checked($p('prefsShowBestBrewer') !== '1')><label class="form-check-label" for="bbNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestBrewerTitle" class="col-sm-4 col-form-label">Best Brewer Title</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsBestBrewerTitle" name="prefsBestBrewerTitle" type="text" value="{{ $p('prefsBestBrewerTitle') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Show Best Club?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsShowBestClub" value="1" id="bcYes" @checked($p('prefsShowBestClub') === '1')><label class="form-check-label" for="bcYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsShowBestClub" value="0" id="bcNo" @checked($p('prefsShowBestClub') !== '1')><label class="form-check-label" for="bcNo">No</label></div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="prefsBestClubTitle" class="col-sm-4 col-form-label">Best Club Title</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="prefsBestClubTitle" name="prefsBestClubTitle" type="text" value="{{ $p('prefsBestClubTitle') }}"></div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">Use BOS in Best Brewer/Club Scoring?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsBestUseBOS" value="1" id="bbosYes" @checked($p('prefsBestUseBOS') === '1')><label class="form-check-label" for="bbosYes">Yes</label></div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="prefsBestUseBOS" value="0" id="bbosNo" @checked($p('prefsBestUseBOS') !== '1')><label class="form-check-label" for="bbosNo">No</label></div>
                    </div>
                </div>
                @foreach ([
                    'prefsFirstPlacePts' => 'First Place Points',
                    'prefsSecondPlacePts' => 'Second Place Points',
                    'prefsThirdPlacePts' => 'Third Place Points',
                    'prefsFourthPlacePts' => 'Fourth Place Points',
                    'prefsHMPts' => 'Honorable Mention Points',
                ] as $field => $label)
                    <div class="mb-4 row">
                        <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                        <div class="col-sm-9"><input class="input input-bordered" id="{{ $field }}" name="{{ $field }}" type="number" min="0" style="width:auto;" value="{{ $p($field) }}"></div>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">Save Best Brewer/Club Preferences</button>
            </form>
        @endif
    </section>
</x-public-layout>
