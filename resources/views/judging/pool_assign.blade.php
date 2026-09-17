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
    <section class="landing-page-section mt-6 mb-4">
        <p class="lead">{{ $ctx->contestStr('contestName') }}: Assign or Unassign Participants as {{ $filterLabel }}</p>

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

        @if (empty($rows))
            <div class="error">No participants have been assigned to the {{ $singular }} pool.</div>
        @else
            <table class="table table-responsive table-bordered {{ $filter !== 'bos' ? 'table-striped' : '' }}" id="sortable" data-dt data-dt-page="{{ (int) $ctx->prefsStr('prefsRecordPaging') ?: 25 }}">
                <thead>
                    <tr>
                        <th style="width:1%" nowrap>
                            <input type="checkbox" id="pool-check-all" aria-label="Check all">
                        </th>
                        <th>Name</th>
                        <th class="hidden-xs hidden-sm">Assigned As</th>
                        @if ($filter === 'bos')
                            <th>Placing Entries</th>
                        @endif
                        @if ($filter === 'judges' || $filter === 'bos')
                            <th class="hidden-xs hidden-sm">ID</th>
                            <th>Rank</th>
                        @endif
                        @if (in_array($filter, ['judges', 'stewards', 'staff'], true))
                            <th class="hidden-xs hidden-sm">Preferences</th>
                        @endif
                        @if ($filter === 'judges' || $filter === 'stewards')
                            <th class="hidden-xs hidden-sm" style="width:30%">Has Entries In...</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @if ($filter === 'bos' && $row['hasPlacingEntries']) class="bg-danger text-danger"
                            @elseif ($filter === 'bos' && ! $row['hasPlacingEntries'] && $row['checked']) class="bg-info text-info"
                            @elseif ($filter === 'bos' && ! $row['hasPlacingEntries']) class="bg-success text-success" @endif>
                            <td>
                                <input type="checkbox"
                                       name="{{ $staffColumn }}{{ $row['uid'] }}"
                                       value="{{ $row['checked'] ? 1 : 0 }}"
                                       id="assigned-{{ $row['uid'] }}"
                                       @checked($row['checked'])
                                       @disabled($row['disabled'])
                                       data-uid="{{ $row['uid'] }}"
                                       data-col="{{ $staffColumn }}"
                                       aria-label="Assign {{ $row['name'] }} as {{ $singular }}">
                            </td>
                            <td>
                                {{ $row['name'] }}
                                <div>
                                    <span id="assigned-{{ $row['uid'] }}-{{ $staffColumn }}-status"></span>
                                    <span id="assigned-{{ $row['uid'] }}-{{ $staffColumn }}-status-msg"></span>
                                </div>
                            </td>
                            <td class="hidden-xs hidden-sm">{{ ucwords($row['assignmentLabel']) }}</td>
                            @if ($filter === 'bos')
                                <td>{!! $row['placingEntries'] ?: '&nbsp;' !!}</td>
                            @endif
                            @if ($filter === 'judges' || $filter === 'bos')
                                <td class="hidden-xs hidden-sm">{{ strtoupper((string) $row['judgeId']) }}</td>
                                <td>{!! $row['rankDisplay'] !!}</td>
                            @endif
                            @if (in_array($filter, ['judges', 'stewards', 'staff'], true))
                                <td class="hidden-xs hidden-sm">{!! $row['preferences'] ?: ($filter === 'staff' ? '&nbsp;' : '<span class="fa fa-sm fa-ban text-danger"></span> <a href="'.url('/backoffice/participants/'.$row['uid'].'/edit').'" data-bs-toggle="tooltip" title="Enter '.$row['firstName'].'\'s location preferences">None specified</a>') !!}</td>
                            @endif
                            @if ($filter === 'judges' || $filter === 'stewards')
                                <td class="hidden-xs hidden-sm">
                                    @if ($row['entryCount'] > 0)
                                        <a href="{{ url('/backoffice/entries?filter='.$row['uid']) }}">{{ $row['entryCount'] }} entr{{ $row['entryCount'] === 1 ? 'y' : 'ies' }}</a>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value;

            const statusSpan = (uid, suffix) => document.getElementById(`assigned-${uid}-${suffix}`);
            // The row renders ...-status / ...-status-msg spans; the handler
            // used to look up ...-ok / ...-err, which never exist, so the
            // success branch threw before re-enabling the box and left the
            // checkbox permanently disabled (the "frozen" UI).
            const setStatus = (uid, col, ok) => {
                const el = statusSpan(uid, `${col}-status`);
                const msg = statusSpan(uid, `${col}-status-msg`);
                if (! el) return;
                if (ok) {
                    el.innerHTML = '<span class="fa fa-check text-success"></span>';
                    if (msg) msg.textContent = '';
                } else {
                    el.innerHTML = '<span class="fa fa-times text-danger"></span>';
                    if (msg) msg.textContent = 'Save failed — not saved.';
                }
            };

            const saveColumn = (body) => {
                if (! csrf) return Promise.reject(new Error('no csrf'));
                const params = new URLSearchParams({ action: 'judging_staff', ...body });
                return fetch('{{ url('/admin/judging/pool-assign/staff') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: params.toString(),
                }).then((r) => r.json()).then((d) => d.status === '1');
            };

            document.querySelectorAll('#sortable input[type="checkbox"][data-uid]').forEach((box) => {
                box.addEventListener('change', () => {
                    const uid = box.dataset.uid;
                    const col = box.dataset.col;
                    const settle = (ok) => {
                        if (! ok) box.checked = ! box.checked;
                        box.value = box.checked ? '1' : '0';
                        box.disabled = false;
                        setStatus(uid, col, ok);
                    };
                    box.disabled = true;
                    saveColumn({ go: col, id: uid, [col]: box.checked ? '1' : '0' })
                        .then((ok) => {
                            settle(ok);
                            // Roles are mutually exclusive judge<->steward; on
                            // assignment, clear the opposite-role box.
                            if (ok && col === 'staff_judge' && box.checked) {
                                document.querySelectorAll('#sortable input[data-col="staff_steward"]').forEach((b) => { if (b.checked) b.checked = false; });
                            } else if (ok && col === 'staff_steward' && box.checked) {
                                document.querySelectorAll('#sortable input[data-col="staff_judge"]').forEach((b) => { if (b.checked) b.checked = false; });
                            }
                        })
                        .catch(() => settle(false));
                });
            });

            const checkAll = document.getElementById('pool-check-all');
            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    document.querySelectorAll('#sortable input[type="checkbox"][data-uid]').forEach((box) => {
                        if (! box.disabled) {
                            box.checked = checkAll.checked;
                            box.dispatchEvent(new Event('change'));
                        }
                    });
                });
            }

            const organizer = document.getElementById('staff-organizer-ajax');
            if (organizer) {
                organizer.addEventListener('change', () => {
                    const okEl = document.getElementById('staff-organizer-ajax-staff_organizer-status');
                    const errEl = document.getElementById('staff-organizer-ajax-staff_organizer-status-msg');
                    okEl.innerHTML = '';
                    errEl.textContent = '';
                    saveColumn({ go: 'staff_organizer', staff_organizer: organizer.value })
                        .then((ok) => {
                            if (ok) {
                                if (organizer.value !== '') {
                                    okEl.innerHTML = '<span class="fa fa-check text-success"></span>';
                                }
                            } else {
                                errEl.textContent = 'Save failed — not saved.';
                            }
                        })
                        .catch(() => { errEl.textContent = 'Save failed — not saved.'; });
                });
            }
        });
    </script>
</x-public-layout>
