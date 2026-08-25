@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $df = $ctx->prefsStr('prefsDateFormat');
    $tf = $ctx->prefsStr('prefsTimeFormat');
    $dt = fn (string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $ctx->judgingStr($key), $tz, $df, $tf, 'system', false,
    );
    $cdt = fn (string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $contest[$key] ?? null, $tz, $df, $tf, 'system', false,
    );
    $wdt = fn (string $key) => \App\Support\Tenant\DateFmt::dateTime(
        $ctx->prefsStr($key), $tz, $df, $tf, 'system', false,
    );
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-4 mb-3">
        <h1>{{ $ctx->contestStr('contestName') }}: Competition-Related Dates</h1>
        <p>All competition-related dates for various functions are listed below.</p>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Dates updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="post" action="{{ url('/admin/dates') }}">
            @csrf
            @method('put')

            <h3>Entry-Related</h3>
            <div class="mb-3 row">
                <label for="contestEntryOpen" class="col-sm-3 col-form-label">Entry Window Open</label>
                <div class="col-sm-9"><input class="form-control" id="contestEntryOpen" name="contestEntryOpen" type="text" value="{{ $cdt('contestEntryOpen') }}" required></div>
            </div>
            <div class="mb-3 row">
                <label for="contestEntryDeadline" class="col-sm-3 col-form-label">Entry Window Close</label>
                <div class="col-sm-9"><input class="form-control" id="contestEntryDeadline" name="contestEntryDeadline" type="text" value="{{ $cdt('contestEntryDeadline') }}" required></div>
            </div>
            <div class="mb-3 row">
                <label for="contestEntryEditDeadline" class="col-sm-3 col-form-label">Entry Edit Close Date</label>
                <div class="col-sm-9"><input class="form-control" id="contestEntryEditDeadline" name="contestEntryEditDeadline" type="text" value="{{ $cdt('contestEntryEditDeadline') }}"></div>
            </div>
            <div class="mb-3 row">
                <label for="contestDropoffOpen" class="col-sm-3 col-form-label">Drop-Off Window Open</label>
                <div class="col-sm-9"><input class="form-control" id="contestDropoffOpen" name="contestDropoffOpen" type="text" value="{{ $cdt('contestDropoffOpen') }}"></div>
            </div>
            <div class="mb-3 row">
                <label for="contestDropoffDeadline" class="col-sm-3 col-form-label">Drop-Off Window Close</label>
                <div class="col-sm-9"><input class="form-control" id="contestDropoffDeadline" name="contestDropoffDeadline" type="text" value="{{ $cdt('contestDropoffDeadline') }}"></div>
            </div>
            <div class="mb-3 row">
                <label for="contestShippingOpen" class="col-sm-3 col-form-label">Shipping Window Open</label>
                <div class="col-sm-9"><input class="form-control" id="contestShippingOpen" name="contestShippingOpen" type="text" value="{{ $cdt('contestShippingOpen') }}"></div>
            </div>
            <div class="mb-3 row">
                <label for="contestShippingDeadline" class="col-sm-3 col-form-label">Shipping Window Close</label>
                <div class="col-sm-9"><input class="form-control" id="contestShippingDeadline" name="contestShippingDeadline" type="text" value="{{ $cdt('contestShippingDeadline') }}"></div>
            </div>

            <h3>Account Registration</h3>
            <div class="mb-3 row">
                <label for="contestRegistrationOpen" class="col-sm-3 col-form-label">Entrant Open</label>
                <div class="col-sm-9"><input class="form-control" id="contestRegistrationOpen" name="contestRegistrationOpen" type="text" value="{{ $cdt('contestRegistrationOpen') }}" required></div>
            </div>
            <div class="mb-3 row">
                <label for="contestRegistrationDeadline" class="col-sm-3 col-form-label">Entrant Close</label>
                <div class="col-sm-9"><input class="form-control" id="contestRegistrationDeadline" name="contestRegistrationDeadline" type="text" value="{{ $cdt('contestRegistrationDeadline') }}" required></div>
            </div>
            <div class="mb-3 row">
                <label for="contestJudgeOpen" class="col-sm-3 col-form-label">Judge/Steward Open</label>
                <div class="col-sm-9"><input class="form-control" id="contestJudgeOpen" name="contestJudgeOpen" type="text" value="{{ $cdt('contestJudgeOpen') }}" required></div>
            </div>
            <div class="mb-3 row">
                <label for="contestJudgeDeadline" class="col-sm-3 col-form-label">Judge/Steward Close</label>
                <div class="col-sm-9"><input class="form-control" id="contestJudgeDeadline" name="contestJudgeDeadline" type="text" value="{{ $cdt('contestJudgeDeadline') }}" required></div>
            </div>

            <h3>Judging Sessions</h3>
            @if ($sessions->isEmpty())
                <p>No judging sessions have been defined.</p>
            @else
                @foreach ($sessions as $session)
                    <fieldset class="mb-3 border rounded p-3">
                        <legend class="small">{{ $session->judgingLocName }} ({{ ((int) $session->judgingLocType) === 1 ? 'Distributed' : 'Traditional' }})</legend>
                        <input type="hidden" name="id[]" value="{{ $session->id }}">
                        <div class="mb-2 row">
                            <label for="judgingDate{{ $session->id }}" class="col-sm-3 col-form-label">Starts</label>
                            <div class="col-sm-9"><input class="form-control" id="judgingDate{{ $session->id }}" name="judgingDate{{ $session->id }}" type="text"
                                value="{{ \App\Support\Tenant\DateFmt::dateTime($session->judgingDate, $tz, $df, $tf, 'system', false) }}"></div>
                        </div>
                        <div class="mb-2 row">
                            <label for="judgingDateEnd{{ $session->id }}" class="col-sm-3 col-form-label">Ends</label>
                            <div class="col-sm-9"><input class="form-control" id="judgingDateEnd{{ $session->id }}" name="judgingDateEnd{{ $session->id }}" type="text"
                                value="{{ \App\Support\Tenant\DateFmt::dateTime($session->judgingDateEnd, $tz, $df, $tf, 'system', false) }}"></div>
                        </div>
                    </fieldset>
                @endforeach
            @endif

            <h3>Judging Window</h3>
            <div class="mb-3 row">
                <label for="jPrefsJudgingOpen" class="col-sm-3 col-form-label">Judging Open</label>
                <div class="col-sm-9"><input class="form-control" id="jPrefsJudgingOpen" name="jPrefsJudgingOpen" type="text" value="{{ $dt('jPrefsJudgingOpen') }}"></div>
            </div>
            <div class="mb-3 row">
                <label for="jPrefsJudgingClosed" class="col-sm-3 col-form-label">Judging Close</label>
                <div class="col-sm-9"><input class="form-control" id="jPrefsJudgingClosed" name="jPrefsJudgingClosed" type="text" value="{{ $dt('jPrefsJudgingClosed') }}"></div>
            </div>

            <h3>Results Publish</h3>
            <div class="mb-3 row">
                <label for="prefsWinnerDelay" class="col-sm-3 col-form-label">Winners Display Date</label>
                <div class="col-sm-9"><input class="form-control" id="prefsWinnerDelay" name="prefsWinnerDelay" type="text" value="{{ $wdt('prefsWinnerDelay') }}"></div>
            </div>

            <button type="submit" class="btn btn-primary">Save Dates</button>
        </form>
    </section>
</x-public-layout>
