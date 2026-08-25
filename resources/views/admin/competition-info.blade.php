@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $df = $ctx->prefsStr('prefsDateFormat');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    $dt = fn (?string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $contest[$key] ?? null, $tz, $df, $tf, 'system', false,
    );
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Competition Info</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Competition info updated.</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="post" action="{{ url('/admin/competition-info') }}">
            @csrf
            @method('put')

            <h3>General</h3>
            <div class="mb-4 row">
                <label for="contestName" class="col-sm-4 col-form-label">Competition Name</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestName" name="contestName" type="text" value="{{ $contest['contestName'] ?? '' }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHost" class="col-sm-4 col-form-label">Host Organization</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestHost" name="contestHost" type="text" value="{{ $contest['contestHost'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHostWebsite" class="col-sm-4 col-form-label">Host Website</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestHostWebsite" name="contestHostWebsite" type="text" value="{{ $contest['contestHostWebsite'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestHostLocation" class="col-sm-4 col-form-label">Host Location</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestHostLocation" name="contestHostLocation" type="text" value="{{ $contest['contestHostLocation'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestID" class="col-sm-4 col-form-label">Competition ID</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestID" name="contestID" type="text" value="{{ $contest['contestID'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestLogo" class="col-sm-4 col-form-label">Competition Logo File Name</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestLogo" name="contestLogo" type="text" value="{{ $contest['contestLogo'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestCheckInPassword" class="col-sm-4 col-form-label">Entry Check-In Password</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="contestCheckInPassword" name="contestCheckInPassword" type="password">
                    <span class="help-block">Leave blank to clear (stored hashed).</span>
                </div>
            </div>

            <h3>Rules</h3>
            @php $rules = json_decode((string) ($contest['contestRules'] ?? ''), true) ?: []; @endphp
            <div class="mb-4 row">
                <label for="competition_rules" class="col-sm-4 col-form-label">Competition Rules</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="competition_rules" name="competition_rules" rows="8">{{ $rules['competition_rules'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="competition_packing_shipping" class="col-sm-4 col-form-label">Packing &amp; Shipping Instructions</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="competition_packing_shipping" name="competition_packing_shipping" rows="8">{{ $rules['competition_packing_shipping'] ?? '' }}</textarea></div>
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
                    <label for="{{ $field }}" class="col-sm-4 col-form-label">{{ $label }}</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $dt($field) }}"></div>
                </div>
            @endforeach

            <h3>Awards</h3>
            <div class="mb-4 row">
                <label for="contestAwardsLocation" class="col-sm-4 col-form-label">Awards Location</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestAwardsLocation" name="contestAwardsLocation" type="text" value="{{ $contest['contestAwardsLocation'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestAwardsLocName" class="col-sm-4 col-form-label">Awards Venue Name</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestAwardsLocName" name="contestAwardsLocName" type="text" value="{{ $contest['contestAwardsLocName'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestAwards" class="col-sm-4 col-form-label">Awards Info</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestAwards" name="contestAwards" rows="4">{{ $contest['contestAwards'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestBOSAward" class="col-sm-4 col-form-label">Best of Show Awards</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestBOSAward" name="contestBOSAward" rows="4">{{ $contest['contestBOSAward'] ?? '' }}</textarea></div>
            </div>

            <h3>Shipping &amp; Drop-Off</h3>
            <div class="mb-4 row">
                <label for="contestShippingName" class="col-sm-4 col-form-label">Shipping Contact</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestShippingName" name="contestShippingName" type="text" value="{{ $contest['contestShippingName'] ?? '' }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestShippingAddress" class="col-sm-4 col-form-label">Shipping Address</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestShippingAddress" name="contestShippingAddress" rows="3">{{ $contest['contestShippingAddress'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestBottles" class="col-sm-4 col-form-label">Bottle Requirements</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestBottles" name="contestBottles" rows="4">{{ $contest['contestBottles'] ?? '' }}</textarea></div>
            </div>

            <h3>Other</h3>
            <div class="mb-4 row">
                <label for="contestCircuit" class="col-sm-4 col-form-label">Circuits</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestCircuit" name="contestCircuit" rows="3">{{ $contest['contestCircuit'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestVolunteers" class="col-sm-4 col-form-label">Volunteer Info</label>
                <div class="col-sm-9"><textarea class="textarea textarea-bordered" id="contestVolunteers" name="contestVolunteers" rows="3">{{ $contest['contestVolunteers'] ?? '' }}</textarea></div>
            </div>
            <div class="mb-4 row">
                <label for="contestClubs" class="col-sm-4 col-form-label">Homebrew Clubs</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="contestClubs" name="contestClubs" type="text" value="{{ implode(';', (array) json_decode((string) ($contest['contestClubs'] ?? ''), true) ?: []) }}">
                    <span class="help-block">Semicolon-separated list.</span>
                </div>
            </div>
            <div class="mb-4 row">
                <label for="contestWinnerLink" class="col-sm-4 col-form-label">Past Winners Link</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestWinnerLink" name="contestWinnerLink" type="text" value="{{ $contest['contestWinnerLink'] ?? '' }}"></div>
            </div>

            <button type="submit" class="btn btn-primary">Save Competition Info</button>
        </form>
    </section>
</x-public-layout>
