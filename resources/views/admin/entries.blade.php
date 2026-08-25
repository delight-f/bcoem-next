<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}:
            @if ($view === 'paid') Paid @elseif ($view === 'unpaid') Unpaid @else All @endif
            Entries</h1>

        @if (request('msg') === 'updated')
            <div class="alert alert-success">Entry updated.</div>
        @elseif (request('msg') === 'deleted')
            <div class="alert alert-success">Entry deleted.</div>
        @endif

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
