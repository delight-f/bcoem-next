<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Judging Tables</h1>

        {{-- Tables Competition/Planning Mode (judging_tables.admin.php:744-752).
             Legacy ships both lead texts + both buttons, then JS shows one
             of each per jPrefsTablePlanning. --}}
        <div id="mode-alert" class="alert {{ $planning ? 'alert-purple' : 'alert-teal' }} print:hidden">
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

        <div id="tables-planning-mode" class="print:hidden" @if ($planning) hidden @endif>
            <button type="button" id="table-planning-button" class="btn btn-primary">
                <span class="fa fa-exchange"></span> Switch to Tables <strong>Planning</strong> Mode
            </button>
        </div>
        <div id="tables-competition-mode" class="print:hidden" @if (! $planning) hidden @endif>
            <button type="button" id="tables-competition-button" class="btn btn-primary">
                <span class="fa fa-exchange"></span> Switch to Tables <strong>Competition</strong> Mode
            </button>
        </div>
        <p class="print:hidden">
            <a class="btn btn-primary" href="{{ route('admin.judging.tables.create') }}">Add a Table</a>
        </p>
        {{-- Legacy control set: View... + Print... dropdowns
             (judging_tables.admin.php:777-822). Assignment items map to the
             existing port outputs; the "Not Assigned to a Table" items open
             the avail modals below. --}}
        <div class="bcoem-admin-element hidden-print mb-3">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="View Assignments by Name">Judge Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="View Assignments by Table">Judge Assignments By Table</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="View Assignments by Name">Steward Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="View Assignments by Table">Steward Assignments By Table</a></li>
                    <li class="small"><a class="dropdown-item" data-open-modal="availJudgeModal">Judges Not Assigned to a Table</a></li>
                    <li class="small"><a class="dropdown-item" data-open-modal="availStewardModal">Stewards Not Assigned to a Table</a></li>
                </ul>
            </div>
            <div class="btn-group hidden-xs hidden-sm" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-print"></span> Print...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item" onclick="window.print()">Tables List</a></li>
                    @if (! $obfuscate)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.pullsheets') }}">Pullsheets by Table</a></li>
                    @endif
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="Print Judge Assignments by Name">Judge Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="Print Judge Assignments by Table">Judge Assignments By Table</a></li>
                    @if ($sessionCount > 1)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'judges']) }}" title="Print Judge Assignments by Location">Judge Assignments By Location</a></li>
                    @endif
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="Print Steward Assignments by Name">Steward Assignments By Last Name</a></li>
                    <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="Print Steward Assignments by Table">Steward Assignments By Table</a></li>
                    @if ($sessionCount > 1)
                        <li class="small"><a class="dropdown-item" href="{{ route('outputs.assignments', ['filter' => 'stewards']) }}" title="Print Steward Assignments by Location">Steward Assignments By Location</a></li>
                    @endif
                </ul>
            </div>
        </div>

        {{-- Legacy #tables-competition-mode-modal: switching back to
             Competition Mode restructures flights/assignments. --}}
        <dialog class="modal" id="tables-competition-mode-modal">
            <div class="modal-box">
                <h3 class="font-bold">Please Confirm</h3>
                <p>Are you sure you want to switch back to Tables Competition Mode?</p>
                <div class="modal-action">
                    <form method="dialog">
                        <button class="btn">Cancel</button>
                    </form>
                    <button type="button" id="tables-competition-button-yes" class="btn btn-success">Yes</button>
                </div>
            </div>
        </dialog>

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

                const competitionBtn = document.getElementById('tables-competition-button');
                if (competitionBtn) {
                    competitionBtn.addEventListener('click', () =>
                        document.getElementById('tables-competition-mode-modal')?.showModal());
                }
                const confirmYes = document.getElementById('tables-competition-button-yes');
                if (confirmYes) {
                    confirmYes.addEventListener('click', () => {
                        document.getElementById('tables-competition-mode-modal')?.close();
                        switchMode('enable-competition');
                    });
                }
            });
        </script>
        {{-- Legacy #unassigned-modal (judging_tables.admin.php:726-743): shown
             on switching to Competition Mode when un-assignments occurred. --}}
        <dialog class="modal" id="unassigned-modal">
            <div class="modal-box">
                <h4 class="font-bold" id="unassigned-modal-label">Caution! Judges and/or Stewards Were Un-Assigned</h4>
                <p>Upon switching to Tables Competition Mode, one or more judges/stewards were un-assigned from tables due to entry style conflicts. This can happen if the participant added or edited an entry's style that is designated at a table where they are assigned as a judge or steward.</p>
                <p>Review the list below for any changes in judge or steward counts and make adjustments accordingly.</p>
                <div class="modal-action">
                    <form method="dialog"><button class="btn btn-success">I Understand</button></form>
                </div>
            </div>
        </dialog>

        {{-- Legacy #availJudgeModal / #availStewardModal
             (judging_tables.admin.php:852-884): lib/admin.lib.php not_assigned(). --}}
        <dialog class="modal" id="availJudgeModal">
            <div class="modal-box">
                <h4 class="font-bold" id="availJudgeModalLabel">Judges Not Assigned to a Table</h4>
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
                <div class="modal-action">
                    <form method="dialog"><button class="btn">Close</button></form>
                </div>
            </div>
        </dialog>

        <dialog class="modal" id="availStewardModal">
            <div class="modal-box">
                <h4 class="font-bold" id="availStewardModalLabel">Stewards Not Assigned to a Table</h4>
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
                <div class="modal-action">
                    <form method="dialog"><button class="btn">Close</button></form>
                </div>
            </div>
        </dialog>
        @if ($tables->isEmpty())
            <p>No tables have been defined.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Styles</th>
                        <th>Location</th>
                        <th>Entry Limit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tables as $table)
                        <tr>
                            <td>{{ $table->tableNumber }}</td>
                            <td>{{ $table->tableName }}</td>
                            <td>{{ $table->tableStyles }}</td>
                            <td>{{ $table->tableLocation }}</td>
                            <td>{{ $table->tableEntryLimit ?? '' }}</td>
                            <td class="print:hidden">
                                <a href="{{ route('admin.judging.tables.edit', ['id' => $table->id]) }}">Edit</a>
                                <form method="post" action="{{ route('admin.judging.tables.destroy', ['id' => $table->id]) }}" class="inline" onsubmit="return confirm('Delete this table? All of its scores and flights are removed. This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link btn-sm p-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-public-layout>
