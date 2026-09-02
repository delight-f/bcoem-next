@php($isEdit = $table !== null)
@php($selectedStyles = $isEdit ? array_filter(explode(',', (string) $table->tableStyles), fn ($v) => $v !== '') : old('tableStyles', []))
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Judging Tables: {{ $isEdit ? 'Edit' : 'Add' }} a Table</h1>

        {{-- Legacy control set (judging_tables.admin.php:778-792): View...
            dropdown — assignment views by name/table plus the
            not-assigned modals (edit view carries unassignedJudges/
            unassignedStewards; add falls back to the empty roster). --}}
        <div class="bcoem-admin-element print:hidden mb-3">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-eye"></span> View...
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a data-fancybox data-type="iframe" class="modal-window-link hide-loader" href="{{ url('/admin/output/assignments') }}?filter=judges&tb=view&view=name" title="View Assignments by Name">Judge Assignments By Last Name</a></li>
                    <li class="small"><a data-fancybox data-type="iframe" class="modal-window-link hide-loader" href="{{ url('/admin/output/assignments') }}?filter=judges&tb=view&view=table" title="View Assignments by Table">Judge Assignments By Table</a></li>
                    <li class="small"><a data-fancybox data-type="iframe" class="modal-window-link hide-loader" href="{{ url('/admin/output/assignments') }}?filter=stewards&tb=view&view=name" title="View Assignments by Name">Steward Assignments By Last Name</a></li>
                    <li class="small"><a data-fancybox data-type="iframe" class="modal-window-link hide-loader" href="{{ url('/admin/output/assignments') }}?filter=stewards&tb=view&view=table" title="View Assignments by Table">Steward Assignments By Table</a></li>
                    <li class="small"><a href="#" data-bs-toggle="modal" data-bs-target="#availJudgeModal">Judges Not Assigned to a Table</a></li>
                    <li class="small"><a href="#" data-bs-toggle="modal" data-bs-target="#availStewardModal">Stewards Not Assigned to a Table</a></li>
                </ul>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ $isEdit ? route('admin.judging.tables.update', ['id' => $table->id]) : route('admin.judging.tables.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="mb-4 row">
                <label for="tableName" class="col-md-3 col-form-label">Table Name</label>
                <div class="col-md-6">
                    <input class="form-control" id="tableName" name="tableName" type="text" required value="{{ old('tableName', $table->tableName ?? '') }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableNumber" class="col-md-3 col-form-label">Table Number</label>
                <div class="col-md-6">
                    <select class="form-select" id="tableNumber" name="tableNumber" required>
                        @foreach (range(1, 100) as $n)
                            <option value="{{ $n }}"
                                @if ((string) old('tableNumber', $table->tableNumber ?? ($nextTableNumber ?? '')) === (string) $n) selected @endif
                                @if (in_array($n, $usedNumbers, true)) disabled @endif>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableLocation" class="col-md-3 col-form-label">Judging Session</label>
                <div class="col-md-6">
                    <select class="form-select" id="tableLocation" name="tableLocation" required>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((string) old('tableLocation', $table->tableLocation ?? '') === (string) $loc->id)>{{ $loc->judgingLocName }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableEntryLimit" class="col-md-3 col-form-label">Entry Limit</label>
                <div class="col-md-6">
                    <input class="form-control" id="tableEntryLimit" name="tableEntryLimit" type="number" min="1" value="{{ old('tableEntryLimit', $table->tableEntryLimit ?? '') }}">
                    <div class="form-text">Overall entry limit for this table/medal group. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-md-3 col-form-label">Styles at This Table</span>
                <div class="col-md-6">
                    @forelse ($styles as $style)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tableStyles[]" id="style_{{ $style->id }}" value="{{ $style->id }}" @checked(in_array((string) $style->id, (array) $selectedStyles, true) || in_array((int) $style->id, (array) $selectedStyles, true))>
                            <label class="form-check-label" for="style_{{ $style->id }}">{{ ltrim((string) $style->brewStyleGroup, '0') }}{{ $style->brewStyleNum }}: {{ $style->brewStyle }}
                                @if (! empty($styleAssignments[$style->id] ?? null))
                                    @php($at = $styleAssignments[$style->id])
                                    <br><em>Assigned to Table {{ $at->tableNumber }}: <a href="{{ route('admin.judging.tables.edit', ['id' => $at->id]) }}">{{ $at->tableName }}</a></em>.
                                @endif
                            </label>
                        </div>
                    @empty
                        <p class="form-text mb-0">No styles are available in the active style set.</p>
                    @endforelse
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Edit' : 'Add' }} Table</button>
            <a class="btn btn-secondary" href="{{ route('admin.judging.tables.index') }}">Back</a>
        </form>
    </section>
</x-public-layout>
