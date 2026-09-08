<x-public-layout
    :ctx="$ctx"
    :show-hero="false"
>
    <section class="landing-page-section mt-6 mb-4">
            <div class="bcoem-admin-element mb-4"><a href="{{ url('/admin/judging/checkin?filter=box-paid') }}" class="btn btn-sm btn-primary">Switch View to Entry/Judging Numbers, Box, and Paid Entries</a></div>
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
                <a href="{{ route('admin.judging.checkin.show', ['clear' => 1]) }}">Clear list</a>
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
    </section>
</x-public-layout>
