<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        $statusLabel = $view === 'paid' ? 'Paid' : ($view === 'unpaid' ? 'Unpaid' : 'All');
        // Legacy mark-all msg codes (headers.inc.php 642-656).
        $msgTexts = [
            20 => 'All entries have been marked as paid.',
            21 => 'All entries have been marked as received.',
            22 => 'All unconfirmed entries are now marked as confirmed.',
            34 => 'All entries have been un-marked as paid.',
            35 => 'All entries have been un-marked as received.',
        ];
    @endphp
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $statusLabel }} Entries</h1>

        @if (request('msg') === 'updated')
            <div class="alert alert-success">Entry updated.</div>
        @elseif (request('msg') === 'deleted')
            <div class="alert alert-success">Entry deleted.</div>
        @elseif (in_array((int) request('msg'), array_keys($msgTexts), true))
            <div class="alert alert-success">{{ $msgTexts[(int) request('msg')] }}</div>
        @endif

        {{-- Legacy admin-element control row (entries.admin.php:753+). --}}
        <div class="mb-4 flex flex-wrap gap-2 items-start">
            <div class="dropdown">
                <button type="button" class="btn btn-secondary dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    Add an Entry For... <span class="caret"></span>
                </button>
                <ul class="dropdown-menu" role="listbox" aria-label="Choose participant">
                    @foreach ($participants as $p)
                        <li class="small">
                            <a class="dropdown-item" href="{{ '/backoffice/entries?bid='.$p->uid }}">
                                {{ $p->brewerLastName }}, {{ $p->brewerFirstName }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Print Current View... dropdown (entries.admin.php:784).
                 TODO: legacy output — the print targets re-render
                 admin/entries.admin.php in print mode via
                 includes/output.inc.php?section=admin&go=entries&action=print;
                 no port output route exists yet. --}}
            <div class="dropdown">
                <button type="button" class="btn btn-secondary dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-print"></span> Print Current View... <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Entry Number</a></li>
                    <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Judging Number</a></li>
                    <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Style</a></li>
                    <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Brewer Last Name</a></li>
                    <li class="small"><a class="dropdown-item disabled" aria-disabled="true">By Entry Name</a></li>
                </ul>
            </div>

            {{-- Admin Actions dropdown — legacy process.inc.php mark-all
                 actions (entries.admin.php:817); POST + confirm mirrors the
                 port's CSRF hardening of legacy bare GET links. --}}
            <div class="dropdown">
                <button type="button" class="btn btn-secondary dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-check-circle"></span> Admin Actions <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    @foreach ([
                        'paid' => ['Mark All as Paid', 'Are you sure? This will mark ALL entries as paid and could be a large pain to undo.', 20],
                        'unpaid' => ['Un-Mark All as Paid', 'Are you sure? This will mark ALL entries as unpaid and could be a large pain to undo.', 34],
                        'received' => ['Mark All as Received', 'Are you sure? This will mark ALL entries as received and could be a large pain to undo.', 21],
                        'not-received' => ['Un-Mark All as Received', 'Are you sure? This will mark ALL entries as NOT received and could be a large pain to undo.', 35],
                        'confirmed' => ['Confirm All Entries', 'Are you sure? This will mark ALL entries as confirmed and could be a large pain to undo.', 22],
                    ] as $action => [$label, $confirm, $msg])
                        <li class="small">
                            <form method="post" action="{{ route('backoffice.entries.mark_all') }}"
                                  onsubmit="return confirm('{{ $confirm }}');">
                                @csrf
                                <input type="hidden" name="action" value="{{ $action }}">
                                <button type="submit" class="dropdown-item">{{ $label }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>

            <button type="button" class="btn btn-success ms-auto" data-open-modal="entryStatusModal">
                {{ $statusLabel }} Entry Status
            </button>
        </div>

        @foreach ([['allEmailModal', 'all', 'All Participants with Entries Email Addresses', 'to contact all participants with entries'], ['paidEmailModal', 'paid', 'All Participants with Paid Entries Email Addresses', 'to contact participants with <strong>PAID</strong> entries'], ['unpaidEmailModal', 'unpaid', 'All Participants with Unpaid Entries Email Addresses', 'to contact participants with <strong>UNPAID</strong> entries']] as [$modalId, $key, $title, $purpose])
            @if ($emailLists[$key] !== '')
                <button type="button" class="btn btn-info mb-4 me-2" data-open-modal="{{ $modalId }}">{{ $title }}</button>
                <dialog class="modal" id="{{ $modalId }}">
                    <div class="modal-box">
                        <h4 class="font-bold">{{ $title }}</h4>
                        <p>Copy and paste the list below into your favorite email program {!! $purpose !!}.</p>
                        <textarea class="w-full border rounded p-2 font-mono text-sm" rows="8" readonly>{{ $emailLists[$key] }}</textarea>
                        <div class="modal-action">
                            <form method="dialog"><button class="btn">Close</button></form>
                        </div>
                    </div>
                </dialog>
            @endif
        @endforeach

        <dialog class="modal" id="entryStatusModal">
            <div class="modal-box">
                <h4 class="font-bold" id="entryStatusModalLabel">{{ $statusLabel }} Entry Status</h4>
                <div class="d-flex justify-content-between border-bottom py-1">
                    <strong class="text-info">Confirmed Entries</strong><span>{{ $entryStatus['confirmed'] }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-1">
                    <strong class="text-info">Unconfirmed Entries</strong><span>{{ $entryStatus['unconfirmed'] }}</span>
                </div>
                @if (isset($entryStatus['paidConfirmed']))
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Paid Confirmed Entries</strong><span>{{ $entryStatus['paidConfirmed'] }}</span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Unpaid Confirmed Entries</strong><span>{{ $entryStatus['unpaidConfirmed'] }}</span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Received Entries</strong><span>{{ $entryStatus['received'] }}</span>
                    </div>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Total Fees</strong>
                        <span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFees'], 2) }}</span>
                    </div>
                @endif
                @if (isset($entryStatus['totalFeesPaid']))
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Total Fees Paid</strong>
                        <span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFeesPaid'], 2) }}</span>
                    </div>
                @endif
                @if (isset($entryStatus['totalFeesUnpaid']))
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <strong class="text-info">Total Fees Unpaid</strong>
                        <span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFeesUnpaid'], 2) }}</span>
                    </div>
                @endif
                <div class="modal-action">
                    <form method="dialog"><button class="btn">Close</button></form>
                </div>
            </div>
        </dialog>

        {{-- Legacy filters: paid/unpaid view + category + participant --}}
        <form method="get" action="{{ url('/backoffice/entries') }}" class="row row-cols-auto g-2 items-end mb-4">
            <div>
                <label class="form-label" for="f-view">View</label>
                <select id="f-view" name="view" class="select select-bordered">
                    @foreach ([['default', 'All'], ['paid', 'Paid'], ['unpaid', 'Unpaid']] as [$v, $label])
                        <option value="{{ $v }}" @selected($view === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="f-filter">Category</label>
                <input id="f-filter" name="filter" class="input input-bordered" placeholder="e.g. 01 or C1"
                       value="{{ $filter !== 'default' ? $filter : '' }}">
            </div>
            <div>
                <label class="form-label" for="f-bid">Participant uid</label>
                <input id="f-bid" name="bid" class="input input-bordered" placeholder="all"
                       value="{{ $bid !== 'default' ? $bid : '' }}">
            </div>
            <button type="submit" class="btn btn-outline btn-secondary">Filter</button>
        </form>

        @if ($entries->isEmpty())
            <p>No entries found.</p>
        @else
            <table class="table table-zebra table-bordered">
                <thead>
                    <tr>
                        <th>Entry</th>
                        <th>Judging</th>
                        <th>Name</th>
                        <th>Style</th>
                        <th>Brewer</th>
                        <th>Club</th>
                        <th>Updated</th>
                        <th>Paid?</th>
                        <th>Rec'd?</th>
                        <th>Admin Notes</th>
                        <th>Staff Notes</th>
                        <th>Loc/Box</th>
                        <th class="print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ \App\Http\Controllers\Admin\EntriesController::entryNumber($entry->id) }}</td>
                            <td>{{ $entry->brewJudgingNumber }}</td>
                            <td>{{ $entry->brewName }}</td>
                            <td>{{ ltrim((string) $entry->brewCategorySort, '0') }}{{ $entry->brewSubCategory }}
                                {{ $entry->brewStyle }}</td>
                            <td>{{ $entry->brewBrewerFirstName }} {{ $entry->brewBrewerLastName }}</td>
                            <td>{{ $entry->brewerClubs }}</td>
                            <td>{{ \App\Http\Controllers\Admin\EntriesController::updated($ctx, $entry->brewUpdated) }}</td>
                            <td>@if ((int) $entry->brewPaid === 1)<span class="text-success">&#10003;</span>@endif</td>
                            <td>@if ((int) $entry->brewReceived === 1)<span class="text-success">&#10003;</span>@endif</td>
                            <td>{{ $entry->brewAdminNotes }}</td>
                            <td>{{ $entry->brewStaffNotes }}</td>
                            <td>{{ $entry->brewBoxNum }}</td>
                            <td class="print:hidden">
                                <a href="{{ route('backoffice.entries.edit', ['id' => $entry->id]) }}">Edit</a>
                                <form method="post" action="{{ route('backoffice.entries.destroy', ['id' => $entry->id]) }}" class="inline"
                                      onsubmit="return confirm('Delete this entry? Its scores are removed as well. This cannot be undone.');">
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
