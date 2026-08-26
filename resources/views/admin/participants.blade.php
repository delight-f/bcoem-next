<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        $subtitle = match ($filter) {
            'judges' => 'Available Judges',
            'stewards' => 'Available Stewards',
            'with_entries' => 'Participants with Entries',
            default => 'Participants',
        };
        // Legacy "All <subtitle> Email Addresses" copy/paste modal.
        $allEmails = $participants->pluck('brewerEmail')->filter()->unique()->implode(', ');
    @endphp
    <section class="container mt-6 mb-4">
        <h1>Participants</h1>

        @if (request('msg') === 'deleted')
            <div class="alert alert-success">Participant deleted (all entries, scores, assignments and staff roles removed).</div>
        @elseif (request('msg') === 'updated')
            <div class="alert alert-success">Participant updated.</div>
        @elseif (request('msg') === 'self')
            <div class="alert alert-warning">Silly, you cannot delete yourself.</div>
        @elseif (request('msg') === 'not-found')
            <div class="alert alert-warning">Participant not found.</div>
        @endif

        {{-- Legacy admin-element control row (participants.admin.php:538+). --}}
        <div class="mb-4 flex flex-wrap gap-2 items-start">
            <div class="flex flex-wrap gap-2">
                @if ($filter !== 'default')
                    <a class="btn btn-secondary" href="{{ url('/backoffice/participants') }}">&larr; All Participants</a>
                @endif

                {{-- Register... dropdown (participants.admin.php:576). URLs follow
                     the Admin Essentials menu mapping (1deb35c): the port register
                     forms are the canonical surfaces, quick variants pass view=quick. --}}
                <div class="dropdown">
                    <button type="button" class="btn btn-secondary dropdown-toggle">
                        <span class="fa fa-plus-circle"></span> Register... <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li class="small"><a class="dropdown-item" href="{{ url('/register/entrant') }}">A Participant</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/register/judge') }}">A Judge (Standard)</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/register/steward') }}">A Steward (Standard)</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/register/judge') }}?view=quick">A Judge (Quick)</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/register/steward') }}?view=quick">A Steward (Quick)</a></li>
                    </ul>
                </div>

                {{-- Assign/Unassign... dropdown (participants.admin.php:591); same
                     targets as the Admin Essentials menu fix. --}}
                <div class="dropdown">
                    <button type="button" class="btn btn-secondary dropdown-toggle">
                        <span class="fa fa-check-circle"></span> Assign/Unassign... <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/locations') }}?action=assign&filter=judges">Judges</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/locations') }}?action=assign&filter=bos">BOS Judges</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/locations') }}?action=assign&filter=stewards">Stewards</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/locations') }}?action=assign&filter=staff">Staff</a></li>
                        <li class="small"><a class="dropdown-item" href="{{ url('/admin/judging/tables') }}?action=assign">Judges/Stewards to Tables</a></li>
                    </ul>
                </div>

                {{-- Print Current View... dropdown (participants.admin.php:606).
                     TODO: legacy output — the print targets are
                     includes/output.inc.php?section=admin&go=participants&action=print
                     re-rendering participants.admin.php in print mode; no port
                     output route exists yet. --}}
                <div class="dropdown">
                    <button type="button" class="btn btn-secondary dropdown-toggle">
                        <span class="fa fa-print"></span> Print Current View... <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        @if ($filter === 'default')
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Last Name</a></li>
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Club</a></li>
                        @elseif ($filter === 'with_entries')
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Entrant Last Name</a></li>
                        @elseif ($filter === 'judges')
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Judge ID</a></li>
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Judge Rank</a></li>
                        @elseif ($filter === 'stewards')
                            <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Last Name</a></li>
                        @endif
                    </ul>
                </div>

                @if ($allEmails !== '')
                    {{-- All Participant Email Addresses modal (participants.admin.php:660). --}}
                    <button type="button" class="btn btn-info" data-open-modal="allEmailModal">
                        All {{ $subtitle }} Email Addresses
                    </button>
                @endif
            </div>

            {{-- Participant Status modal trigger (participants.admin.php:689). --}}
            <button type="button" class="btn btn-success ms-auto" data-open-modal="participantStatusModal">
                Participant Status
            </button>
        </div>

        @if ($allEmails !== '')
            <dialog class="modal" id="allEmailModal">
                <div class="modal-box">
                    <h4 class="font-bold" id="allEmailModalLabel">Participant Email Addresses</h4>
                    <p>Copy and paste the list below into your favorite email program.</p>
                    <textarea class="w-full border rounded p-2 font-mono text-sm" rows="8" readonly>{{ $allEmails }}</textarea>
                    <div class="modal-action">
                        <form method="dialog"><button class="btn">Close</button></form>
                    </div>
                </div>
            </dialog>
        @endif

        <dialog class="modal" id="participantStatusModal">
            <div class="modal-box">
                    <h4 class="font-bold" id="participantStatusModalLabel">Participant Status</h4>
                    <div>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <strong class="text-info">Participants</strong><span>{{ $statusCounts['participants'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <strong class="text-info">Participants with Entries</strong><span>{{ $statusCounts['withEntries'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <strong class="text-info">Available Judges</strong><span>{{ $statusCounts['judges'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <strong class="text-info">Available Stewards</strong><span>{{ $statusCounts['stewards'] }}</span>
                        </div>
                    </div>
                    <div class="modal-action">
                        <form method="dialog"><button class="btn">Close</button></form>
                    </div>
            </div>
        </dialog>

        {{-- Legacy filters: default / judges / stewards / with_entries --}}
        <ul class="nav nav-pills mb-4">
            @foreach ([['default', 'All'], ['judges', 'Available Judges'], ['stewards', 'Available Stewards'], ['with_entries', 'Participants with Entries']] as [$f, $label])
                <li class="nav-item">
                    <a class="nav-link {{ $filter === $f ? 'active' : '' }}"
                       href="{{ url('/backoffice/participants').($f !== 'default' ? '?filter='.$f : '').($q !== '' ? '&q='.urlencode($q) : '') }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>

        <form method="get" action="{{ url('/backoffice/participants') }}" class="row row-cols-auto g-2 mb-4">
            @if ($filter !== 'default')
                <input type="hidden" name="filter" value="{{ $filter }}">
            @endif
            <input type="hidden" name="q" value="{{ $q }}">
            <input class="input input-bordered" name="q" placeholder="Search participants…"
                   value="{{ $q }}">
            <button type="submit" class="btn btn-outline btn-secondary">Search</button>
        </form>
        @if ($participants->isEmpty())
            <p>No participants found.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>User Level</th>
                        @if ($filter === 'judges' || $filter === 'stewards')
                            <th>Location(s) Available</th>
                        @else
                            <th>Club</th>
                        @endif
                        @if ($filter === 'default')
                            <th>Steward?</th>
                            <th>Judge?</th>
                        @endif
                        @if ($filter === 'with_entries')
                            <th>Entries</th>
                        @else
                            <th>Assigned As</th>
                        @endif
                        @if ($filter === 'judges')
                            <th>ID</th>
                            <th>Rank</th>
                        @endif
                        @if ($filter === 'judges' || $filter === 'stewards')
                            <th>Assigned to Table(s)</th>
                            <th class="print:hidden">Has Entries In...</th>
                        @endif
                        @if ($filter !== 'with_entries')
                            <th class="print:hidden">Updated</th>
                        @endif
                        <th class="print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        <tr>
                            <td>{{ $p->brewerFirstName }} {{ $p->brewerLastName }}</td>
                            <td>{{ $p->userLevel }}</td>
                            @if ($filter === 'judges' || $filter === 'stewards')
                                <td>{{ $locationDisplay($filter === 'judges' ? $p->brewerJudgeLocation : $p->brewerStewardLocation) }}</td>
                            @else
                                <td>{{ $p->brewerClubs }}</td>
                            @endif
                            @if ($filter === 'default')
                                <td>@if ($p->brewerSteward === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                                <td>@if ($p->brewerJudge === 'Y')<span class="text-success">&#10003;</span>@endif</td>
                            @endif
                            @if ($filter === 'with_entries')
                                <td>{{ $entryCounts[$p->uid] ?? 0 }}</td>
                            @else
                                <td>{{ $p->brewerAssignment }}</td>
                            @endif
                            @if ($filter === 'judges')
                                <td>{{ $p->brewerJudgeID }}</td>
                                <td>{{ $p->brewerJudgeRank }}</td>
                            @endif
                            @if ($filter === 'judges' || $filter === 'stewards')
                                <td>{{ $tableAssignments[$p->uid.'|'.($filter === 'judges' ? 'J' : 'S')] ?? '' }}</td>
                                <td class="print:hidden">
                                    @foreach ($judgeEntries[$p->uid] ?? collect() as $i => $e)
                                        @if ($i !== 0), @endif
                                        <a href="{{ '/backoffice/entries?filter='.$e['filter'] }}"
                                           title="View the {{ $e['label'] }} Entries">{{ $e['label'] }}</a>
                                    @endforeach
                                </td>
                            @endif
                            @if ($filter !== 'with_entries')
                                <td class="print:hidden">
                                    @if ($p->userCreated)
                                        {{ \App\Support\Tenant\DateFmt::dateTime(strtotime($p->userCreated) ?: null, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', false) }}
                                    @endif
                                </td>
                            @endif
                            <td class="print:hidden">
                                <a href="{{ route('backoffice.participants.edit', ['uid' => $p->uid]) }}">Edit</a>
                                <a href="{{ '/backoffice/entries?bid='.$p->uid }}">Entries</a>
                                <form method="post" action="{{ route('backoffice.participants.destroy', ['uid' => $p->uid]) }}" class="inline"
                                      onsubmit="return confirm('Delete the participant account for {{ $p->brewerFirstName }} {{ $p->brewerLastName }}? ALL entries for this participant WILL BE DELETED as well. This cannot be undone.');">
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
