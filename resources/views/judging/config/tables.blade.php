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
