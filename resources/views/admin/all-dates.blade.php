@php
    $tz = $ctx->prefsStr('prefsTimeZone');
    $df = $ctx->prefsStr('prefsDateFormat');
    $tf = $ctx->prefsStr('prefsTimeFormat');

    // Legacy getTimeZoneDateTime(...,'system','date-time-system'): "Y-m-d H:i"
    // (24h) when prefsTimeFormat=1, else "Y-m-d g:i A"; no timezone suffix.
    // Legacy guards optional fields with >0 / !empty, so <=0 renders blank.
    $fmt = fn ($epoch): string => ($epoch === null || $epoch === '' || ! is_numeric($epoch) || (int) $epoch <= 0)
        ? ''
        : (\App\Support\Tenant\DateFmt::dateTime($epoch, $tz, $df, $tf, 'system', false) ?? '');

    $cdt = fn (string $key): string => $fmt($ctx->contestEpoch($key));
    $wdt = fn (string $key): string => $fmt($ctx->prefsStr($key));
    $required = 'This field is required.';
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <p class="lead">{{ $ctx->contestStr('contestName') }} Competition-Related Dates</p>
    <p>All competition-related dates for various functions are listed below. Useful when resetting the software for another competition instance after archiving or purging or to adjust any function's date/time for the current competition iteration.</p>

    @if ($errors->any())
        <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form data-toggle="validator" role="form" class="form-horizontal" method="post" action="{{ url('/admin/dates') }}">
        @csrf
        @method('put')

        <h3>Entry-Related</h3>
        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestEntryOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entry Window Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestEntryOpen" name="contestEntryOpen" type="text" value="{{ $cdt('contestEntryOpen') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestEntryDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entry Window Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestEntryDeadline" name="contestEntryDeadline" type="text" size="20" value="{{ $cdt('contestEntryDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span id="helpBlock" class="help-block">This date is only for restriction of adding <strong>new</strong> entries. Existing entries will be able to be edited beyond this date &ndash; until the drop-off/shipping deadlines &ndash; unless a specific entry editing close date is provided below.</span>
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group">
            <label for="contestEntryEditDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entry Edit Close Date</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <div class="input-group">
                    <input class="form-control date-time-picker-system" id="contestEntryEditDeadline" name="contestEntryEditDeadline" type="text" size="20" value="{{ $cdt('contestEntryEditDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}">
                    <span id="helpBlock" class="help-block">If you wish to restrict editing of any exisiting entry's information by non-admin participants, provide a close date here. For example, this could allow competition staff to prepare for sorting prior to the entry drop-off/shipment closure dates.</span>
                </div>
            </div>
        </div>

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestDropoffOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Drop-Off Window Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <input class="form-control date-time-picker-system" id="contestDropoffOpen" name="contestDropoffOpen" type="text" value="{{ $cdt('contestDropoffOpen') }}" placeholder="{{ $currentDate }} {{ $currentTime }}">
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestDropoffDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Drop-Off Window Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <input class="form-control date-time-picker-system" id="contestDropoffDeadline" name="contestDropoffDeadline" type="text" value="{{ $cdt('contestDropoffDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}">
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestShippingOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Shipping Window Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <input class="form-control date-time-picker-system" id="contestShippingOpen" name="contestShippingOpen" type="text" value="{{ $cdt('contestShippingOpen') }}" placeholder="{{ $currentDate }} {{ $currentTime }}">
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestShippingDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Shipping Window Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <input class="form-control date-time-picker-system" id="contestShippingDeadline" name="contestShippingDeadline" type="text" value="{{ $cdt('contestShippingDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" >
            </div>
        </div><!-- ./Form Group -->

        <h3>Account Registration</h3>
        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestRegistrationOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entrant Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestRegistrationOpen" name="contestRegistrationOpen" type="text" value="{{ $cdt('contestRegistrationOpen') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span id="helpBlock" class="help-block with-errors">The date and time when general entrants are able to create an account.</span>
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestRegistrationDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entrant Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestRegistrationDeadline" name="contestRegistrationDeadline" type="text" size="20" value="{{ $cdt('contestRegistrationDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span id="helpBlock" class="help-block with-errors">The deadline for general entrants to create an account.</span>
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="contestJudgeOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Judge/Steward Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestJudgeOpen" name="contestJudgeOpen" type="text" value="{{ $cdt('contestJudgeOpen') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span id="helpBlock" class="help-block with-errors">The date and time when judges and stewards are able to create an account and indicate their session preferences.</span>
            </div>
        </div><!-- ./Form Group -->

        <div class="form-group"><!-- Form Group NOT REQUIRED Text Input -->
            <label for="contestJudgeDeadline" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Judge/Steward Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <div class="input-group has-warning">
                    <input class="form-control date-time-picker-system" id="contestJudgeDeadline" name="contestJudgeDeadline" type="text" size="20" value="{{ $cdt('contestJudgeDeadline') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                    <span class="input-group-addon" id="contestHost-addon2" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span id="helpBlock" class="help-block with-errors">The deadline for judges and stewards to create an account and indicate their session preferences.</span>
            </div>
        </div><!-- ./Form Group -->

        <!-- Loop through Judging Sessions and show dates -->
        <h3>Judging Sessions</h3>
        @if ($sessions->isEmpty())
            <p>No judging sessions have been defined. <a href="{{ route('admin.judging.locations.create') }}">Add a judging session</a>?</p>
        @else
            @foreach ($sessions as $session)
        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <input type="hidden" name="id[]" value="{{ $session->id }}">
            <label for="judgingDate-{{ $session->id }}" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">{{ $session->judgingLocName }} - Session Start</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <div class="input-group date has-warning">
                    <!-- Input Here -->
                    <input class="form-control date-time-picker-system" id="judgingDate-{{ $session->id }}" name="judgingDate{{ $session->id }}" type="text" value="{{ $fmt($session->judgingDate) }}" placeholder="" required>
                    <span class="input-group-addon" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span class="help-block">Provide a start date and time for the session.<br>Session type is set to <strong>{{ ((int) $session->judgingLocType === 1) ? 'Distributed' : 'Traditional' }}</strong>. To change the type or other information, <a href="{{ route('admin.judging.locations.edit', $session->id) }}">edit the {{ $session->judgingLocName }} session</a>.</span>
            </div>
        </div><!-- ./Form Group -->
                @if ((int) $session->judgingLocType === 1)
        <div class="form-group"><!-- Form Group REQUIRED Text Input -->
            <label for="judgingDateEnd-{{ $session->id }}" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">{{ $session->judgingLocName }} - Session End</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <div class="input-group date has-warning">
                    <!-- Input Here -->
                    <input class="form-control date-time-picker-system" id="judgingDateEnd-{{ $session->id }}" name="judgingDateEnd{{ $session->id }}" type="text" value="{{ $fmt($session->judgingDateEnd) }}" placeholder="">
                    <span class="input-group-addon" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span class="help-block">For a distributed session, it is required that you provide an end date and time that will serve as a deadline for judges to submit their evaluations.</span>
            </div>
        </div><!-- ./Form Group -->
                @endif
            @endforeach
        @endif

        @if ($prefsEval)
        <!-- If Evals Enabled -->
        <h3>Judging Open and Close</h3>
        <div class="form-group">
            <label for="jPrefsJudgingOpen" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Open</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <input class="form-control date-time-picker-system" id="jPrefsJudgingOpen" name="jPrefsJudgingOpen" type="text" value="{{ $judgingOpenDate }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                <div id="helpBlock" class="help-block">Indicate when judges will be allowed access to their Judging Dashboard to add entry evaluations.  Typically, the open date begins the day and time the first judging session begins.
                @if ($suggestedOpen)<br><span class="text-warning" style="margin-bottom:5px;">* The date and time above is suggested. It is the the earliest start day/time for any judging session.</span>@endif
                </div>
            </div>
        </div>
        <div class="form-group">
            <label for="jPrefsJudgingClosed" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Close</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <input class="form-control date-time-picker-system" id="jPrefsJudgingClosed" name="jPrefsJudgingClosed" type="text" size="20" value="{{ $judgingCloseDate }}" placeholder="{{ $currentDate }} {{ $currentTime }}" required>
                <div id="helpBlock" class="help-block">The closing date and time is the absolute latest judges will be allowed to enter or edit their evaluations and scores.
                @if ($suggestedClose)<br><span class="text-warning" style="margin-bottom:5px;">* The date and time above is suggested. It is the latest recorded end date/time for a judging session OR the last judging session's start time + 8 hours.</span>@endif
                <div style="margin-top:5px" class="bcoem-admin-element" role="group" aria-label="judgingWindowModal">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-xs btn-info" data-toggle="modal" data-target="#judgingWindowModal">
                           Judging Open/Close Dates and Times Info
                        </button>
                    </div>
                </div>
                </div>
            </div>
        </div>
        <!-- Modal -->
        <div class="modal fade" id="judgingWindowModal" tabindex="-1" role="dialog" aria-labelledby="judgingWindowModalLabel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bcoem-admin-modal">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="judgingWindowModalLabel">Judging Open/Close Dates and Times Info</h4>
                    </div>
                    <div class="modal-body">
                        <p>Indicate when judges will be allowed access to their Judging Dashboard to add entry evaluations. Typically, the open date begins the day and time the first judging session begins. The closing date and time is the absolute latest judges will be allowed to enter or edit their evaluations and scores.</p>
                        <p>If no dates are input here for either open or close, these defaults will be used by the system:</p>
                        <ul>
                            <li><strong>Open</strong> &ndash; the earliest judging session's start date/time.</li>
                            <li><strong>Closed</strong> &ndash; the last judging session's start date/time <span class="text-primary">+ 8 hours</span>.</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div><!-- ./modal -->
        @endif

        <h3>Non-Judging Sessions</h3>
        @forelse ($nonJudging as $nonJudgingRow)
        <div class="form-group">
            <input type="hidden" name="id[]" value="{{ $nonJudgingRow->id }}">
            <label for="judgingDate-{{ $nonJudgingRow->id }}" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">{{ $nonJudgingRow->judgingLocName }} - Session Start</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <div class="input-group date has-warning">
                    <!-- Input Here -->
                    <input class="form-control date-time-picker-system" id="judgingDate-{{ $nonJudgingRow->id }}" name="judgingDate{{ $nonJudgingRow->id }}" type="text" value="{{ $fmt($nonJudgingRow->judgingDate) }}" placeholder="" required>
                    <span class="input-group-addon" data-tooltip="true" title="{{ $required }}"><span class="fa fa-star"></span></span>
                </div>
                <span class="help-block">Provide a start date and time for the session.</span>
            </div>
        </div>
        @empty
        <p>No non-judging sessions have been defined. <a href="{{ route('admin.judging.non_judging.create') }}">Add a non-judging session</a>?</p>
        @endforelse

        <!-- If Winner Display Enabled -->
        <h3>Results</h3>
        <div class="form-group"><!-- Form Group NOT REQUIRED Text Input -->
            <label for="prefsWinnerDelay" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Display Date and Time</label>
            <div class="col-lg-6 col-md-4 col-sm-8 col-xs-12">
                <input class="form-control date-time-picker-system" id="prefsWinnerDelay" name="prefsWinnerDelay" type="text" value="{{ $wdt('prefsWinnerDelay') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" >
                <span id="helpBlock" class="help-block">Date and time when the system will display winners. If a date and time are specified, winner display will be enabled. If the date and time are removed or blank, winner display will be disabled.</span>
                <div class="help-block with-errors"></div>
            </div>
        </div>

        <h3>Awards Ceremony</h3>
        <div class="form-group"><!-- Form Group NOT REQUIRED Text Input -->
            <label for="contestAwardsLocDate" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Date and Time</label>
            <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                <!-- Input Here -->
                <input class="form-control date-time-picker-system" id="contestAwardsLocDate" name="contestAwardsLocDate" type="text" value="{{ $cdt('contestAwardsLocTime') }}" placeholder="{{ $currentDate }} {{ $currentTime }}" >
                <span id="helpBlock" class="help-block">Provide even if the date of judging is the same.</span>
            </div>
        </div><!-- ./Form Group -->

        <div class="bcoem-admin-element hidden-print">
            <div class="form-group">
                <div class="col-lg-offset-2 col-md-offset-3 col-sm-offset-4">
                    <input name="submit" type="submit" class="btn btn-primary" value="Update Competition Dates">
                </div>
            </div>
        </div>
    </form>
</x-public-layout>
