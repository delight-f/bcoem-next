<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Assign {{ ucfirst($role) }} to Table {{ $table->tableNumber }} &ndash; {{ $table->tableName }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p>
            <a href="{{ route('admin.judging.assign.show', ['id' => $table->id, 'role' => 'judges']) }}">Judges</a> |
            <a href="{{ route('admin.judging.assign.show', ['id' => $table->id, 'role' => 'stewards']) }}">Stewards</a>
        </p>

        <nav class="mb-4 fs-6">
            <a href="{{ url('/backoffice/participants') }}">All Participants</a> |
            <a href="{{ url('/backoffice/participants?filter=judges') }}">Available Judges</a> |
            <a href="{{ url('/backoffice/participants?filter=stewards') }}">Available Stewards</a>
        </nav>

        @if ($flights->isEmpty())
            <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <span class="fa fa-exclamation-triangle fa-lg" aria-hidden="true"></span>
                <div>No flights have been defined for this table yet. Define flights for this table before
                    assigning {{ $role }}.</div>
            </div>
        @elseif ($rows === [])
            <p>No {{ $role }} have volunteered.</p>
        @else
            <form method="post" action="{{ route('admin.judging.assign.store', ['id' => $table->id, 'role' => $role]) }}">
                @csrf
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Name</th>
                            @foreach ($flights as $flight)
                                <th>Flight {{ $flight->flightNumber }} (Round {{ $flight->flightRound }})</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['name'] }}
                                    @if ($row['conflict'])
                                        <span class="text-info">Has an entry at this table &mdash; assignment disabled.</span>
                                    @endif
                                    @if ($row['ineligible'] ?? false)
                                        <span class="text-info">No longer available &mdash; unassign here.</span>
                                    @endif
                                </td>
                                @foreach ($row['flights'] as $cell)
                                    @php($name = sprintf('assign[%d][%d]', $row['uid'], $cell['round']))
                                    <td>
                                        @if ($row['conflict'])
                                            <input type="hidden" name="{{ $name }}" value="0">
                                            &mdash;
                                        @else
                                            <select name="{{ $name }}" class="form-select form-select-sm">
                                                <option value="0">Do Not Assign</option>
                                                @foreach ($flights as $choice)
                                                    <option value="{{ $choice->flightNumber }}"
                                                            @selected($cell['status'] !== 'busy' && (int) $choice->flightNumber === $cell['assignedFlight'])>
                                                        Assign to Flight {{ $choice->flightNumber }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($cell['status'] === 'assigned')
                                            <span class="text-warning d-block"><strong>Assigned.</strong></span>
                                        @elseif ($cell['status'] === 'busy')
                                            <span class="text-primary d-block">Assigned to another table in this round.</span>
                                        @elseif ($cell['status'] === 'preferred')
                                            <span class="text-success d-block">Available and Preferred Style(s).</span>
                                        @elseif ($cell['status'] === 'non-preferred')
                                            <span class="text-danger d-block">Available but Non-Preferred Style(s).</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary">Save Assignments</button>
            </form>
        @endif
    </section>
</x-public-layout>
