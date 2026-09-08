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
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Competition Info</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Competition info updated.</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form data-time-24hr="{{ $tf24 ? '1' : '0' }}" method="post" action="{{ url('/admin/competition-info') }}">
            @csrf
            @method('put')
            <div class="alert alert-info">
                Entry-related information has moved to <a href="{{ url('/admin/site-preferences/entries') }}">Entry Preferences</a>.
            </div>

            <h3>General</h3>
            <div class="mb-4 row">
                <label for="contestName" class="col-md-4 col-form-label">Competition Name</label>
                <div class="col-md-9"><input class="form-control" id="contestName" name="contestName" type="text" value="{{ $contest['contestName'] ?? '' }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHost" class="col-md-4 col-form-label">Host Organization</label>
                <div class="col-md-9"><input class="form-control" id="contestHost" name="contestHost" type="text" value="{{ $contest['contestHost'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHostWebsite" class="col-md-4 col-form-label">Host Website</label>
                <div class="col-md-9"><input class="form-control" id="contestHostWebsite" name="contestHostWebsite" type="text" value="{{ $contest['contestHostWebsite'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHostLocation" class="col-md-4 col-form-label">Host Location</label>
                <div class="col-md-9"><input class="form-control" id="contestHostLocation" name="contestHostLocation" type="text" value="{{ $contest['contestHostLocation'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestID" class="col-md-4 col-form-label">Competition ID</label>
                <div class="col-md-9"><input class="form-control" id="contestID" name="contestID" type="text" value="{{ $contest['contestID'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestLogo" class="col-md-4 col-form-label">Competition Logo File Name</label>
                <div class="col-md-9"><input class="form-control" id="contestLogo" name="contestLogo" type="text" value="{{ $contest['contestLogo'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestCheckInPassword" class="col-md-4 col-form-label">QR Code Log On Password</label>
                <div class="col-md-9">
                    <input class="form-control" id="contestCheckInPassword" name="contestCheckInPassword" type="password">
                    <span class="form-text">Leave blank to clear (stored hashed). For use with the <a class="hide-loader" href="{{ url('/qr') }}" target="_blank" rel="noopener">QR Code Entry Check-In</a> function.</span>
                </div>
            </div>

            <h3>Rules</h3>
            @php $rules = json_decode((string) ($contest['contestRules'] ?? ''), true) ?: []; @endphp
            <div class="mb-4 row">
                <label for="competition_rules" class="col-md-4 col-form-label">Competition Rules</label>
                <div class="col-md-9"><textarea class="form-control" id="competition_rules" name="competition_rules" rows="8">{{ $rules['competition_rules'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="competition_packing_shipping" class="col-md-4 col-form-label">Packing &amp; Shipping Instructions</label>
                <div class="col-md-9"><textarea class="form-control" id="competition_packing_shipping" name="competition_packing_shipping" rows="8">{{ $rules['competition_packing_shipping'] ?? '' }}</textarea></div>
            </div>

            <h3>Dates</h3>
            @foreach ([
                'contestRegistrationOpen' => 'Registration Open',
                'contestRegistrationDeadline' => 'Registration Deadline',
                'contestEntryOpen' => 'Entry Window Open',
                'contestEntryDeadline' => 'Entry Window Close',
                'contestEntryEditDeadline' => 'Entry Edit Close',
                'contestJudgeOpen' => 'Judge/Steward Open',
                'contestJudgeDeadline' => 'Judge/Steward Close',
                'contestDropoffOpen' => 'Drop-Off Window Open',
                'contestDropoffDeadline' => 'Drop-Off Window Close',
                'contestShippingOpen' => 'Shipping Window Open',
                'contestShippingDeadline' => 'Shipping Window Close',
                'contestAwardsLocDate' => 'Awards Date',
            ] as $field => $label)
                <div class="mb-4 row">
                    <label for="{{ $field }}" class="col-md-4 col-form-label">{{ $label }}</label>
                    <div class="col-md-9"><input class="form-control date-time-picker-system" id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $dt($field) }}"></div>
                </div>
            @endforeach

            <h3>Awards</h3>
            <div class="mb-4 row">
                <label for="contestAwardsLocation" class="col-md-4 col-form-label">Awards Location</label>
                <div class="col-md-9"><input class="form-control" id="contestAwardsLocation" name="contestAwardsLocation" type="text" value="{{ $contest['contestAwardsLocation'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestAwardsLocName" class="col-md-4 col-form-label">Awards Venue Name</label>
                <div class="col-md-9"><input class="form-control" id="contestAwardsLocName" name="contestAwardsLocName" type="text" value="{{ $contest['contestAwardsLocName'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestAwards" class="col-md-4 col-form-label">Awards Info</label>
                <div class="col-md-9"><textarea class="form-control" id="contestAwards" name="contestAwards" rows="4">{{ $contest['contestAwards'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestBOSAward" class="col-md-4 col-form-label">Best of Show Awards</label>
                <div class="col-md-9"><textarea class="form-control" id="contestBOSAward" name="contestBOSAward" rows="4">{{ $contest['contestBOSAward'] ?? '' }}</textarea></div>
            </div>

            <h3>Shipping &amp; Drop-Off</h3>
            <div class="mb-4 row">
                <label for="contestShippingName" class="col-md-4 col-form-label">Shipping Contact</label>
                <div class="col-md-9"><input class="form-control" id="contestShippingName" name="contestShippingName" type="text" value="{{ $contest['contestShippingName'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestShippingAddress" class="col-md-4 col-form-label">Shipping Address</label>
                <div class="col-md-9"><textarea class="form-control" id="contestShippingAddress" name="contestShippingAddress" rows="3">{{ $contest['contestShippingAddress'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestBottles" class="col-md-4 col-form-label">Bottle Requirements</label>
                <div class="col-md-9"><textarea class="form-control" id="contestBottles" name="contestBottles" rows="4">{{ $contest['contestBottles'] ?? '' }}</textarea></div>
            </div>

            <h3>Other</h3>
            <div class="mb-4 row">
                <label for="contestCircuit" class="col-md-4 col-form-label">Circuits</label>
                <div class="col-md-9"><textarea class="form-control" id="contestCircuit" name="contestCircuit" rows="3">{{ $contest['contestCircuit'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestVolunteers" class="col-md-4 col-form-label">Volunteer Info</label>
                <div class="col-md-9"><textarea class="form-control" id="contestVolunteers" name="contestVolunteers" rows="3">{{ $contest['contestVolunteers'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestClubs" class="col-md-4 col-form-label">Homebrew Clubs</label>
                <div class="col-md-9">
                    <input class="form-control" id="contestClubs" name="contestClubs" type="text" value="{{ implode(';', (array) json_decode((string) ($contest['contestClubs'] ?? ''), true) ?: []) }}">
                    <span class="form-text">Semicolon-separated list.</span>
                </div>
            </div>
            <div class="mb-4 row">
                <label for="search-club-list-input" class="col-md-4 col-form-label">Additional Club Names</label>
                <div class="col-md-9">
                    <input id="search-club-list-input" class="form-control" placeholder="Search the clubs database">
                    <span class="form-text">Search to check if a club is already in the database. <button type="button" id="clear-search-btn" class="btn btn-sm btn-secondary" disabled>Clear the Search Field</button></span>
                    <button type="button" id="search-club-list-btn" class="btn btn-sm btn-primary">Search Clubs</button>
                    <button type="button" id="copy-to-club-list-btn" class="btn btn-sm btn-success" disabled><span class="fa fa-plus"></span> Add</button>
                    <div id="search-club-list-results-div"></div>
                </div>
            </div>
            <div class="mb-4 row">
                <label for="contestInfoExtra" class="col-md-4 col-form-label">Other Info</label>
                <div class="col-md-9">
                    <textarea class="form-control" id="contestInfoExtra" name="contestInfoExtra" rows="4">{{ $contest['contestInfoExtra'] ?? '' }}</textarea>
                    <div class="form-text">Optional extra competition-info block (PARITY-028: the legacy <code>custom_competition_info.pub.php</code> drop-in, DB-stored). When set, it renders on the landing page's competition-info surface and adds an "Other Info" nav link. HTML is allowed.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Competition Info</button>
        </form>
        <script>
            var bcoem_clubs = @json($clubs);
            document.addEventListener('DOMContentLoaded', function () {
                var input = document.getElementById('search-club-list-input');
                var resultsDiv = document.getElementById('search-club-list-results-div');
                var addBtn = document.getElementById('copy-to-club-list-btn');
                var clearBtn = document.getElementById('clear-search-btn');
                var clubField = document.getElementById('contestClubs');
                var searchBtn = document.getElementById('search-club-list-btn');
                var lastAdded = '';
                function refreshMatchState() {
                    var term = (input.value || '').trim();
                    addBtn.disabled = term === '';
                    clearBtn.disabled = term === '';
                }
                searchBtn.addEventListener('click', function () {
                    var term = (input.value || '').trim();
                    if (!term) { return; }
                    var re = new RegExp(term, 'i');
                    var out = '';
                    for (var i = 0; i < bcoem_clubs.length; i++) {
                        if (bcoem_clubs[i].search(re) !== -1) {
                            out += '<li>' + bcoem_clubs[i] + ';</li>';
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
                });
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    resultsDiv.style.display = 'none';
                    refreshMatchState();
                });
            });
        </script>
    </section>
</x-public-layout>
