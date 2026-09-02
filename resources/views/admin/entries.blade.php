<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        // Legacy entries.admin.php:38-45 header.
        $statusLabel = $view === 'paid' ? 'Paid' : ($view === 'unpaid' ? 'Unpaid' : 'All');
        $header = $ctx->contestStr('contestName').': '.$statusLabel.' Entries';
        $proEdition = (int) $ctx->prefsStr('prefsProEdition') === 1;
        $obfuscate = (int) auth()->user()?->userAdminObfuscate === 0;
        $limit = (int) $ctx->prefsStr('prefsRecordLimit');
        // Legacy mark-all msg codes (headers.inc.php 642-656).
        $msgTexts = [
            20 => 'All entries have been marked as paid.',
            21 => 'All entries have been marked as received.',
            22 => 'All unconfirmed entries are now marked as confirmed.',
            34 => 'All entries have been un-marked as paid.',
            35 => 'All entries have been un-marked as received.',
        ];
        $scoped = $filter !== 'default' || $bid !== 'default' || $view !== 'default';
    @endphp

    <p class="lead">{{ $header }}</p>

    @if (request('msg') === 'updated')
        <div class="alert alert-success">Entry updated.</div>
    @elseif (request('msg') === 'deleted')
        <div class="alert alert-success">Entry deleted.</div>
    @elseif (in_array((int) request('msg'), array_keys($msgTexts), true))
        <div class="alert alert-success">{{ $msgTexts[(int) request('msg')] }}</div>
    @endif

    <form method="post" action="{{ route('backoffice.entries.update_form') }}">
        @csrf
        @method('PUT')

        <div class="bcoem-admin-element hidden-print row">
            <div class="col-md-12">
                @if ($scoped)
                    <div class="btn-group" role="group" aria-label="allEntriesNav">
                        <a class="btn btn-secondary" href="{{ url('/backoffice/entries') }}"><span class="fa fa-arrow-circle-left"></span>
                                                        @if ($filter !== 'default')
                                All Styles
                            @endif
                            @if ($bid !== 'default')
                                All Entries
                            @endif
                            @if ($view !== 'default')
                                All Entries
                            @endif
                        </a>
                    </div>
                @endif

                @if ($entries->isNotEmpty())
                    {{-- View Entries Dropdown --}}
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="fa fa-eye"></span> View...
                        </button>
                        <ul class="dropdown-menu">
                            @if ($view !== 'default')
                                <li><a class="dropdown-item" href="{{ url('/backoffice/entries') }}">All Entries</a></li>
                            @endif
                            @if ($view !== 'paid')
                                <li><a class="dropdown-item" href="{{ url('/backoffice/entries?view=paid') }}">Paid Entries</a></li>
                            @endif
                            @if ($view !== 'unpaid' && $entryStatus['paidCount'] < $entryStatus['totalCount'])
                                <li><a class="dropdown-item" href="{{ url('/backoffice/entries?view=unpaid') }}">Unpaid Entries</a></li>
                            @endif
                        </ul>
                    </div>
                @endif

                {{-- Add an Entry For... participant jump (legacy participant_choose). --}}
                <div class="btn-group" role="group" aria-label="chooseParticipants">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-plus-circle"></span> Add an Entry For...
                    </button>
                    <ul class="dropdown-menu" role="listbox" aria-label="Choose participant">
                        @foreach ($participants as $p)
                            <li>
                                <a class="dropdown-item" href="{{ '/backoffice/entries?bid='.$p->uid }}">{{ $p->brewerLastName }}, {{ $p->brewerFirstName }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($entries->isNotEmpty())
                    <div class="btn-group d-none d-lg-block" role="group" aria-label="printCurrent">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="fa fa-print"></span> Print Current View...
                            </button>
                            <ul class="dropdown-menu">
                                {{-- TODO: legacy output — includes/output.inc.php?section=admin&go=entries&action=print&psort=* --}}
                                <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?psort=entry_number') }}">By Entry Number</a></li>
                                @if ($obfuscate)
                                    <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?psort=judging_number') }}">By Judging Number</a></li>
                                @endif
                                <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?psort=category') }}">By Style</a></li>
                                <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?psort=brewer_name') }}">{{ $proEdition ? 'By Organization Name' : 'By Brewer Last Name' }}</a></li>
                                <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?psort=entry_name') }}">By Entry Name</a></li>
                            </ul>
                        </div>
                        @if ($entryStatus['totalCount'] > $limit && $filter === 'default')
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <span class="fa fa-print"></span> Print All...
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?view=all&psort=entry_number') }}">By Entry Number</a></li>
                                    @if ($obfuscate)
                                        <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?view=all&psort=judging_number') }}">By Judging Number</a></li>
                                    @endif
                                    <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?view=all&psort=category') }}">By Style</a></li>
                                    <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?view=all&psort=brewer_name') }}">By Brewer Last Name</a></li>
                                    <li><a class="dropdown-item hide-loader" href="{{ url('/admin/output/entries_print?view=all&psort=entry_name') }}">By Entry Name</a></li>
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Admin Actions dropdown — legacy process.inc.php mark-all actions. --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-check-circle"></span> Admin Actions
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ([
                            'paid' => ['Mark All as Paid', 'Are you sure? This will mark ALL entries as paid and could be a large pain to undo.', 20],
                            'unpaid' => ['Un-Mark All as Paid', 'Are you sure? This will mark ALL entries as unpaid and could be a large pain to undo.', 34],
                            'received' => ['Mark All as Received', 'Are you sure? This will mark ALL entries as received and could be a large pain to undo.', 21],
                            'not-received' => ['Un-Mark All as Received', 'Are you sure? This will mark ALL entries as NOT received and could be a large pain to undo.', 35],
                            'confirmed' => ['Confirm All Entries', 'Are you sure? This will mark ALL entries as confirmed and could be a large pain to undo.', 22],
                        ] as $action => [$label, $confirm, $msg])
                            <li>
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

                @foreach ([['allEmailModal', 'all', 'All Participants with Entries Email Addresses', 'to contact all participants with entries'], ['paidEmailModal', 'paid', 'All Participants with Paid Entries Email Addresses', 'to contact participants with <strong>PAID</strong> entries'], ['unpaidEmailModal', 'unpaid', 'All Participants with Unpaid Entries Email Addresses', 'to contact participants with <strong>UNPAID</strong> entries']] as [$modalId, $key, $title, $purpose])
                    @if ($emailLists[$key] !== '')
                        <div class="btn-group d-none d-lg-block" role="group">
                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">{{ $title }}</button>
                        </div>
                    @endif
                @endforeach

                @if ($entries->isNotEmpty())
                    <div class="btn-group float-end d-none d-md-block" role="group">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#entryStatusModal">
                                {{ $statusLabel }} Entry Status
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @foreach ([['allEmailModal', 'all', 'All Participants with Entries Email Addresses', 'to contact all participants with entries'], ['paidEmailModal', 'paid', 'All Participants with Paid Entries Email Addresses', 'to contact participants with <strong>PAID</strong> entries'], ['unpaidEmailModal', 'unpaid', 'All Participants with Unpaid Entries Email Addresses', 'to contact participants with <strong>UNPAID</strong> entries']] as [$modalId, $key, $title, $purpose])
            @if ($emailLists[$key] !== '')
                <div class="modal fade" id="{{ $modalId }}" tabindex="-1" role="dialog" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bcoem-admin-modal">
                                <h4 class="modal-title" id="{{ $modalId }}Label">{{ $title }}</h4>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Copy and paste the list below into your favorite email program {!! $purpose !!}.</p>
                                <textarea class="form-control" rows="8">{{ $emailLists[$key] }}</textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Entry Status modal (entries.admin.php:936). --}}
        <div class="modal fade" id="entryStatusModal" tabindex="-1" role="dialog" aria-labelledby="entryStatusModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header bcoem-admin-modal">
                        <h4 class="modal-title" id="entryStatusModalLabel">{{ $statusLabel }} Entry Status</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <strong class="text-info">Confirmed Entries</strong><span>{{ $entryStatus['confirmed'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <strong class="text-info">Unconfirmed Entries</strong><span>{{ $entryStatus['unconfirmed'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <strong class="text-info">Received Entries</strong><span>{{ $entryStatus['received'] }}</span>
                        </div>
                        @if (isset($entryStatus['paidConfirmed']))
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong class="text-info">Paid Confirmed Entries</strong><span>{{ $entryStatus['paidConfirmed'] }}</span>
                            </div>
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong class="text-info">Unpaid Confirmed Entries</strong><span>{{ $entryStatus['unpaidConfirmed'] }}</span>
                            </div>
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong class="text-info">Total Fees</strong><span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFees'], 2) }}</span>
                            </div>
                        @endif
                        @if (isset($entryStatus['totalFeesPaid']))
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong class="text-info">Total Fees Paid{{ $scoped ? ' in this Category' : '' }}</strong><span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFeesPaid'], 2) }}</span>
                            </div>
                        @endif
                        @if (isset($entryStatus['totalFeesUnpaid']))
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong class="text-info">Total Fees Unpaid{{ $scoped ? ' in this Category' : '' }}</strong><span>{{ $ctx->currencySymbol() }}{{ number_format($entryStatus['totalFeesUnpaid'], 2) }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        @if ($entries->isEmpty())
            <p>No entries have been added to the database yet.</p>
        @else
            <table class="table table-responsive table-bordered" id="sortable" data-dt data-dt-page="25">
                <thead>
                    <tr>
                        <th nowrap>Entry</th>
                        <th nowrap>Judging
                            @if ($obfuscate)<a href="#" tabindex="0" role="button" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top" data-bs-container="body" title="Judging Numbers" data-bs-content="Judging numbers are random six-digit numbers that are automatically assigned by the system. You can override each judging number when scanning in barcodes, QR Codes, or by entering it in the field provided. Judging numbers must be six characters and cannot include the ^ character. The ^ character will be converted to a dash (-) upon submit. Use leading zeroes (e.g., 000123 or 01-001, etc.). Alpha characters will be converted to lower case for consistency and system use."><span class="fa fa-question-circle"></span></a>@endif
                        </th>
                        <th class="d-none d-xl-block">Name</th>
                        <th>Style</th>
                        <th class="d-none d-lg-block">{{ $proEdition ? 'Organization' : 'Brewer' }}</th>
                        @if (! $proEdition)
                            <th class="d-none d-xl-block hidden-print">Club</th>
                        @endif
                        <th class="d-none d-xl-block hidden-print">Updated</th>
                        <th class="d-none d-lg-block" width="3%">P<span class="d-none d-xl-block">aid?</span></th>
                        <th class="d-none d-lg-block" width="3%">R<span class="d-none d-xl-block">ec'd?</span></th>
                        <th class="d-none d-xl-block">Admin Notes
                            <a href="#" tabindex="0" role="button" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top" data-bs-container="body" data-bs-html="true" title="Admin Notes" data-bs-content="Catch-all for any information Admins may need for individual entries such as &quot;received damaged,&quot; &quot;maybe mis-categorized,&quot; etc. 255 character limit."><span class="d-none d-xl-block hidden-print fa fa-question-circle"></span></a>
                        </th>
                        <th class="d-none d-xl-block">Staff Notes
                            <a href="#" tabindex="0" role="button" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-placement="top" data-bs-container="body" data-bs-html="true" title="Staff Notes" data-bs-content="Catch-all for any information staff may need to know about individual entries such as &quot;single 750ml bottle,&quot; &quot;missing MBOS bottle,&quot; etc. Notes entered here are printed on pullsheets. 255 character limit."><span class="d-none d-xl-block hidden-print fa fa-question-circle"></span></a>
                        </th>
                        <th class="d-none d-lg-block">Loc<span class="d-none d-xl-block">/Box</span></th>
                        <th class="d-none d-lg-block hidden-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $entryNumber = \App\Http\Controllers\Admin\EntriesController::entryNumber($entry->id);
                            $entryId = $entry->id;
                            $judgingNumber = $entry->brewJudgingNumber !== null && $entry->brewJudgingNumber !== ''
                                ? str_pad((string) $entry->brewJudgingNumber, 6, '0', STR_PAD_LEFT) : '';
                            $allergens = ! empty($entry->brewPossAllergens);
                            $entryName = $entry->brewName;
                            // Required-info / optional-info / allergen summary lines.
                            $info = [];
                            if (! empty($entry->brewInfo)) {
                                $info[] = '<li><strong>Required Info:</strong> '.str_replace('^', ' | ', e($entry->brewInfo)).'</li>';
                            }
                            if (! empty($entry->brewInfoOptional)) {
                                $info[] = '<li><strong>Optional Info:</strong> '.e($entry->brewInfoOptional).'</li>';
                            }
                            if (! empty($entry->brewMead1)) {
                                $info[] = '<li><strong>Carbonation:</strong> '.e($entry->brewMead1).'</li>';
                            }
                            if (! empty($entry->brewMead2)) {
                                $info[] = '<li><strong>Sweetness:</strong> '.e($entry->brewMead2).'</li>';
                            }
                            if (! empty($entry->brewMead3)) {
                                $info[] = '<li><strong>Strength:</strong> '.e($entry->brewMead3).'</li>';
                            }
                            if (! empty($entry->brewABV)) {
                                $info[] = '<li><strong>ABV:</strong> '.e($entry->brewABV).'%</li>';
                            }
                            $styleLabel = ltrim((string) $entry->brewCategorySort, '0').$entry->brewSubCategory;
                            $name = $entry->brewBrewerFirstName.' '.$entry->brewBrewerLastName;
                        @endphp
                        <tr class="{{ $allergens ? 'bg-warning' : '' }}">
                            <input type="hidden" name="ids[]" value="{{ $entryId }}">
                            <td nowrap>{{ $entryNumber }}</td>
                            <td nowrap>
                                <input class="form-control form-control-sm hidden-print" name="brewJudgingNumber{{ $entry->id }}" type="text" pattern=".{6,}" title="Judging numbers must be six characters and cannot include the ^ character. The ^ character will be converted to a dash (-) upon submit. Use leading zeroes (e.g., 000123 or 01-001, etc.). Alpha characters will be converted to lower case for consistency and system use." size="8" maxlength="6" value="{{ $judgingNumber }}">
                            </td>
                            <td class="d-none d-xl-block">
                                {{ $entryName }}
                                @if ($allergens)
                                    <p><strong class="text-danger small">Possible Allergens: {{ $entry->brewPossAllergens }}</strong></p>
                                @endif
                                @if ($info !== [])
                                    <ul class="small">
                                        {!! implode('', $info) !!}
                                    </ul>
                                @endif
                                @if (! empty($entry->brewCoBrewer))
                                    <p class="small"><strong>Co-Brewer:</strong> {{ $entry->brewCoBrewer }}</p>
                                @endif
                                @if ((int) $entry->brewConfirmed !== 1)
                                    <p><span class="badge text-bg-danger">UNCONFIRMED</span></p>
                                @endif
                            </td>
                            <td nowrap>
                                <a href="{{ url('/backoffice/entries?filter='.$entry->brewCategorySort) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="See only the category {{ ltrim($entry->brewCategorySort, '0') }} entries">{{ $styleLabel }}: {{ $entry->brewStyle }}</a>
                            </td>
                            <td class="d-none d-lg-block">{{ $name }}</td>
                            @if (! $proEdition)
                                <td class="d-none d-xl-block hidden-print">{{ $entry->brewerClubs }}</td>
                            @endif
                            <td class="d-none d-xl-block hidden-print">
                                {{ \App\Http\Controllers\Admin\EntriesController::updated($ctx, $entry->brewUpdated) }}
                            </td>
                            <td class="d-none d-lg-block">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" name="brewPaid{{ $entry->id }}" @if ((int) $entry->brewPaid === 1) checked @endif>
                                </div>
                            </td>
                            <td class="d-none d-lg-block">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" name="brewReceived{{ $entry->id }}" @if ((int) $entry->brewReceived === 1) checked @endif>
                                </div>
                            </td>
                            <td class="d-none d-xl-block">
                                <textarea class="form-control form-control-sm" name="brewAdminNotes{{ $entry->id }}" rows="2" maxlength="255">{{ $entry->brewAdminNotes }}</textarea>
                            </td>
                            <td class="d-none d-xl-block">
                                <textarea class="form-control form-control-sm" name="brewStaffNotes{{ $entry->id }}" rows="2" maxlength="255">{{ $entry->brewStaffNotes }}</textarea>
                            </td>
                            <td class="d-none d-lg-block">
                                <input class="form-control form-control-sm" name="brewBoxNum{{ $entry->id }}" type="text" size="5" maxlength="10" value="{{ $entry->brewBoxNum }}">
                            </td>
                            <td class="d-none d-lg-block hidden-print" nowrap>
                                <a href="{{ route('backoffice.entries.edit', ['id' => $entry->id]) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit &ldquo;{{ $entryName }}&rdquo;"><span class="fa fa-lg fa-pencil"></span></a>
                                <form method="post" action="{{ route('backoffice.entries.destroy', ['id' => $entry->id]) }}" class="d-inline"
                                      onsubmit="return confirm('Are you sure you want to delete the entry called &ldquo;{{ $entryName }}?&rdquo; This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link" style="margin:0; padding:0;" title="Delete &ldquo;{{ $entryName }}&rdquo;"><span class="fa fa-lg fa-trash-o"></span></button>
                                </form>
                                <a class="hide-loader" href="{{ url('/admin/output/entry?bid='.($entry->uid ?? $entry->brewBrewerID).'&filter=admin&id='.$entry->id) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="Print the Entry Forms for &ldquo;{{ $entryName }}&rdquo;"><span class="fa fa-lg fa-print"></span></a>
                                <a class="hide-loader" href="mailto:{{ $entry->brewBrewerEmail ?? '' }}" data-bs-toggle="tooltip" data-bs-placement="top" title="Email the entry&rsquo;s owner, {{ $name }}, at {{ $entry->brewBrewerEmail ?? '' }}"><span class="fa fa-lg fa-envelope"></span></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </form>
</x-public-layout>
