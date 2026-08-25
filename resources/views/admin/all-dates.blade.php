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

    // datetime-local values (Y-m-d\TH:i) in the tenant timezone for native
    // date/time pickers; the controller parses them back via DateTimeImmutable,
    // so the round-trip is lossless.
    $toLocal = fn ($epoch) => $epoch === null || $epoch === '' ? '' : (new \DateTimeImmutable('@'.$epoch))
        ->setTimezone(new \DateTimeZone(\App\Support\Tenant\DateFmt::tz($tz)))->format('Y-m-d\\TH:i');
    $isoCdt = fn (string $key) => $toLocal($contest[$key] ?? null);
    $isoJdt = fn (string $key) => $toLocal($ctx->judgingStr($key));
    $isoWdt = fn (string $key) => $toLocal($ctx->prefsStr($key));
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Competition-Related Dates</h1>
        <p>All competition-related dates for various functions are listed below.</p>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Dates updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="post" action="{{ url('/admin/dates') }}">
            @csrf
            @method('put')

            <h3>Entry-Related</h3>
            <div class="mb-4 row">
                <label for="contestEntryOpen" class="col-sm-4 col-form-label">Entry Window Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestEntryOpen" name="contestEntryOpen" type="datetime-local" value="{{ $isoCdt('contestEntryOpen') }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestEntryDeadline" class="col-sm-4 col-form-label">Entry Window Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestEntryDeadline" name="contestEntryDeadline" type="datetime-local" value="{{ $isoCdt('contestEntryDeadline') }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestEntryEditDeadline" class="col-sm-4 col-form-label">Entry Edit Close Date</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestEntryEditDeadline" name="contestEntryEditDeadline" type="datetime-local" value="{{ $isoCdt('contestEntryEditDeadline') }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestDropoffOpen" class="col-sm-4 col-form-label">Drop-Off Window Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestDropoffOpen" name="contestDropoffOpen" type="datetime-local" value="{{ $isoCdt('contestDropoffOpen') }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestDropoffDeadline" class="col-sm-4 col-form-label">Drop-Off Window Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestDropoffDeadline" name="contestDropoffDeadline" type="datetime-local" value="{{ $isoCdt('contestDropoffDeadline') }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestShippingOpen" class="col-sm-4 col-form-label">Shipping Window Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestShippingOpen" name="contestShippingOpen" type="datetime-local" value="{{ $isoCdt('contestShippingOpen') }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="contestShippingDeadline" class="col-sm-4 col-form-label">Shipping Window Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestShippingDeadline" name="contestShippingDeadline" type="datetime-local" value="{{ $isoCdt('contestShippingDeadline') }}"></div>
            </div>

            <h3>Account Registration</h3>
            <div class="mb-4 row">
                <label for="contestRegistrationOpen" class="col-sm-4 col-form-label">Entrant Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestRegistrationOpen" name="contestRegistrationOpen" type="datetime-local" value="{{ $isoCdt('contestRegistrationOpen') }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestRegistrationDeadline" class="col-sm-4 col-form-label">Entrant Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestRegistrationDeadline" name="contestRegistrationDeadline" type="datetime-local" value="{{ $isoCdt('contestRegistrationDeadline') }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestJudgeOpen" class="col-sm-4 col-form-label">Judge/Steward Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestJudgeOpen" name="contestJudgeOpen" type="datetime-local" value="{{ $isoCdt('contestJudgeOpen') }}" required></div>
            </div>
            <div class="mb-4 row">
                <label for="contestJudgeDeadline" class="col-sm-4 col-form-label">Judge/Steward Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="contestJudgeDeadline" name="contestJudgeDeadline" type="datetime-local" value="{{ $isoCdt('contestJudgeDeadline') }}" required></div>
            </div>

            <h3>Judging Sessions</h3>
            @if ($sessions->isEmpty())
                <p>No judging sessions have been defined.</p>
            @else
                @foreach ($sessions as $session)
                    <fieldset class="mb-4 border rounded p-4">
                        <legend class="text-sm">{{ $session->judgingLocName }} ({{ ((int) $session->judgingLocType) === 1 ? 'Distributed' : 'Traditional' }})</legend>
                        <input type="hidden" name="id[]" value="{{ $session->id }}">
                        <div class="mb-2 row">
                            <label for="judgingDate{{ $session->id }}" class="col-sm-4 col-form-label">Starts</label>
                            <div class="col-sm-9"><input class="input input-bordered" id="judgingDate{{ $session->id }}" name="judgingDate{{ $session->id }}" type="datetime-local" value="{{ $toLocal($session->judgingDate) }}"></div>
                        </div>
                        <div class="mb-2 row">
                            <label for="judgingDateEnd{{ $session->id }}" class="col-sm-4 col-form-label">Ends</label>
                            <div class="col-sm-9"><input class="input input-bordered" id="judgingDateEnd{{ $session->id }}" name="judgingDateEnd{{ $session->id }}" type="datetime-local" value="{{ $toLocal($session->judgingDateEnd) }}"></div>
                        </div>
                    </fieldset>
                @endforeach
            @endif

            <h3>Judging Window</h3>
            <div class="mb-4 row">
                <label for="jPrefsJudgingOpen" class="col-sm-4 col-form-label">Judging Open</label>
                <div class="col-sm-9"><input class="input input-bordered" id="jPrefsJudgingOpen" name="jPrefsJudgingOpen" type="datetime-local" value="{{ $isoJdt('jPrefsJudgingOpen') }}"></div>
            </div>
            <div class="mb-4 row">
                <label for="jPrefsJudgingClosed" class="col-sm-4 col-form-label">Judging Close</label>
                <div class="col-sm-9"><input class="input input-bordered" id="jPrefsJudgingClosed" name="jPrefsJudgingClosed" type="datetime-local" value="{{ $isoJdt('jPrefsJudgingClosed') }}"></div>
            </div>

            <h3>Results Publish</h3>
            <div class="mb-4 row">
                <label for="prefsWinnerDelay" class="col-sm-4 col-form-label">Winners Display Date</label>
                <div class="col-sm-9"><input class="input input-bordered" id="prefsWinnerDelay" name="prefsWinnerDelay" type="datetime-local" value="{{ $isoWdt('prefsWinnerDelay') }}"></div>
            </div>

            <button type="submit" class="btn btn-primary">Save Dates</button>
        </form>
    </section>
</x-public-layout>
