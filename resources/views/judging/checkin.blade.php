@php($boxPaid = $boxPaid ?? false)
@php($entries = $entries ?? collect())
<x-public-layout
    :ctx="$ctx"
    :show-hero="false"
>
    <section class="landing-page-section mt-6 mb-4">
            @if ($boxPaid)
                <div class="bcoem-admin-element mb-4"><a href="{{ url('/admin/judging/checkin') }}" class="btn btn-sm btn-primary">Switch View to Entry/Judging Numbers Only</a></div>
            @else
                <div class="bcoem-admin-element mb-4"><a href="{{ url('/admin/judging/checkin?filter=box-paid') }}" class="btn btn-sm btn-primary">Switch View to Entry/Judging Numbers, Box, and Paid Entries</a></div>
            @endif
        <h1>{{ $ctx->contestStr('contestName') }}: Check-In Entries with a Barcode Reader/Scanner</h1>

        @if (request('ok') !== null)
            <div class="alert alert-success">Entry <strong>{{ request('ok') }}</strong> checked in.</div>
        @elseif (request('again') !== null)
            <div class="alert alert-info">Entry <strong>{{ request('again') }}</strong> was already checked in.</div>
        @elseif (request('bad') !== null)
            <div class="alert alert-danger">The following entries were <strong>not found</strong> in the database: {{ request('bad') }}</div>
        @elseif (request('dup') !== null)
            <div class="alert alert-danger"><strong>The following judging number(s) have already been assigned to entries.</strong> Please use another judging number for each: {{ request('dup') }}</div>
        @endif

        @if ($checkedIn !== [])
            <div class="alert alert-info">
                The following entries have been checked in: {{ implode(', ', $checkedIn) }}
                <a href="{{ url('/admin/judging/checkin?clear=1'.($boxPaid ? '&filter=box-paid' : '')) }}">Clear list</a>
            </div>
        @endif

        <p>Scan or type an entry's judging number (or entry id) and submit — keyboard-wedge scanners send Enter after each code.</p>
        <form method="post" action="{{ route('admin.judging.checkin.store') }}">
            @csrf
            <input type="text" name="scan" id="scan" class="form-control form-control-lg mb-2"
                   maxlength="32" required autofocus autocomplete="off"
                   aria-label="Entry or judging number">
            <button type="submit" class="btn btn-primary">Check-In Entry</button>
        </form>

        @if ($boxPaid)
            <h2 class="h4 mt-5">Entry/Judging Numbers, Box, and Paid Entries</h2>
            @if ($entries->isEmpty())
                <div class="alert alert-info" role="alert">No entries are available to check in.</div>
            @else
                <table class="table table-responsive table-bordered" data-dt data-dt-page="50">
                    <thead>
                        <tr>
                            <th>Entry #</th>
                            <th>Judging #</th>
                            <th>Box</th>
                            <th>Paid</th>
                            <th>Received</th>
                            <th class="d-print-none" data-no-sort>Check-In</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td>{{ $entry->id }}</td>
                                <td>{{ $entry->brewJudgingNumber }}</td>
                                <td>
                                    <form id="checkin-{{ $entry->id }}" method="post" action="{{ route('admin.judging.checkin.store') }}">
                                        @csrf
                                        <input type="hidden" name="scan" value="{{ $entry->id }}">
                                        <input type="hidden" name="filter" value="box-paid">
                                    </form>
                                    <input form="checkin-{{ $entry->id }}" class="form-control form-control-sm" type="text"
                                           name="brewBoxNum" value="{{ $entry->brewBoxNum }}" maxlength="10"
                                           style="max-width:8rem;" aria-label="Box number for entry {{ $entry->id }}">
                                </td>
                                <td>
                                    <div class="form-check">
                                        <input form="checkin-{{ $entry->id }}" class="form-check-input" type="checkbox"
                                               name="brewPaid" id="paid-{{ $entry->id }}" value="1"
                                               @checked((int) $entry->brewPaid === 1)>
                                        <label class="form-check-label" for="paid-{{ $entry->id }}">Paid</label>
                                    </div>
                                </td>
                                <td>{{ (int) $entry->brewReceived === 1 ? 'Yes' : 'No' }}</td>
                                <td class="d-print-none">
                                    <button form="checkin-{{ $entry->id }}" type="submit" class="btn btn-sm btn-primary">Check-In</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </section>
</x-public-layout>
