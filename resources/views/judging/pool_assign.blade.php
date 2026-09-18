<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        $filterLabel = match ($filter) {
            'judges' => 'Judges',
            'stewards' => 'Stewards',
            'staff' => 'Staff',
            'bos' => 'BOS Judges',
        };
        $singular = match ($filter) {
            'judges' => 'judge',
            'stewards' => 'steward',
            'staff' => 'staff member',
            'bos' => 'BOS judge',
        };
    @endphp
    <section class="container mt-6 mb-4">
        <p class="lead">{{ $ctx->contestStr('contestName') }}: {{ $filterLabel }} Pool</p>
        <p>Tick a name to add or remove that person from the {{ $singular }} pool.</p>
        @if ($allocatesTables)
            <p>The list is split into <strong>unallocated</strong> (no judging table yet) and <strong>allocated</strong>
                participants. Use the <em>Assigned To</em> picker to place someone at a table, or the &times; to take them
                off one. Unallocated includes {{ $singular }}s not yet added to the pool.</p>
        @endif

        {{-- Page purpose: the pool is the prerequisite for any table/flight
             assignment, and the four pools share one screen. --}}
        <p>This page assigns judges, stewards, BOS judges, and staff to a pool of available
            volunteers. Pool membership is the prerequisite for judging-table and flight
            assignments: only participants in the pool appear on a table&rsquo;s assignment screen.</p>

        {{-- Legacy judges/stewards registration notice
             (judging_locations.admin.php:471). --}}
        @if ($filter === 'judges' || $filter === 'stewards')
            <p><strong>{{ $filterLabel }} are assigned to the {{ $singular }} pool automatically upon registration providing they registered as a {{ $singular }}.</strong>
                Participants who first signed up as entrants but later edit their account to indicate their availability as a {{ $singular }} are added to the pool automatically too.
                You can assign {{ $singular }}s to the pool by checking the box next to a name or unassign by unchecking the box.</p>
            <p class="text-danger"><strong>Caution:</strong> {{ $singular }}s who are unassigned will also be removed from all table assignments.</p>
        @endif

        {{-- Legacy pool cross-nav (judging_locations.admin.php:514-565). --}}
        <div class="bcoem-admin-element d-print-none">
            <div class="btn-group" role="group">
                <a class="btn btn-secondary" href="{{ url('/backoffice/participants') }}"><span class="fa fa-arrow-circle-left"></span> All Participants</a>
            </div>
            <div class="btn-group" role="group">
                <a class="btn btn-secondary" href="{{ url('/admin/judging/tables') }}"><span class="fa fa-arrow-circle-left"></span> All Tables</a>
            </div>

            <div class="btn-group" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="{{ url('/backoffice/participants') }}">All Participants</a></li>
                    <li><a class="dropdown-item" href="{{ url('/backoffice/participants?filter=judges') }}">Available Judges</a></li>
                    <li><a class="dropdown-item" href="{{ url('/backoffice/participants?filter=stewards') }}">Available Stewards</a></li>
                </ul>
            </div>

            <div class="btn-group" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-check-circle"></span> Assign/Unassign...
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=judges') }}">Judges</a></li>
                    <li><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=bos') }}">BOS Judges</a></li>
                    <li><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=stewards') }}">Stewards</a></li>
                    <li><a class="dropdown-item" href="{{ url('/admin/judging/pool-assign?filter=staff') }}">Staff</a></li>
                    <li><a class="dropdown-item" href="{{ url('/admin/judging/tables?action=assign') }}">Judges/Stewards to Tables</a></li>
                </ul>
            </div>

            @if ($filter === 'bos')
                <div class="btn-group" role="group">
                    @if ($view === 'ranked')
                        <a class="btn btn-primary" href="{{ url('/admin/judging/pool-assign?filter=bos') }}"><span class="fa fa-filter"></span> Filter: All Judges</a>
                    @else
                        <a class="btn btn-primary" href="{{ url('/admin/judging/pool-assign?filter=bos&view=ranked') }}"><span class="fa fa-filter"></span> Filter: Ranked Judges Only</a>
                    @endif
                </div>
            @endif
        </div>

        {{-- Per-table assignment: reachable straight from the pool. A table
             (with flights) must exist first, so the control is swapped for a
             visual + textual alert when none do. --}}
        @if ($filter === 'judges' || $filter === 'stewards')
            @if ($tables->isEmpty())
                <div class="alert alert-warning d-flex align-items-center gap-2 d-print-none" role="alert">
                    <span class="fa fa-exclamation-triangle fa-lg" aria-hidden="true"></span>
                    <div>No tables have been created. Create a table and define its flights before assigning
                        {{ $filter }} to a table.</div>
                </div>
            @else
                <div class="bcoem-admin-element d-print-none">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="fa fa-table"></span> Assign {{ ucfirst($filter) }} to a Table
                        </button>
                        <ul class="dropdown-menu">
                            @foreach ($tables as $t)
                                <li><a class="dropdown-item" href="{{ route('admin.judging.assign.show', ['id' => $t->id, 'role' => $filter]) }}">Table {{ $t->tableNumber }}: {{ $t->tableName }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        @endif

        {{-- Assigned-emails modal triggers (legacy judges/stewards/staff). --}}
        @if (in_array($filter, ['judges', 'stewards', 'staff'], true))
            @php
                $emailTitle = $filter === 'judges' ? 'Assigned Judge Email Addresses'
                    : ($filter === 'stewards' ? 'Assigned Steward Email Addresses' : 'Assigned Staff Email Addresses');
            @endphp
            <div class="bcoem-admin-element d-print-none mt-2">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#poolEmailModal">
                        {{ $emailTitle }}
                    </button>
                </div>
            </div>

            <div class="modal fade" id="poolEmailModal" tabindex="-1" role="dialog" aria-labelledby="poolEmailModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header bcoem-admin-modal">
                            <h4 class="modal-title" id="poolEmailModalLabel">{{ $emailTitle }}</h4>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Copy and paste the list below into your favorite email program to contact all assigned {{ strtolower($filterLabel) }}.</p>
                            <textarea class="form-control" id="pool-email-list" rows="8" readonly>{{ implode(', ', $checkedEmails) }}</textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($filter === 'staff')
            <div class="bcoem-admin-element d-print-none mt-2">
                <div class="btn-group" role="group">
                    <label class="pe-2 align-middle" for="staff-organizer-ajax"><strong>Competition Organizer:</strong></label>
                    <select class="form-select" id="staff-organizer-ajax" name="staff_organizer" style="max-width: 26rem">
                        <option value="">Designate the Competition Organizer</option>
                        @foreach ($allBrewers as $b)
                            <option value="{{ $b->uid }}" @selected((int) $b->uid === $organizerUid)>
                                {{ $b->brewerLastName }}, {{ $b->brewerFirstName }}
                                @if ((int) $b->uid === $organizerUid)(Selected Competition Organizer)@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <span id="staff-organizer-ajax-staff_organizer-status"></span>
                <span id="staff-organizer-ajax-staff_organizer-status-msg"></span>
            </div>
        @endif

        @if ($allocatesTables)
            <h2 class="h4 mt-4">Unallocated to a Table ({{ count($unallocated) }})</h2>
            @include('judging.partials.pool_table', ['rows' => $unallocated])

            <h2 class="h4 mt-4">Allocated to a Table ({{ count($allocated) }})</h2>
            @include('judging.partials.pool_table', ['rows' => $allocated])
        @else
            @include('judging.partials.pool_table', ['rows' => $rows])
        @endif
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value;

            const post = (url, body) => {
                if (! csrf) return Promise.reject(new Error('no csrf'));
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: new URLSearchParams(body).toString(),
                }).then((r) => r.json());
            };

            const statusSpan = (uid, suffix) => document.getElementById(`assigned-${uid}-${suffix}`);
            const setStatus = (uid, col, ok) => {
                const el = statusSpan(uid, `${col}-status`);
                const msg = statusSpan(uid, `${col}-status-msg`);
                if (! el) return;
                el.innerHTML = ok
                    ? '<span class="fa fa-check text-success"></span>'
                    : '<span class="fa fa-times text-danger"></span>';
                if (msg) msg.textContent = ok ? '' : 'Save failed — not saved.';
            };

            // Pool membership checkboxes, scoped per table so the unallocated
            // and allocated tables each manage their own rows.
            document.querySelectorAll('[data-pool-table]').forEach((table) => {
                const boxes = table.querySelectorAll('input[type="checkbox"][data-uid]');

                boxes.forEach((box) => {
                    box.addEventListener('change', () => {
                        const uid = box.dataset.uid;
                        const col = box.dataset.col;
                        box.disabled = true;
                        post('{{ url('/admin/judging/pool-assign/staff') }}', {
                            action: 'judging_staff', go: col, id: uid, [col]: box.checked ? '1' : '0',
                        }).then((d) => {
                            if (d.status !== '1') throw new Error('save failed');
                            box.disabled = false;
                            box.value = box.checked ? '1' : '0';
                            setStatus(uid, col, true);
                            // Roles are mutually exclusive judge<->steward.
                            if (col === 'staff_judge' && box.checked) {
                                document.querySelectorAll('[data-pool-table] input[data-col="staff_steward"]').forEach((b) => { if (b.checked) b.checked = false; });
                            } else if (col === 'staff_steward' && box.checked) {
                                document.querySelectorAll('[data-pool-table] input[data-col="staff_judge"]').forEach((b) => { if (b.checked) b.checked = false; });
                            }
                        }).catch(() => {
                            box.checked = ! box.checked;
                            box.disabled = false;
                            setStatus(uid, col, false);
                        });
                    });
                });

                const checkAll = table.querySelector('[data-pool-check-all]');
                if (checkAll) {
                    checkAll.addEventListener('change', () => {
                        boxes.forEach((box) => {
                            if (! box.disabled) {
                                box.checked = checkAll.checked;
                                box.dispatchEvent(new Event('change'));
                            }
                        });
                    });
                }
            });

            // Inline table allocation (judges/stewards).
            document.querySelectorAll('.pool-assign-select').forEach((select) => {
                select.addEventListener('change', () => {
                    if (! select.value) return;
                    const [table, flight] = select.value.split(':');
                    select.disabled = true;
                    post('{{ url('/admin/judging/pool-assign/table') }}', {
                        action: 'assign', id: select.dataset.uid, role: select.dataset.role, table, flight,
                    }).then((d) => {
                        if (d.status === '1') {
                            window.location.reload();
                            return;
                        }
                        select.value = '';
                        select.disabled = false;
                        window.alert(d.error_type === '4'
                            ? 'This person has an entry at that table and cannot be assigned there.'
                            : 'Assignment failed.');
                    }).catch(() => {
                        select.value = '';
                        select.disabled = false;
                        window.alert('Assignment failed.');
                    });
                });
            });

            // Remove a participant from their table.
            document.querySelectorAll('.pool-remove').forEach((btn) => {
                btn.addEventListener('click', () => {
                    post('{{ url('/admin/judging/pool-assign/table') }}', {
                        action: 'remove', id: btn.dataset.uid, role: btn.dataset.role, table: btn.dataset.table,
                    }).then((d) => {
                        if (d.status === '1') window.location.reload();
                    });
                });
            });

            const organizer = document.getElementById('staff-organizer-ajax');
            if (organizer) {
                organizer.addEventListener('change', () => {
                    const okEl = document.getElementById('staff-organizer-ajax-staff_organizer-status');
                    const errEl = document.getElementById('staff-organizer-ajax-staff_organizer-status-msg');
                    okEl.innerHTML = '';
                    errEl.textContent = '';
                    post('{{ url('/admin/judging/pool-assign/staff') }}', {
                        action: 'judging_staff', go: 'staff_organizer', staff_organizer: organizer.value,
                    }).then((d) => {
                        if (d.status === '1' && organizer.value !== '') {
                            okEl.innerHTML = '<span class="fa fa-check text-success"></span>';
                        }
                    });
                });
            }
        });
    </script>
</x-public-layout>
