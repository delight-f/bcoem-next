@php
    // D1: flatpickr (app.js .date-time-picker-system) drives these inputs
    // with dateFormat 'Y-m-d H:i' (24h) or 'Y-m-d h:i K' (12h), chosen by
    // the form's data-time-24hr below. Prefill uses the matching
    // DateFmt::dateTimeInput format so the open calendar highlights the
    // stored wall time (legacy: getTimeZoneDateTime(...,'system',
    // 'date-time-system')).
    $isEdit = $location !== null;
    $tf24 = ((int) $ctx->prefsStr('prefsTimeFormat')) === 1;
@endphp
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $nonJudging ? 'Non-Judging Sessions' : 'Judging Sessions' }}: {{ $isEdit ? 'Edit' : 'Add' }} a {{ $nonJudging ? 'Non-Judging Session' : 'Judging Session' }}</h1>

        {{-- Legacy control row (judging_locations.admin.php:229-236):
            back-to-sessions plus (edit view) the add-session shortcut. --}}
        <div class="mb-4 d-flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="{{ route('admin.judging.locations.index') }}"><span class="fa fa-arrow-circle-left"></span> All Judging Sessions</a>
            @if ($isEdit)
                <a class="btn btn-outline btn-secondary" href="{{ route('admin.judging.locations.create') }}"><span class="fa fa-plus-circle"></span> Add a Judging Session</a>
            @endif
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form data-time-24hr="{{ $tf24 ? '1' : '0' }}" method="post" action="{{ $isEdit
            ? route($nonJudging ? 'admin.judging.non_judging.update' : 'admin.judging.locations.update', ['id' => $location->id])
            : route($nonJudging ? 'admin.judging.non_judging.store' : 'admin.judging.locations.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="mb-4 row">
                <label for="judgingLocName" class="col-md-3 col-form-label">Session Name</label>
                <div class="col-md-6">
                    <input class="form-control" id="judgingLocName" name="judgingLocName" type="text" maxlength="255" required value="{{ old('judgingLocName', $location->judgingLocName ?? '') }}">
                </div>
            </div>

            @if (! $nonJudging)
                <div class="mb-4 row">
                    <span class="col-md-3 col-form-label">Session Type</span>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="judgingLocType" id="judgingLocType_0" value="0" required @checked((string) old('judgingLocType', $location->judgingLocType ?? '0') === '0')>
                            <label class="form-check-label" for="judgingLocType_0">Traditional <small>(typically a single day in a central location)</small></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="judgingLocType" id="judgingLocType_1" value="1" required @checked((string) old('judgingLocType', $location->judgingLocType ?? '0') === '1')>
                            <label class="form-check-label" for="judgingLocType_1">Distributed <small>(multi-day and/or multi-location; requires an end date/time)</small></label>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mb-4 row">
                <label for="judgingDate" class="col-md-3 col-form-label">Session Start Date/Time</label>
                <div class="col-md-6">
                    <input class="form-control date-time-picker-system" id="judgingDate" name="judgingDate" type="text" placeholder="YYYY-MM-DD hh:mm AM" required value="{{ old('judgingDate', \App\Support\Tenant\DateFmt::dateTimeInput($location->judgingDate ?? null, $ctx->prefsStr('prefsTimeZone'), $tf24) ?? '') }}">
                    <div class="form-text">Format: YYYY-MM-DD hh:mm AM/PM, in the competition's timezone.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="judgingDateEnd" class="col-md-3 col-form-label">Session End Date/Time</label>
                <div class="col-md-6">
                    <input class="form-control date-time-picker-system" id="judgingDateEnd" name="judgingDateEnd" type="text" placeholder="YYYY-MM-DD hh:mm AM" value="{{ old('judgingDateEnd', \App\Support\Tenant\DateFmt::dateTimeInput($location->judgingDateEnd ?? null, $ctx->prefsStr('prefsTimeZone'), $tf24) ?? '') }}">
                    <div class="form-text">@if (! $nonJudging)Required for distributed sessions: the deadline for judges to submit evaluations.@else Optional.@endif</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="judgingLocation" class="col-md-3 col-form-label">{{ $nonJudging ? 'Session Address' : 'Address / Entry Distribution Info' }}</label>
                <div class="col-md-6">
                    <input class="form-control" id="judgingLocation" name="judgingLocation" type="text" maxlength="255" required value="{{ old('judgingLocation', $location->judgingLocation ?? '') }}">
                </div>
            </div>

            @if (! $nonJudging)
                <div class="mb-4 row">
                    <label for="judgingRounds" class="col-md-3 col-form-label">Session Rounds</label>
                    <div class="col-md-6">
                        <input class="form-control" id="judgingRounds" name="judgingRounds" type="number" min="1" required value="{{ old('judgingRounds', $location->judgingRounds ?? '') }}">
                    </div>
                </div>
            @endif

            <div class="mb-4 row">
                <label for="judgingLocNotes" class="col-md-3 col-form-label">Notes</label>
                <div class="col-md-6">
                    <input class="form-control" id="judgingLocNotes" name="judgingLocNotes" type="text" maxlength="1000" value="{{ old('judgingLocNotes', $location->judgingLocNotes ?? '') }}">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Edit' : 'Add' }} {{ $nonJudging ? 'Non-Judging Session' : 'Judging Session' }}</button>
            <a class="btn btn-secondary" href="{{ route($nonJudging ? 'admin.judging.non_judging.index' : 'admin.judging.locations.index') }}">Back</a>
        </form>
    </section>
</x-public-layout>
