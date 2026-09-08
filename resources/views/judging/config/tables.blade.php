<x-public-layout :ctx="$ctx" :show-hero="false">
    <p class="lead">{{ $ctx->contestStr('contestName') }} Judging Tables</p>
    <section class="container mt-6 mb-4">

        {{-- Tables Competition/Planning Mode (judging_tables.admin.php:744-752).
             Legacy ships both lead texts + both buttons, then JS shows one
             of each per jPrefsTablePlanning. --}}
        <div id="mode-alert" class="alert {{ $planning ? 'alert-purple' : 'alert-teal' }} d-print-none">
            <div id="tables-planning-mode-text" @if (! $planning) hidden @endif>
                <strong>Your installation is currently in Tables Planning Mode</strong>
                &ndash; defining tables, flights, rounds, and associated judge/steward assignments
                is <strong>not</strong> bound by the Tables Competition Mode requirement that all
                entries must be marked as paid and received. Pullsheets are <strong>not</strong> available
                in Tables Planning Mode.
            </div>
            <div id="tables-competition-mode-text" @if ($planning) hidden @endif>
                <strong>Your installation is currently in Tables Competition Mode </strong>
                &ndash; to ensure accuracy, verify that all paid and received entries have been
                marked as such via the <a href="{{ url('/backoffice/entries') }}">Manage Entries</a> screen.
            </div>
        </div>

        <div id="tables-planning-mode" class="d-print-none" @if ($planning) hidden @endif>
            <button type="button" id="table-planning-button" class="btn btn-primary">
                <span class="fa fa-exchange"></span> Switch to Tables <strong>Planning</strong> Mode
            </button>
            {{-- judging_tables.admin.php:749-752 — popover on the mode switch;
                 CSS tooltip carries the same help text. --}}
            <span class="fa fa-question-circle text-secondary" style="cursor: help"
                data-bs-toggle="tooltip" data-placement="right" data-tooltip="true"
                title="When the Tables Planning Mode function is enabled, Admins can define tables, flights, rounds, judge/steward assignments, and, if enabled in Entry Preferences, associated entry limits prior to entries being marked as paid and/or received. Any table configurations and associated assignments will not be official until an Admin returns to Tables Competition Mode after entries have been sorted and marked as received."></span>
        </div>
        <div id="tables-competition-mode" class="d-print-none" @if (! $planning) hidden @endif>
            <button type="button" id="tables-competition-button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tables-competition-mode-modal">
                <span class="fa fa-exchange"></span> Switch to Tables <strong>Competition</strong> Mode
            </button>
            {{-- judging_tables.admin.php:754-756 — popover on the mode switch. --}}
            <span class="fa fa-question-circle text-secondary" style="cursor: help"
                data-bs-toggle="tooltip" data-placement="right" data-tooltip="true"
                title="When the Tables Competition Mode function is enabled by an admin, it indicates to the system that the planning stage is over and all applicable entries have been marked as received. Table configurations and assignments can still be changed as necessary while in Competition Mode. Pullsheets will be available."></span>
        </div>
        <p class="d-print-none">
            <a class="btn btn-primary" href="{{ route('admin.judging.tables.create') }}">Add a Table</a>
        </p>

        {{-- Legacy assign-pool screen cross-nav (judging_locations.admin.php
             515-561): pool-level ?action=assign&filter=X URLs now redirect
             to /admin/judging/pool-assign?filter=X; per-table links use the
             admin.judging.assign.show route directly. --}}
        <div class="bcoem-admin-element d-print-none mb-3">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-users"></span> Assign Roles...
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" href="{{ url('/backoffice/participants') }}">All Participants</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/backoffice/participants?filter=judges') }}">Available Judges</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/backoffice/participants?filter=stewards') }}">Available Stewards</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=judges') }}">Judges</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=bos') }}">BOS Judges</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=stewards') }}">Stewards</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=staff') }}">Staff</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/backoffice/participants?filter=stewards&view=sessions') }}">Judging Session List</a></li>
                </ul>
            </div>
        </div>
        {{-- Legacy control set: View... + Print... dropdowns
             (judging_tables.admin.php:777-822). Assignment items map to the
             existing port outputs; the "Not Assigned to a Table" items open
             the avail modals below. --}}
        <div class="bcoem-admin-element d-print-none mb-3">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}&view=name&tb=view" title="View Assignments by Name">Judge Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}&view=table&tb=view" title="View Assignments by Table">Judge Assignments By Table</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}&view=name&tb=view" title="View Assignments by Name">Steward Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}&view=table&tb=view" title="View Assignments by Table">Steward Assignments By Table</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/flights/rounds') }}">Assign Judges/Stewards to Rounds</a></li>
                    <li class="small"><a class="dropdown-item" data-bs-toggle="modal" data-bs-target="#availJudgeModal">Judges Not Assigned to a Table</a></li>
                    <li class="small"><a class="dropdown-item" data-bs-toggle="modal" data-bs-target="#availStewardModal">Stewards Not Assigned to a Table</a></li>
                </ul>
            </div>
            <div class="btn-group d-none d-lg-block" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-print"></span> Print...
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" onclick="window.print()">Tables List</a></li>
                    @if (! $obfuscate)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.pullsheets') }}">Pullsheets by Table</a></li>
                    @endif
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}&view=name" title="Print Judge Assignments by Name">Judge Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}&view=table" title="Print Judge Assignments by Table">Judge Assignments By Table</a></li>
                    @if ($sessionCount > 1)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="Print Judge Assignments by Location">Judge Assignments By Location</a></li>
                    @endif
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}&view=name" title="Print Steward Assignments by Name">Steward Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}&view=table" title="Print Steward Assignments by Table">Steward Assignments By Table</a></li>
                    @if ($sessionCount > 1)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="Print Steward Assignments by Location">Steward Assignments By Location</a></li>
                    @endif
                </ul>
            </div>
        </div>

        {{-- Legacy #tables-competition-mode-modal: switching back to
             Competition Mode restructures flights/assignments. --}}
        <div class="modal fade" id="tables-competition-mode-modal" tabindex="-1" role="dialog" aria-labelledby="tables-competition-mode-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title fw-bold" id="tables-competition-mode-modal-label">Please Confirm</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to switch back to Tables Competition Mode?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="tables-competition-button-yes" class="btn btn-success" data-bs-dismiss="modal">Yes</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                    || document.querySelector('input[name="_token"]')?.value;
                const switchMode = (section) => fetch('{{ url('/admin/judging/tables-mode') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: 'section=' + section,
                }).then((r) => { if (r.ok) window.location.reload(); });

                const planningBtn = document.getElementById('table-planning-button');
                if (planningBtn) planningBtn.addEventListener('click', () => switchMode('enable-planning'));

                // The Competition-mode switch button opens the confirm modal via
                // data-bs-toggle/data-bs-target (BS5 data-api); Yes confirms here.
                const confirmYes = document.getElementById('tables-competition-button-yes');
                if (confirmYes) {
                    confirmYes.addEventListener('click', () => switchMode('enable-competition'));
                }
            });
        </script>
        {{-- Legacy #unassigned-modal (judging_tables.admin.php:726-743): shown
             on switching to Competition Mode when un-assignments occurred. --}}
        <div class="modal fade" id="unassigned-modal" tabindex="-1" role="dialog" aria-labelledby="unassigned-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="unassigned-modal-label">Caution! Judges and/or Stewards Were Un-Assigned</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Upon switching to Tables Competition Mode, one or more judges/stewards were un-assigned from tables due to entry style conflicts. This can happen if the participant added or edited an entry's style that is designated at a table where they are assigned as a judge or steward.</p>
                        <p>Review the list below for any changes in judge or steward counts and make adjustments accordingly.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" data-bs-dismiss="modal">I Understand</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Legacy #availJudgeModal / #availStewardModal
             (judging_tables.admin.php:852-884): lib/admin.lib.php not_assigned(). --}}
        <div class="modal fade" id="availJudgeModal" tabindex="-1" role="dialog" aria-labelledby="availJudgeModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="availJudgeModalLabel">Judges Not Assigned to a Table</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if ($unassignedJudges->isEmpty())
                            <p>No judges are currently not assigned to a table.</p>
                        @else
                            <table class="table table-bordered">
                                <thead><tr><th>Name</th><th>Judge Rank</th></tr></thead>
                                <tbody>
                                    @foreach ($unassignedJudges as $judge)
                                        <tr>
                                            <td class="small">{{ $judge['name'] }}</td>
                                            <td class="small">{{ $judge['rank'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="availStewardModal" tabindex="-1" role="dialog" aria-labelledby="availStewardModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="availStewardModalLabel">Stewards Not Assigned to a Table</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if ($unassignedStewards->isEmpty())
                            <p>No stewards are currently not assigned to a table.</p>
                        @else
                            <table class="table table-bordered">
                                <thead><tr><th>Name</th><th>Judge Rank</th></tr></thead>
                                <tbody>
                                    @foreach ($unassignedStewards as $steward)
                                        <tr>
                                            <td class="small">{{ $steward['name'] }}</td>
                                            <td class="small">{{ $steward['rank'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        @if ($tables->isEmpty())
            <p>No tables have been defined.</p>
        @else
            <table class="table table-responsive table-bordered">
                <thead>
                    <tr>
                        <th>Table #</th>
                        <th>Table Name</th>
                        <th>Styles</th>
                        <th>Location</th>
                        <th>Received</th>
                        <th>Scored</th>
                        <th class="d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tables as $table)
                        <tr>
                            <td>{{ $table->tableNumber }}</td>
                            <td>{{ $table->tableName }}</td>
                            <td>{{ $table->stylesLabel }}</td>
                            <td>{{ $table->tableLocationName ?? '' }}</td>
                            <td>{{ $table->receivedTotal }}</td>
                            <td>{{ $table->scoredTotal }}</td>
                            <td class="d-print-none" nowrap>
                                {{-- Pullsheets by Entry/Judging Numbers (legacy planning-mode gate). --}}
                                @if (! $planning)
                                    <a class="hide-loader" href="{{ route('outputs.pullsheets') }}&view=entry&id={{ $table->id }}" data-bs-toggle="tooltip" data-placement="top" title="Print the pullsheet by Entry Numbers for Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-print"></span></a>
                                @else
                                    <span class="fa fa-lg fa-print text-muted" data-bs-toggle="tooltip" data-placement="top" title="Printing pullsheets is disabled in Tables Planning Mode"></span>
                                @endif
                                @if ($sessionCount > 1 && ! $planning)
                                    <a class="hide-loader" href="{{ route('outputs.pullsheets') }}&view=judging&id={{ $table->id }}" data-bs-toggle="tooltip" data-placement="top" title="Print the pullsheet by Judging Numbers for Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-print"></span></a>
                                @endif
                                @if (! $obfuscate && ! $planning)
                                    <a class="hide-loader" href="{{ route('outputs.pullsheets') }}?id={{ $table->id }}" data-bs-toggle="tooltip" data-placement="top" title="Print the Entries with Additional Info Report for Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-plus-square"></span></a>
                                @endif
                                <a href="{{ route('admin.judging.tables.edit', ['id' => $table->id]) }}" data-bs-toggle="tooltip" data-placement="top" title="Edit Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-pencil"></span></a>
                                <a href="{{ route('admin.judging.flights.show', ['id' => $table->id]) }}?filter=define" data-bs-toggle="tooltip" data-placement="top" title="Add/edit flights for Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-send"></span></a>
                                <a href="{{ route('admin.judging.assign.show', ['id' => $table->id, 'role' => 'judges']) }}" data-bs-toggle="tooltip" data-placement="top" title="Assign judges to Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-lock"></span></a>
                                <a href="{{ route('admin.judging.assign.show', ['id' => $table->id, 'role' => 'stewards']) }}" data-bs-toggle="tooltip" data-placement="top" title="Assign stewards to Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-gavel"></span></a>
                                <form method="post" action="{{ route('admin.judging.tables.destroy', ['id' => $table->id]) }}" class="d-inline" onsubmit="return confirm('Delete this table? All of its scores and flights are removed. This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link" style="margin:0; padding:0;" data-bs-toggle="tooltip" data-placement="top" title="Delete Table {{ $table->tableNumber }}: {{ $table->tableName }}"><span class="fa fa-lg fa-trash-o"></span></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
