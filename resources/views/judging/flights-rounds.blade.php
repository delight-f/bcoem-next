<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        // Legacy flight_round_number display + judging_flights.admin.php:334-348.
        $locationLine = function ($location) use ($ctx): string {
            if ($location === null) {
                return '<span class="text-danger">No location chosen.</span>';
            }

            $rounds = (int) ($location->judgingRounds ?? 1);
            $noun = $rounds > 1 ? 'rounds' : 'round';
            $date = \App\Support\Tenant\DateFmt::dateTime(
                is_numeric($location->judgingDate ?? null) ? (int) $location->judgingDate : null,
                $ctx->prefsStr('prefsTimeZone'),
                $ctx->prefsStr('prefsDateFormat'),
                $ctx->prefsStr('prefsTimeFormat'),
                'long',
            );

            // Legacy judging_flights.admin.php:346-347: the location line
            // links "defined for this location" to the location editor
            // (go=judging&action=edit&id=N).
            $locEdit = url('/admin/judging/locations/'.$location->id.'/edit');

            return e($location->judgingLocName).($date ? ' &ndash; '.$date : '')
                .' ('.$rounds.' '.$noun.' <a href="'.e($locEdit).'" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit the '.e($location->judgingLocName).' location">defined for this location</a>)';
        };
    @endphp
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Define/Edit Flights</h1>

        {{-- Legacy control row (judging_flights.admin.php:102-119). --}}
        <div class="mb-4 d-flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="{{ url('/admin/judging/tables') }}"><span class="fa fa-arrow-circle-left"></span> All Tables</a>
            <a class="btn btn-secondary" href="{{ url('/admin/judging/flights') }}"><span class="fa fa-plus-circle"></span> Add/Edit Flights</a>
        </div>

        <form method="post" action="{{ route('admin.judging.flights.rounds.assign') }}">
            @csrf
            @foreach ($rows as $row)
                @php($table = $row['table'])
                <h4>Table {{ $table->tableNumber }} &ndash; {{ $table->tableName }}
                    @if ($row['location'] !== null)
                        <small><a href="{{ route('admin.judging.flights.show', ['id' => $table->id]) }}?filter=define" data-bs-toggle="tooltip" data-bs-placement="top" title="Define/Edit the {{ $table->tableName }} Flights"><span class="fa fa-lg fa-pencil-square-o"></span></a></small>
                    @endif
                </h4>
                <p><strong>Location:</strong> {!! $locationLine($row['location']) !!}</p>

                @if ($row['flights'] === [])
                    <p>No flights have been defined.</p>
                @elseif ($row['location'] === null)
                    <p>No flights have been defined.</p>
                @else
                    @php($maxRound = max(1, (int) ($row['location']->judgingRounds ?? 1)))
                    @foreach ($row['flights'] as $flightNumber => $current)
                        <div class="mb-2 row">
                            <label class="col-md-4 col-form-label" for="round-{{ $table->id }}-{{ $flightNumber }}">
                                Assign Flight {{ $flightNumber }} to:
                            </label>
                            <div class="col-md-3">
                                <select class="form-select" id="round-{{ $table->id }}-{{ $flightNumber }}"
                                        name="rounds[{{ $table->id }}][{{ $flightNumber }}]">
                                    <option value="" @selected($current === '')>Not Assigned to a Round</option>
                                    @for ($r = 1; $r <= $maxRound; $r++)
                                        <option value="{{ $r }}" @selected((string) $current === (string) $r)>Round {{ $r }}</option>
                                    @endfor
                                </select>
                            </div>
                        </div>
                    @endforeach
                @endif
            @endforeach

            @if ($rows !== [])
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirm-submit">Assign</button>
            @endif

            {{-- Legacy confirm modal text verbatim
                 (judging_flights.admin.php:388-404). --}}
            <div class="modal fade" id="confirm-submit" tabindex="-1" role="dialog" aria-labelledby="confirm-submit-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold" id="confirm-submit-title">Please Confirm</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong><em>All</em> applicable judging/stewarding assignments will be deleted if you have changed a table&rsquo;s round assignment.</strong> Do you wish to continue? This cannot be undone.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Yes</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </section>
</x-public-layout>
