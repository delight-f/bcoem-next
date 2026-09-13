@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    // D1: the date fields below are flatpickr .date-time-picker-system
    // inputs (legacy competition_info.admin.php carried the same class);
    // prefill matches the picker dateFormat so the open calendar
    // highlights the stored wall time.
    $tf24 = ((int) $tf) === 1;
    $dt = fn (?string $key) => \App\Support\Tenant\DateFmt::dateTimeInput(
        $contest[$key] ?? null, $tz, $tf24,
    ) ?? '';
    $et = fn (?string $key) => \App\Support\Tenant\ContestRules::editText($contest[$key] ?? null);
    $rules = json_decode((string) ($contest['contestRules'] ?? ''), true) ?: [];
    $rulesText = fn (string $key) => \App\Support\Tenant\ContestRules::editText($rules[$key] ?? null);
    $required = 'This field is required.';
    // Legacy keeps the check-in password in a separate modal (go=qr); the
    // main form never carries it. A blank submit clears the stored hash.
    $hasQrPassword = ! empty($contest['contestCheckInPassword']);
    // Legacy additional_clubs: the saved club list, joined with "; " plus a
    // trailing "; " (the disabled field mirrors legacy exactly).
    $savedClubs = collect((array) json_decode((string) ($contest['contestClubs'] ?? ''), true) ?: [])
        ->filter(static fn ($c): bool => $c !== '')->values();
    $clubsValue = $savedClubs->implode('; ').($savedClubs->isNotEmpty() ? '; ' : '');
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4 bcoem-comp-info">
        <style>
            /* This form is long. Each section is a <details> collapsed by
               default so the page opens compact; the heading stays visible as
               the toggle. */
            .bcoem-comp-info details.bcoem-comp-info-section {
                margin-top: 1.25rem;
                border-top: 1px solid var(--bs-border-color);
                padding-top: .5rem;
            }
            .bcoem-comp-info summary { cursor: pointer; }
            .bcoem-comp-info h3 { margin-top: 1.75rem; padding-bottom: .5rem; border-bottom: 1px solid var(--bs-border-color); }
            .bcoem-comp-info summary h3 { margin: 0; }
            .bcoem-comp-info h3:first-of-type { margin-top: .5rem; }
        </style>
        <p class="lead">{{ $ctx->contestStr('contestName') }}: Update Competition Information</p>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Competition info updated.</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form data-time-24hr="{{ $tf24 ? '1' : '0' }}" method="post" action="{{ url('/admin/competition-info') }}" name="form1">
            @csrf
            @method('put')

            {{-- ============================ General ============================ --}}
            <details class="bcoem-comp-info-section">
                <summary><h3>General</h3></summary>
            <div class="row mb-3"><!-- Form Group REQUIRED Text Input -->
                <label for="contestName" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Competition Name</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control" id="contestName" name="contestName" type="text" maxlength="255" value="{{ $contest['contestName'] ?? '' }}" placeholder="" autofocus required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group -->
                <label for="contestID" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">BJCP Competition ID</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestID" name="contestID" type="text" value="{{ $contest['contestID'] ?? '' }}" placeholder="Current competition iteration BJCP ID.">
                    <div class="form-text">
                        <p>Be sure to enter the BJCP ID for the <strong>CURRENT</strong> competition iteration. Please note that the BJCP will reject any XML report with a missing or incorrect ID number.</p>
                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#BJCPCompIDModal">BJCP Competition ID Info</button>
                    </div>
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group REQUIRED Text Input -->
                <label for="contestHost" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Host</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control" id="contestHost" name="contestHost" type="text" maxlength="255" value="{{ $contest['contestHost'] ?? '' }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <label for="contestHostLocation" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Host Location</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestHostLocation" name="contestHostLocation" type="text" maxlength="255" value="{{ $contest['contestHostLocation'] ?? '' }}" placeholder="">
                </div>
            </div>

            <div class="row mb-3">
                <label for="contestHostWebsite" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Host Website Address</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestHostWebsite" name="contestHostWebsite" type="text" maxlength="255" value="{{ $contest['contestHostWebsite'] ?? '' }}" placeholder="http://www.yoursite.com">
                </div>
            </div>

            <div class="row mb-3">
                <label for="contestWinnerLink" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Link to Past Winners</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestWinnerLink" name="contestWinnerLink" type="text" maxlength="255" value="{{ $contest['contestWinnerLink'] ?? '' }}" placeholder="http://www.yoursite.com">
                    <span id="helpBlock" class="form-text">Website or URL of a previous winner list for this competition.</span>
                </div>
            </div>

            <div class="row mb-3">
                <label for="contestLogo" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Logo File Name</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <select class="form-select" name="contestLogo" id="contestLogo" data-live-search="true" data-size="10">
                        <option value=""></option>
                        @foreach ($images as $image)
                            <option value="{{ $image }}" @if (($contest['contestLogo'] ?? '') === $image) selected @endif>{{ $image }}</option>
                        @endforeach
                    </select>
                    <span class="form-text">Choose the image file. If the file is not on the list, upload it first.</span>
                    <div class="mt-2">
                        <a class="btn btn-sm btn-primary" href="{{ url('/admin/upload?action=html') }}"><span class="fa fa-upload"></span> Upload Logo Image</a>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <label for="QRModal" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">QR Code Log On Password</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#QRModal">Add, Update, or Change QR Code Log On Password</button>
                    @if ($hasQrPassword)
                        <span class="form-text d-block">A check-in password is set &mdash; this is the shared password volunteers enter at the <a href="{{ url('/qr') }}" target="_blank" rel="noopener">QR Code Entry Check-In</a> page. Leave the modal field blank to clear it, which disables QR check-in.</span>
                    @else
                        <span id="helpBlock" class="form-text">For use with the <a href="{{ url('/qr') }}" target="_blank" rel="noopener">QR Code Entry Check-In</a> function. No password is set yet, so QR check-in is unavailable until you set one here and share it with the volunteers scanning bottles. Passwords are stored hashed and cannot be viewed later &mdash; if it is forgotten, set a new one.</span>
                    @endif
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group Additional Club Names -->
                <label for="search-club-list-input" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Additional Club Names</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <p class="small text-body-secondary mb-1">
                        Clubs offered to entrants when they register, listed alongside the ones already
                        stored on participant profiles. Search first &mdash; add a name only if it is missing.
                    </p>
                    <div class="input-group">
                        <input id="search-club-list-input" class="form-control" placeholder="Search the clubs database">
                        <button type="button" id="clear-search-btn" class="btn btn-outline-secondary" disabled>Clear</button>
                        <button type="button" id="search-club-list-btn" class="btn btn-primary">Search Clubs</button>
                        <button type="button" id="copy-to-club-list-btn" class="btn btn-success" disabled><span class="fa fa-plus"></span> Add</button>
                    </div>
                    <div id="search-club-list-results-div" class="small mt-2"></div>

                    {{-- Legacy renders the accumulated list with each club followed by
                         "; " and keeps the field disabled — the search/add UI is the
                         only editor. Re-enabled at submit so its value posts. --}}
                    <label for="contestClubs" class="form-label small mt-3 mb-1">Clubs saved for this competition</label>
                    <input class="form-control" id="contestClubs" name="contestClubs" type="text" value="{{ $clubsValue }}" disabled>
                    <div class="form-text mb-2">Each club is separated by a semi-colon (;) for system use.</div>
                    <div>
                        <button type="button" id="clear-additional-clubs" class="btn btn-sm btn-outline-secondary">Clear Entire List</button>
                        <button type="button" id="restore-additional-clubs" class="btn btn-sm btn-outline-secondary" disabled>Restore List</button>
                        <button type="button" id="clear-last-added" class="btn btn-sm btn-outline-secondary" disabled>Clear Last Added</button>
                    </div>
                </div>
            </div>

            {{-- ============================ Entry Window ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Entry Window</h3></summary>
            <div class="row mb-3">
                <label for="contestEntryOpen" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Open Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestEntryOpen" name="contestEntryOpen" type="text" value="{{ $dt('contestEntryOpen') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestEntryDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestEntryDeadline" name="contestEntryDeadline" type="text" size="20" value="{{ $dt('contestEntryDeadline') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                    <span id="helpBlock" class="form-text">This date is only for restriction of adding <strong>new</strong> entries. Existing entries will be able to be edited beyond this date &ndash; until the drop-off/shipping deadlines &ndash; unless a specific entry editing close date is provided below.</span>
                </div>
            </div>

            {{-- ============================ Entry Editing ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Entry Editing</h3></summary>
            <div class="row mb-3">
                <label for="contestEntryEditDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestEntryEditDeadline" name="contestEntryEditDeadline" type="text" size="20" value="{{ $dt('contestEntryEditDeadline') }}" placeholder="">
                        <span id="helpBlock" class="form-text">If you wish to restrict editing of any exisiting entry's information by non-admin participants, provide a close date here. For example, this could allow competition staff to prepare for sorting prior to the entry drop-off/shipment closure dates.</span>
                    </div>
                </div>
            </div>

            {{-- ============================ Drop-Off Window ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Drop-Off Window</h3></summary>
            <div class="row mb-3">
                <label for="contestDropoffOpen" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Open Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control date-time-picker-system" id="contestDropoffOpen" name="contestDropoffOpen" type="text" value="{{ $dt('contestDropoffOpen') }}" placeholder="">
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestDropoffDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control date-time-picker-system" id="contestDropoffDeadline" name="contestDropoffDeadline" type="text" value="{{ $dt('contestDropoffDeadline') }}" placeholder="">
                </div>
            </div>

            {{-- ============================ Shipping Location ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Shipping Location</h3></summary>
            <div class="row mb-3">
                <label for="contestShippingName" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Name</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestShippingName" name="contestShippingName" type="text" value="{{ $contest['contestShippingName'] ?? '' }}" placeholder="">
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestShippingAddress" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Address</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestShippingAddress" name="contestShippingAddress" type="text" value="{{ $contest['contestShippingAddress'] ?? '' }}" placeholder="">
                </div>
            </div>

            {{-- ============================ Shipping Window ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Shipping Window</h3></summary>
            <div class="row mb-3">
                <label for="contestShippingOpen" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Open Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control date-time-picker-system" id="contestShippingOpen" name="contestShippingOpen" type="text" value="{{ $dt('contestShippingOpen') }}" placeholder="">
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestShippingDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control date-time-picker-system" id="contestShippingDeadline" name="contestShippingDeadline" type="text" value="{{ $dt('contestShippingDeadline') }}" placeholder="">
                    <span id="helpBlock" class="form-text">This window only applies to the Shipping Location above.</span>
                </div>
            </div>

            {{-- ============================ Account Registration ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Account Registration</h3></summary>
            <div class="row mb-3">
                <label for="contestRegistrationOpen" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Open Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestRegistrationOpen" name="contestRegistrationOpen" type="text" value="{{ $dt('contestRegistrationOpen') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestRegistrationDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestRegistrationDeadline" name="contestRegistrationDeadline" type="text" size="20" value="{{ $dt('contestRegistrationDeadline') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>

            {{-- ============================ Judge or Steward Account Registration ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Judge or Steward Account Registration</h3></summary>
            <div class="row mb-3">
                <label for="contestJudgeOpen" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Open Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestJudgeOpen" name="contestJudgeOpen" type="text" value="{{ $dt('contestJudgeOpen') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestJudgeDeadline" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Close Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <div class="input-group">
                        <input class="form-control date-time-picker-system" id="contestJudgeDeadline" name="contestJudgeDeadline" type="text" size="20" value="{{ $dt('contestJudgeDeadline') }}" placeholder="" required>
                        <span class="input-group-text" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star text-warning"></span></span>
                    </div>
                </div>
            </div>

            {{-- ============================ Rules and Other Information ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Rules and Other Information</h3></summary>
            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestRules" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Competition Rules</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="competition_rules" id="contestRules" :value="$rulesText('competition_rules')" :rows="15"
                        help="Edit the provided general rules text as needed. Use the toolbar for headings, lists and emphasis." />
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestBottles" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Entry Acceptance Rules</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestBottles" id="contestBottles" :value="$et('contestBottles')" :rows="15"
                        help="Indicate the number of bottles, size, color, etc. Edit default text as needed." />
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="competitionPackingShipping" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Packaging and Shipping Rules</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="competition_packing_shipping" id="competitionPackingShipping" :value="$rulesText('competition_packing_shipping')" :rows="15"
                        help="Edit the provided general rules text as needed." />
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestVolunteers" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Volunteer Information</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestVolunteers" id="contestVolunteers" :value="$et('contestVolunteers')" :rows="15"
                        help="Shown on the public Volunteers page." />
                </div>
            </div>

            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Entry Information</h3></summary>
            <p>Entry-related information has moved to <a href="{{ url('/admin/site-preferences/entries') }}">Entry Preferences</a>.</p>

            {{-- ============================ Awards Ceremony ============================ --}}
            </details>

            <details class="bcoem-comp-info-section">
                <summary><h3>Awards Ceremony</h3></summary>
            <div class="row mb-3">
                <label for="contestAwardsLocDate" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Date</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control date-time-picker-system" id="contestAwardsLocDate" name="contestAwardsLocDate" type="text" value="{{ $dt('contestAwardsLocDate') }}" placeholder="">
                    <span id="helpBlock" class="form-text">Provide even if the date of judging is the same.</span>
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestAwardsLocName" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Location Name</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestAwardsLocName" name="contestAwardsLocName" type="text" value="{{ $contest['contestAwardsLocName'] ?? '' }}" placeholder="">
                </div>
            </div>
            <div class="row mb-3">
                <label for="contestAwardsLocation" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Location Address</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <input class="form-control" id="contestAwardsLocation" name="contestAwardsLocation" type="text" value="{{ $contest['contestAwardsLocation'] ?? '' }}" placeholder="">
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestAwards" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Awards Structure</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestAwards" id="contestAwards" :value="$et('contestAwards')" :rows="15"
                        help="Indicate places for each category, BOS procedure, qualifying criteria, etc." />
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestBOSAward" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Best of Show</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestBOSAward" id="contestBOSAward" :value="$et('contestBOSAward')" :rows="15"
                        help="Indicate whether the Best of Show winner will receive a special award (e.g., a pro-am brew with a sponsoring brewery, etc.)." />
                </div>
            </div>

            <div class="row mb-3"><!-- Form Group NOT-REQUIRED Text Area -->
                <label for="contestCircuit" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Circuit Qualifying Events</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestCircuit" id="contestCircuit" :value="$et('contestCircuit')" :rows="15"
                        help="Indicate whether your competition is a qualifier for any national or regional competitions." />
                </div>
            </div>

            {{-- contestInfoExtra (PARITY-028): the port's DB-stored equivalent of
                 the legacy custom_competition_info.pub.php drop-in. Kept at the
                 end of the form, outside the legacy layout, as a port addition. --}}
            <div class="row mb-3">
                <label for="contestInfoExtra" class="col-12 col-md-4 col-lg-3 col-xl-2 col-form-label">Other Info</label>
                <div class="col-12 col-md-8 col-lg-6 col-xl-6">
                    <x-markdown-textarea name="contestInfoExtra" id="contestInfoExtra" :value="$et('contestInfoExtra')" :rows="6"
                        help="Optional extra competition-info block shown on the landing page (adds an &ldquo;Other Info&rdquo; nav link)." />
                </div>
            </div>

            </details>

            <div class="bcoem-admin-element d-print-none">
                <div class="row mb-3">
                    <div class="col-auto offset-md-4 offset-lg-3 offset-xl-2">
                        <input id="update-comp-info-btn" name="submit" type="submit" class="btn btn-primary" value="Update Competition Info">
                    </div>
                </div>
            </div>
        </form>

        {{-- BJCP Competition ID modal --}}
        <div class="modal fade" id="BJCPCompIDModal" tabindex="-1" role="dialog" aria-labelledby="BJCPCompIDModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="BJCPCompIDModalLabel">BJCP Competition ID Info</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Enter the Competition ID you received from the BJCP if you <a href="http://bjcp.org/apps/comp_reg/comp_reg.php" target="_blank" rel="noopener">registered your competition</a>. The BJCP will <em>not</em> accept an XML competition report without a Competition ID.</p>
                        <p><strong>Be sure to enter the BJCP ID for the CURRENT competition iteration.</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- QR Code log-on password modal (legacy go=qr) --}}
        <div class="modal fade" id="QRModal" tabindex="-1" role="dialog" aria-labelledby="QRModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="QRModalLabel">Add, Update, or Change QR Code Log On Password</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="{{ url('/admin/competition-info/qr-password') }}" name="form2">
                            @csrf
                            @method('put')
                            <div class="mb-3">
                                <label for="contestCheckInPassword" class="form-label">QR Code Log On Password</label>
                                <input class="form-control" id="contestCheckInPassword" name="contestCheckInPassword" type="password" value="" placeholder="">
                                @if ($hasQrPassword)
                                    <div class="form-text">Leave blank and save to clear the current password (this disables QR check-in).</div>
                                @else
                                    <div class="form-text">Provide the shared password volunteers will enter at the QR Code Entry Check-In page. It is stored hashed and cannot be viewed later, so if it is forgotten, set a new one.</div>
                                @endif
                            </div>
                            <button name="submit" type="submit" class="btn btn-primary">Update Password</button>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        <script>
            var bcoem_clubs = @json($clubs);
            document.addEventListener('DOMContentLoaded', function () {
                var input = document.getElementById('search-club-list-input');
                var resultsDiv = document.getElementById('search-club-list-results-div');
                var addBtn = document.getElementById('copy-to-club-list-btn');
                var clearSearchBtn = document.getElementById('clear-search-btn');
                var clubField = document.getElementById('contestClubs');
                var searchBtn = document.getElementById('search-club-list-btn');
                var clearListBtn = document.getElementById('clear-additional-clubs');
                var restoreListBtn = document.getElementById('restore-additional-clubs');
                var clearLastBtn = document.getElementById('clear-last-added');
                var mainForm = clubField.closest('form');
                var lastAdded = '';
                var savedValue = clubField.value;
                function refreshMatchState() {
                    var term = (input.value || '').trim();
                    addBtn.disabled = term === '';
                    clearSearchBtn.disabled = term === '';
                }
                // Without this the Add/Clear buttons start disabled and are
                // never re-enabled as the user types (issue 21).
                input.addEventListener('input', refreshMatchState);
                refreshMatchState();
                function escapeHtml(s) {
                    return String(s).replace(/[&<>"]/g, function (c) {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                    });
                }
                searchBtn.addEventListener('click', function () {
                    var term = (input.value || '').trim();
                    if (!term) { return; }
                    // Escape regex metacharacters: a club named e.g.
                    // "Brewers (County)" must search for that text rather
                    // than throw on an invalid pattern.
                    var re = new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
                    var out = '';
                    for (var i = 0; i < bcoem_clubs.length; i++) {
                        if (bcoem_clubs[i].search(re) !== -1) {
                            out += '<li>' + escapeHtml(bcoem_clubs[i]) + ';</li>';
                        }
                    }
                    if (out) {
                        resultsDiv.style.display = 'block';
                        resultsDiv.innerHTML = '<ul class="d-flex flex-wrap list-unstyled gap-2"><li><strong>Possible matches in the database:</strong></li> ' + out + '</ul>If none match, select the Add button to add the name you searched to the list above.';
                    } else {
                        resultsDiv.style.display = 'block';
                        resultsDiv.innerHTML = '<span class="text-danger">No clubs found in the database.</span>';
                    }
                });
                addBtn.addEventListener('click', function () {
                    var val = (input.value || '').trim();
                    if (!val) { return; }
                    lastAdded = val + ';';
                    var current = clubField.value;
                    if (current.indexOf(val) === -1) {
                        clubField.value = current ? current + val + '; ' : val + '; ';
                    }
                    resultsDiv.style.display = 'none';
                    input.value = '';
                    refreshMatchState();
                    clearListBtn.disabled = clubField.value === '';
                    restoreListBtn.disabled = false;
                    clearLastBtn.disabled = false;
                });
                clearSearchBtn.addEventListener('click', function () {
                    input.value = '';
                    resultsDiv.style.display = 'none';
                    refreshMatchState();
                });
                clearListBtn.addEventListener('click', function () {
                    clubField.value = '';
                    clearListBtn.disabled = true;
                    restoreListBtn.disabled = false;
                    clearLastBtn.disabled = true;
                });
                restoreListBtn.addEventListener('click', function () {
                    clubField.value = savedValue;
                    clearListBtn.disabled = clubField.value === '';
                    restoreListBtn.disabled = true;
                    clearLastBtn.disabled = true;
                });
                clearLastBtn.addEventListener('click', function () {
                    var current = clubField.value;
                    if (lastAdded && current.indexOf(lastAdded) !== -1) {
                        clubField.value = current.replace(lastAdded, '');
                    }
                    clubField.value = (clubField.value || '').trim() + ' ';
                    if (!clubField.value.trim()) { clearListBtn.disabled = true; }
                    clearLastBtn.disabled = true;
                });
                if (mainForm) {
                    mainForm.addEventListener('submit', function () { clubField.disabled = false; });
                }
            });
        </script>
    </section>
</x-public-layout>