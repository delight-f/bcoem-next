{{-- Legacy brewer_entries.pub.php table (pre-results branch): entry number,
     judging number, name, style, confirmed/paid/received badges, actions.
     Edit targets the P3.3b brew-edit route; delete POSTs to
     EntriesController::destroy with a JS confirmation like legacy's
     data-confirm link. --}}
@if ($rows === [])
    <p>{{ __('site.no_entries') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-bordered table-striped border-dark-subtle" id="sortable">
            <thead class="table-dark">
                <tr>
                    <th>{{ __('site.entry_number') }}</th>
                    <th>{{ __('site.judging_number') }}</th>
                    <th>Name</th>
                    <th>{{ __('site.style') }}</th>
                    <th>{{ __('site.confirmed') }}</th>
                    <th>{{ __('site.paid_label') }}</th>
                    <th>{{ __('site.received') }}</th>
                    <th class="d-print-none">{{ __('site.actions') }}</th>
                </tr>
            </thead>
            <tbody class="table-group-divider">
                @foreach ($rows as $r)
                    @php($e = $r['entry'])
                    <tr>
                        <td>{{ str_pad((string) $e->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ str_pad((string) $e->brewJudgingNumber, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>
                            {{ $e->brewName }}
                            @if (! empty($e->brewCoBrewer))
                                <div><em class="small">{{ __('site.cobrewer') }}: {{ $e->brewCoBrewer }}</em></div>
                            @endif
                        </td>
                        <td>{{ $e->brewCategorySort }}-{{ $e->brewSubCategory }}: {{ $e->brewStyle }}</td>
                        @foreach (['confirmed' => ['brewConfirmed', 'site.confirmed'], 'paid' => ['brewPaid', 'site.paid_label'], 'received' => ['brewReceived', 'site.received']] as $flag => [$col, $labelKey])
                            @php($on = (int) $e->{$col} === 1)
                            <td>
                                <span class="badge {{ $on ? 'text-bg-success' : 'text-bg-danger' }}"
                                    data-flag="{{ $flag }}" data-state="{{ $on ? 'yes' : 'no' }}">{{ __($labelKey) }}</span>
                            </td>
                        @endforeach
                        <td class="d-print-none">
                            @if ($r['canEdit'])
                                {{-- P3.3b brew edit route --}}
                                <a href="{{ url('/brew/'.$e->id.'/edit') }}" title="Edit"><i class="fa fa-fw fa-lg fa-pencil"></i></a>
                            @else
                                <span title="{{ __('site.edit_locked') }}"><i class="fa fa-fw fa-lg fa-pencil text-muted"></i></span>
                            @endif
                            @if ($r['canDelete'])
                                <form method="post" action="{{ route('entries.destroy', ['id' => $e->id]) }}" class="d-inline"
                                    onsubmit="return confirm('{{ __('site.delete_confirm') }}');">
                                    @csrf
                                    <button type="submit" class="btn btn-link p-0 align-baseline" title="{{ __('site.delete') }}">
                                        <i class="fa fa-fw fa-lg fa-trash-can"></i>
                                    </button>
                                </form>
                            @else
                                <span title="{{ __('site.delete_locked') }}"><i class="fa fa-fw fa-lg fa-trash-can text-muted"></i></span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
