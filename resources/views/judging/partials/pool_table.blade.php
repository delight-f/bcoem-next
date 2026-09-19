@php
    // Group the (table, flight) choices under one <optgroup> per table.
    $choiceGroups = [];
    foreach ($tableChoices as $choice) {
        $choiceGroups[$choice['tableId']]['label'] ??= 'Table '.$choice['tableNumber'].' – '.$choice['tableName'];
        $choiceGroups[$choice['tableId']]['options'][] = $choice;
    }
@endphp

@if (empty($rows))
    <div class="error">No participants in this group.</div>
@else
    <table class="table table-responsive table-bordered {{ $filter !== 'bos' ? 'table-striped' : '' }}" data-dt data-dt-page="{{ (int) $ctx->prefsStr('prefsRecordPaging') ?: 25 }}" data-pool-table>
        <thead>
            <tr>
                <th style="width:1%" nowrap>
                    <input type="checkbox" data-pool-check-all aria-label="Check all">
                </th>
                <th>Name</th>
                <th class="hidden-xs hidden-sm">Assigned As</th>
                @if ($allocatesTables)
                    <th>Assigned To</th>
                @endif
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
                    @if ($allocatesTables)
                        <td>
                            @if (! $row['isAllocated'])
                                <select class="form-select form-select-sm pool-assign-select"
                                        data-uid="{{ $row['uid'] }}"
                                        data-role="{{ $filter }}"
                                        aria-label="Assign {{ $row['name'] }} to a table">
                                    <option value="">Assign to table&hellip;</option>
                                    @foreach ($choiceGroups as $group)
                                        <optgroup label="{{ $group['label'] }}">
                                            @foreach ($group['options'] as $choice)
                                                <option value="{{ $choice['tableId'] }}:{{ $choice['flight'] }}">
                                                    Flight {{ $choice['flight'] }} (Round {{ $choice['round'] }})
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <span class="pool-assign-status" data-pool-status="{{ $row['uid'] }}"></span>
                            @else
                                @foreach ($row['assignments'] as $assignment)
                                    <div class="d-flex align-items-center">
                                        <span>{!! $assignment['text'] !!}</span>
                                        <button type="button" class="btn btn-sm btn-link text-danger pool-remove"
                                                data-uid="{{ $row['uid'] }}"
                                                data-role="{{ $filter }}"
                                                data-table="{{ $assignment['table'] }}"
                                                aria-label="Remove {{ $row['name'] }} from Table {{ $assignment['tableNumber'] }}"
                                                title="Remove from table">&times;</button>
                                    </div>
                                @endforeach
                            @endif
                        </td>
                    @endif
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
