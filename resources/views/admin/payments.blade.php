<x-public-layout
    :ctx="$ctx"
    :show-hero="false"
>
    <section class="landing-page-section mt-6 mb-4">
        <h1>Mark entries paid</h1>

        @if (request('msg') === 'marked')
            <div class="alert alert-success">Payment recorded.</div>
        @elseif (request('msg') === 'already-paid')
            <div class="alert alert-warning">Selected entries were already paid — nothing to mark.</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($unpaid->isEmpty())
            <p>No unpaid confirmed entries.</p>
        @else
            {{-- Minimal admin surface (P3.5c): the full entries admin view is
                 P5.5 scope. Batch via checkboxes; one payments row per entrant. --}}
            <form method="post" action="{{ url('/admin/payments/mark') }}">
                @csrf
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th></th>
                            <th>#</th>
                            <th>Entry</th>
                            <th>Style</th>
                            <th>Brewer uid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unpaid as $entry)
                            <tr>
                                <td><input type="checkbox" name="entry_ids[]" value="{{ $entry->id }}" aria-label="mark entry {{ $entry->id }} paid"></td>
                                <td>{{ $entry->id }}</td>
                                <td>{{ $entry->brewName }}</td>
                                <td>{{ $entry->brewCategory }}{{ $entry->brewSubCategory }} {{ $entry->brewStyle }}</td>
                                <td>{{ $entry->brewBrewerID }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p>{{ count($unpaid) }} unpaid × {{ $fee }} per entry (total computed on save).</p>

                <div class="mb-4 row">
                    <label for="pay_method" class="col-md-4 col-form-label">Method</label>
                    <div class="col-md-9">
                        <select id="pay_method" name="pay_method" class="form-select">
                            @foreach ($payMethods as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="reference" class="col-md-4 col-form-label">Reference</label>
                    <div class="col-md-9">
                        <input class="form-control" id="reference" name="reference" type="text"
                               placeholder="check number / transfer id">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="note" class="col-md-4 col-form-label">Note</label>
                    <div class="col-md-9">
                        <input class="form-control" id="note" name="note" type="text">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Mark paid</button>
            </form>
        @endif
    </section>
</x-public-layout>
