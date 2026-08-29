<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        // Legacy participants.admin.php subtitle (lines 57-77).
        $subtitle = match ($filter) {
            'judges' => 'Available Judges',
            'stewards' => 'Available Stewards',
            'with_entries' => 'Participants with Entries',
            default => 'Participants',
        };
        $proEdition = (int) $ctx->prefsStr('prefsProEdition') === 1;
        $allEmails = $participants->pluck('brewerEmail')->filter()->unique()->implode(', ');
        $totalParticipants = (int) $ctx->prefsStr('prefsRecordLimit');
        $overLimit = $participants->count() > $totalParticipants;
    @endphp

    <p class="lead">{{ $ctx->contestStr('contestName') }} {{ $subtitle }}</p>

    @if (request('msg') === 'deleted')
        <div class="alert alert-success">Participant deleted (all entries, scores, assignments and staff roles removed).</div>
    @elseif (request('msg') === 'updated')
        <div class="alert alert-success">Participant updated.</div>
    @elseif (request('msg') === 'self')
        <div class="alert alert-warning">Silly, you cannot delete yourself.</div>
    @elseif (request('msg') === 'not-found')
        <div class="alert alert-warning">Participant not found.</div>
    @endif

    @if ($overLimit)
        {{-- Legacy participants.admin.php:30-36 DataTables record-limit notice. --}}
        <div class="info">The DataTables recordset paging limit of {{ $totalParticipants }} has been surpassed. Filtering and sorting capabilites are only available for this set of {{ (int) $ctx->prefsStr('prefsRecordPaging') }} participants.<br />To adjust this setting, <a href="{{ url('/admin/site-preferences') }}">change your installation's DataTables Record Threshold</a> (under the &ldquo;Performance&rdquo; heading in preferences) to a number <em>greater</em> than the total number of participants ({{ $participants->count() }}).</div>
    @endif

    {{-- Legacy admin-element control row (participants.admin.php:543-720). --}}
    <div class="bcoem-admin-element hidden-print">
        <div class="row">
            <div class="col-lg-10 col-md-8 col-sm-12 col-xs-12">
                @if ($filter !== 'default')
                    <div class="btn-group" role="group">
                        <a class="btn btn-default" href="{{ url('/backoffice/participants') }}"><span class="fa fa-arrow-circle-left"></span> All Participants</a>
                    </div>
                @endif

                {{-- View... dropdown --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-eye"></span> View...
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        @if ($filter !== 'default')
                            <li class="small"><a href="{{ url('/backoffice/participants') }}">All Participants</a></li>
                        @endif
                        @if ($filter !== 'judges')
                            <li class="small"><a href="{{ url('/backoffice/participants?filter=judges') }}">Available Judges</a></li>
                        @endif
                        @if ($filter !== 'stewards')
                            <li class="small"><a href="{{ url('/backoffice/participants?filter=stewards') }}">Available Stewards</a></li>
                        @endif
                        @if ($filter !== 'with_entries')
                            <li class="small"><a href="{{ url('/backoffice/participants?filter=with_entries') }}">Participants with Entries</a></li>
                        @endif
                    </ul>
                </div>

                {{-- Register... dropdown --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-plus-circle"></span> Register...
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li class="small"><a href="{{ url('/register/entrant') }}">A Participant</a></li>
                        <li class="small"><a href="{{ url('/register/judge') }}">A Judge (Standard)</a></li>
                        <li class="small"><a href="{{ url('/register/steward') }}">A Steward (Standard)</a></li>
                        <li class="small"><a href="{{ url('/register/judge') }}?view=quick">A Judge (Quick)</a></li>
                        <li class="small"><a href="{{ url('/register/steward') }}?view=quick">A Steward (Quick)</a></li>
                    </ul>
                </div>

                {{-- Assign/Unassign... dropdown --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-check-circle"></span> Assign/Unassign...
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li class="small"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=judges">Judges</a></li>
                        <li class="small"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=bos">BOS Judges</a></li>
                        <li class="small"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=stewards">Stewards</a></li>
                        <li class="small"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff">Staff</a></li>
                        <li class="small"><a href="{{ url('/admin/judging/tables') }}?action=assign">Judges/Stewards to Tables</a></li>
                    </ul>
                </div>

                {{-- Print Current View... dropdown (TODO: legacy output route). --}}
                <div class="btn-group hidden-xs hidden-sm hidden-md" role="group">
                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-print"></span> Print Current View...
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        @if ($filter === 'default')
                            <li class="small"><a class="hide-loader" href="{{ url('/admin/output/participant_summary?psort=brewer_name') }}">By Last Name</a></li>
                            @if (! $proEdition)
                                <li class="small"><a class="hide-loader" href="{{ url('/admin/output/participant_summary?psort=club') }}">By Club</a></li>
                            @else
                                <li class="small"><a class="hide-loader" href="{{ url('/backoffice/participants?action=print&view=default&psort=organization') }}">By Organization Name</a></li>
                            @endif
                        @elseif ($filter === 'with_entries')
                            <li class="small"><a class="hide-loader" href="{{ url('/backoffice/participants?action=print&view=default&filter=with_entries') }}">{{ $proEdition ? 'By Organization Name' : 'By Entrant Last Name' }}</a></li>
                        @elseif ($filter === 'judges')
                            <li class="small"><a class="hide-loader" href="{{ url('/admin/output/participant_summary?filter=judges&psort=judge_id') }}">By Judge ID</a></li>
                            <li class="small"><a class="hide-loader" href="{{ url('/admin/output/participant_summary?filter=judges&psort=judge_rank') }}">By Judge Rank</a></li>
                        @elseif ($filter === 'stewards')
                            <li class="small"><a class="hide-loader" href="{{ url('/admin/output/participant_summary?filter=stewards&psort=brewer_name') }}">By Last Name</a></li>
                        @endif
                    </ul>
                </div>

                @if ($allEmails !== '')
                    {{-- All <subtitle> Email Addresses modal (participants.admin.php:660). --}}
                    <div class="btn-group hidden-xs hidden-sm" role="group">
                        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#allEmailModal">
                            All {{ ucwords($subtitle) }} Email Addresses
                        </button>
                    </div>
                @endif
            </div>

            <div class="col-lg-2 col-md-4 col-sm-12 col-xs-12">
                <div class="btn-group pull-right hidden-xs" role="group">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#participantStatusModal">
                            Participant Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Assignment modal(s): one per participant with judge/steward assignment. --}}
    @foreach ($participants as $p)
        @php
            $assignment = trim((string) ($p->brewerAssignment ?? ''));
            $hasJudge = str_contains($assignment, 'Judge');
            $hasSteward = str_contains($assignment, 'Steward');
            $tableJudge = $tableAssignments[$p->uid.'|J'] ?? '';
            $tableSteward = $tableAssignments[$p->uid.'|S'] ?? '';
            $entriesIn = $judgeEntries[$p->uid] ?? collect();
        @endphp
        @if (($hasJudge || $hasSteward) && $filter !== 'judges' && $filter !== 'stewards')
            <div class="modal fade" id="assignment-modal-{{ $p->uid }}" tabindex="-1" role="dialog" aria-labelledby="assignment-modal-label-{{ $p->uid }}">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header bcoem-admin-modal">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="assignment-modal-label-{{ $p->uid }}">Assignment(s) for {{ $p->brewerFirstName }} {{ $p->brewerLastName }}</h4>
                        </div>
                        <div class="modal-body">
                            @if ($hasJudge)
                                @if ($tableJudge !== '')
                                    <p>{{ $p->brewerFirstName }} is assigned as a <strong>judge</strong> to table(s):<br>{{ $tableJudge }}</p>
                                @else
                                    <p>{{ $p->brewerFirstName }} has been added to the <strong>judge</strong> pool, but has not been assigned to a table yet.</p>
                                @endif
                            @endif
                            @if ($hasSteward)
                                @if ($tableSteward !== '')
                                    <p>{{ $p->brewerFirstName }} is assigned as a <strong>steward</strong> to table(s):<br>{{ $tableSteward }}</p>
                                @else
                                    <p>{{ $p->brewerFirstName }} has been added to the <strong>steward</strong> pool, but has not been assigned to a table yet.</p>
                                @endif
                            @endif
                            @if ($entriesIn->isNotEmpty())
                                <p>Has entries in: {{ $entriesIn->pluck('label')->implode(', ') }}</p>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    @if ($allEmails !== '')
        {{-- All email addresses modal. --}}
        <div class="modal fade" id="allEmailModal" tabindex="-1" role="dialog" aria-labelledby="allEmailModalLabel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bcoem-admin-modal">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="allEmailModalLabel">Participant Email Addresses</h4>
                    </div>
                    <div class="modal-body">
                        <p>Copy and paste the list below into your favorite email program.</p>
                        <textarea class="form-control" rows="8">{{ ltrim($allEmails, ' ') }}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Participant Status modal. --}}
    <div class="modal fade" id="participantStatusModal" tabindex="-1" role="dialog" aria-labelledby="participantStatusModalLabel">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header bcoem-admin-modal">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="participantStatusModalLabel">Participant Status</h4>
                </div>
                <div class="modal-body">
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Participants</strong><span class="pull-right">{{ $statusCounts['participants'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Participants with Entries</strong><span class="pull-right">{{ $statusCounts['withEntries'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Available Judges</strong><span class="pull-right">{{ $statusCounts['judges'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Available Stewards</strong><span class="pull-right">{{ $statusCounts['stewards'] }}</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    @if ($participants->isEmpty())
        {{-- Legacy empty states (participants.admin.php:800-803). --}}
        @if ($filter === 'default')<div class="error">There are no participants yet.</div>
        @elseif ($filter === 'judges')<div class="error">There are no judges available yet.</div>
        @elseif ($filter === 'stewards')<div class="error">There are no stewards available yet.</div>
        @else<div class="error">There are no participants with entries yet.</div>
        @endif
    @else
        <table class="table table-responsive table-bordered table-striped" id="sortable">
            <thead>
                <tr>
                    @if ($filter === 'with_entries')
                        <th>{{ $proEdition ? 'Organization' : 'Name' }}</th>
                        <th>Entries</th>
                        <th class="print:hidden">Actions</th>
                    @else
                        <th>{{ $proEdition ? 'Contact Name' : 'Name' }}</th>
                        <th>User Level</th>
                        @if (($filter === 'judges' || $filter === 'stewards'))
                            <th class="print:hidden">Location(s) Available</th>
                        @else
                            <th class="print:hidden">{{ $proEdition ? 'Organization' : 'Club' }}</th>
                        @endif
                        @if ($filter === 'default')
                            <th class="print:hidden">Steward?</th>
                            <th class="print:hidden">Judge?</th>
                        @endif
                        <th>Assigned As</th>
                        @if ($filter !== 'default')
                            @if ($filter === 'judges')
                                <th class="print:hidden">ID</th>
                                <th>Rank</th>
                            @endif
                            <th>Assigned to Table(s)</th>
                            <th class="print:hidden">Has Entries In...</th>
                        @endif
                        <th class="print:hidden">Updated</th>
                        <th class="print:hidden">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($participants as $p)
                    @php
                        $displayName = $proEdition && ! empty($p->brewerBreweryName)
                            ? $p->brewerBreweryName
                            : $p->brewerLastName.', '.$p->brewerFirstName;
                        $level = (int) ($p->userLevel ?? 2);
                        $levelLabel = $level === 0 ? 'Top-Level Admin' : ($level === 1 ? 'Admin' : 'Participant');
                        $assignment = trim((string) ($p->brewerAssignment ?? ''));
                        $hasJudge = str_contains($assignment, 'Judge');
                        $hasSteward = str_contains($assignment, 'Steward');
                    @endphp
                    @if ($filter === 'with_entries')
                        <tr>
                            <td>{{ $displayName }}</td>
                            <td>
                                <a href="{{ url('/backoffice/entries?bid='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="List {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s entries.">Entry Numbers</a>: {{ $entryNumbers[$p->uid] ?? '' }}<br>Judging Numbers: {{ $judgingNumbers[$p->uid] ?? '' }}
                            </td>
                            <td class="print:hidden">
                                {{-- Legacy with_entries row (participants.admin.php): full action
                                     icon set — edit account, delete account, edit user level,
                                     add entry, list entries. --}}
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/backoffice/participants/'.$p->uid.'/edit') }}" data-toggle="tooltip" data-placement="top" title="Edit {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s account information."><span class="fa fa-lg fa-pencil"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/backoffice/participants/'.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Delete {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s account." data-confirm="Are you sure you want to delete the participant account for {{ $p->brewerFirstName }} {{ $p->brewerLastName }}? ALL entries for this participant WILL BE DELETED as well. This cannot be undone."><span class="fa fa-lg fa-trash-o"></span></a></span>
                                <span style="margin-right: .4em"><a href="{{ url('/backoffice/participants/'.$p->uid.'/edit') }}" data-toggle="tooltip" data-placement="top" title="Edit {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s user account information"><span class="fa fa-lg fa-pencil"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/user/username?filter=admin&id='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Change {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s email address"><span class="fa fa-lg fa-user"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/brew?filter='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Add an entry for {{ $p->brewerFirstName }} {{ $p->brewerLastName }}"><span class="fa fa-lg fa-beer"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/backoffice/entries?bid='.$p->uid) }}" title="List {{ $p->brewerFirstName }} {{ $p->brewerLastName }}'s entries."><span class="fa fa-lg fa-list"></span></a></span>
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td>
                                <a name="{{ $p->uid }}"></a>
                                {{ $displayName }}
                                @if ($level === 0)<i class="fa fa-sm fa-lock text-danger"></i>
                                @elseif ($level === 1)<i class="fa fa-sm fa-lock text-warning"></i>
                                @endif
                                <br><small>{{ $p->brewerCity }}, {{ $p->brewerState }}</small>
                            </td>
                            <td>
                                {{ $levelLabel }}
                                @if ($level === 0)
                                    <i class="fa fa-sm fa-eye" data-toggle="tooltip" data-placement="top" title="{{ $displayName }} can view Judging Numbers - edit their user level to change."></i>
                                @else
                                    <i class="fa fa-sm fa-eye-slash" data-toggle="tooltip" data-placement="top" title="{{ $displayName }} CANNOT view Judging Numbers - edit their user level to change."></i>
                                @endif
                            </td>
                            @if ($filter === 'judges' || $filter === 'stewards')
                                <td class="print:hidden">{{ $locationDisplay($filter === 'judges' ? $p->brewerJudgeLocation : $p->brewerStewardLocation) }}</td>
                            @else
                                <td class="print:hidden">{{ $p->brewerClubs }}</td>
                            @endif
                            @if ($filter === 'default')
                                <td class="print:hidden">
                                    @if ($p->brewerSteward === 'Y')<span class="fa fa-lg fa-check text-success"></span>
                                    @elseif ($p->brewerSteward === 'N')<span class="fa fa-lg fa-times text-danger"></span>
                                    @endif
                                </td>
                                <td class="print:hidden">
                                    @if ($p->brewerJudge === 'Y')<span class="fa fa-lg fa-check text-success"></span>
                                    @elseif ($p->brewerJudge === 'N')<span class="fa fa-lg fa-times text-danger"></span>
                                    @endif
                                </td>
                            @endif
                            <td>
                                @if ($assignment !== '')
                                    @if (($hasJudge || $hasSteward) && $filter !== 'judges' && $filter !== 'stewards')
                                        <button type="button" class="btn btn-link" style="margin:0; padding:0;" data-toggle="modal" data-target="#assignment-modal-{{ $p->uid }}">{{ ucwords($assignment) }}</button>
                                    @else
                                        {{ ucwords($assignment) }}
                                    @endif
                                @endif
                            </td>
                            @if ($filter !== 'default')
                                @if ($filter === 'judges')
                                    <td class="print:hidden">{{ $p->brewerJudgeID }}</td>
                                    <td>{{ $p->brewerJudgeRank }}</td>
                                @endif
                                <td>
                                    @foreach ($tableAssignments[$p->uid.'|'.($filter === 'judges' ? 'J' : 'S')] ?? [] as $i => $t)
                                        @if ($i !== 0),&nbsp;@endif
                                        @if ($filter === 'judges')
                                            <a href="{{ url('/admin/judging/tables') }}?action=assign&filter=judges&id={{ $t['id'] }}" data-toggle="tooltip" title="Assign/Unassign Judges to Table {{ $t['label'] }}">{{ $t['label'] }}</a>
                                        @else
                                            <a href="{{ url('/admin/judging/tables') }}?action=assign&filter=stewards&id={{ $t['id'] }}" data-toggle="tooltip" title="Assign/Unassign Stewards to Table {{ $t['label'] }}">{{ $t['label'] }}</a>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="print:hidden">
                                    @foreach ($judgeEntries[$p->uid] ?? collect() as $i => $e)
                                        @if ($i !== 0), @endif
                                        <a href="{{ url('/backoffice/entries?filter='.$e['filter']) }}" title="View the {{ $e['label'] }} Entries">{{ $e['label'] }}</a>
                                    @endforeach
                                </td>
                            @endif
                            <td class="print:hidden">
                                @if ($p->userCreated)
                                    {{ \App\Support\Tenant\DateFmt::dateTime(strtotime((string) $p->userCreated) ?: null, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', false) }}
                                @endif
                            </td>
                            <td class="print:hidden">
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/brew?filter='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Add an entry for {{ $displayName }}"><span class="fa fa-lg fa-beer"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="{{ route('backoffice.participants.edit', ['uid' => $p->uid]) }}" data-toggle="tooltip" data-placement="top" title="Edit {{ $displayName }}'s user account information"><span class="fa fa-lg fa-pencil"></span></a></span>
                                @if ($viewerLevel === 0)
                                    @if ($p->brewerEmail !== auth()->user()?->user_name)
                                        <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/backoffice/participants?bid='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Change {{ $displayName }}'s User Level"><span class="fa fa-lg fa-lock"></span></a></span>
                                    @else
                                        <span style="margin-right: .4em"><span class="fa fa-lg fa-lock text-muted" data-toggle="tooltip" data-placement="top" title="You cannot change your own user level, {{ auth()->user()?->user_name }}."></span></span>
                                    @endif
                                    @if ($p->brewerEmail !== auth()->user()?->user_name)
                                        <span style="margin-right: .4em">
                                            <form method="post" action="{{ route('backoffice.participants.destroy', ['uid' => $p->uid]) }}" class="inline" onsubmit="return confirm('Delete the participant account for {{ $displayName }}? ALL entries for this participant WILL BE DELETED as well. This cannot be undone.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-link" style="margin:0; padding:0;" title="Delete {{ $displayName }}'s account."><span class="fa fa-lg fa-trash-o"></span></button>
                                            </form>
                                        </span>
                                    @else
                                        <span style="margin-right: .4em"><span class="fa fa-lg fa-trash-o text-muted" data-toggle="tooltip" data-placement="top" title="Silly, you cannot delete yourself, {{ auth()->user()?->user_name }}!"></span></span>
                                    @endif
                                    <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/user/username?filter=admin&id='.$p->uid) }}" data-toggle="tooltip" data-placement="top" title="Change {{ $displayName }}'s email address"><span class="fa fa-lg fa-user"></span></a></span>
                                    <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/user/password') }}" data-toggle="tooltip" data-placement="top" title="Change {{ $displayName }}'s password"><span class="fa fa-lg fa-key"></span></a></span>
                                @endif
                                <span style="margin-right: .4em"><a class="hide-loader" href="mailto:{{ $p->brewerEmail }}" data-toggle="tooltip" data-placement="top" title="Email {{ $displayName }} at {{ $p->brewerEmail }}"><span class="fa fa-lg fa-envelope"></span></a></span>
                                <span style="margin-right: .4em"><a class="hide-loader" href="#" data-toggle="tooltip" data-placement="top" title="{{ $displayName }}'s phone number: {{ $p->brewerPhone1 }}"><span class="fa fa-lg fa-phone"></span></a></span>
                                @if (str_contains((string) ($tableAssignments[$p->uid.'|J'] ?? ''), 'Judge') || ($staffJudge[$p->uid] ?? false))
                                    <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/admin/output/labels?action=judging_labels&go=participants&id='.$p->uid.'&psort=5160') }}" data-toggle="tooltip" data-placement="top" title="Download Judge Scoresheet Labels for {{ $displayName }} - Letter (Avery 5160)"><span class="fa fa-lg fa-file"></span></a></span>
                                    <span style="margin-right: .4em"><a class="hide-loader" href="{{ url('/admin/output/labels?action=judging_labels&go=participants&id='.$p->uid.'&psort=3422') }}" data-toggle="tooltip" data-placement="top" title="Download Judge Scoresheet Labels for {{ $displayName }} - A4 (Avery 3422)"><span class="fa fa-lg fa-file-text"></span></a></span>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</x-public-layout>
