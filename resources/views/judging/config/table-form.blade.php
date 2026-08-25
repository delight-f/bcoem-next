@php($isEdit = $table !== null)
@php($selectedStyles = $isEdit ? array_filter(explode(',', (string) $table->tableStyles), fn ($v) => $v !== '') : old('tableStyles', []))
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Judging Tables: {{ $isEdit ? 'Edit' : 'Add' }} a Table</h1>

        @if ($errors->any())
            <div class="alert alert-error">
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
                <label for="tableName" class="col-sm-3 col-form-label">Table Name</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="tableName" name="tableName" type="text" required value="{{ old('tableName', $table->tableName ?? '') }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableNumber" class="col-sm-3 col-form-label">Table Number</label>
                <div class="col-sm-6">
                    <select class="select select-bordered" id="tableNumber" name="tableNumber" required>
                        @foreach (range(1, 100) as $n)
                            <option value="{{ $n }}"
                                @if ((string) old('tableNumber', $table->tableNumber ?? ($nextTableNumber ?? '')) === (string) $n) selected @endif
                                @if (in_array($n, $usedNumbers, true)) disabled @endif>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableLocation" class="col-sm-3 col-form-label">Judging Session</label>
                <div class="col-sm-6">
                    <select class="select select-bordered" id="tableLocation" name="tableLocation" required>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((string) old('tableLocation', $table->tableLocation ?? '') === (string) $loc->id)>{{ $loc->judgingLocName }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="tableEntryLimit" class="col-sm-3 col-form-label">Entry Limit</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="tableEntryLimit" name="tableEntryLimit" type="number" min="1" value="{{ old('tableEntryLimit', $table->tableEntryLimit ?? '') }}">
                    <div class="form-text">Overall entry limit for this table/medal group. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-sm-3 col-form-label">Styles at This Table</span>
                <div class="col-sm-6">
                    @forelse ($styles as $style)
                        <div class="form-check">
                            <input class="checkbox" type="checkbox" name="tableStyles[]" id="style_{{ $style->id }}" value="{{ $style->id }}" @checked(in_array((string) $style->id, (array) $selectedStyles, true) || in_array((int) $style->id, (array) $selectedStyles, true))>
                            <label class="form-check-label" for="style_{{ $style->id }}">{{ ltrim((string) $style->brewStyleGroup, '0') }}{{ $style->brewStyleNum }}: {{ $style->brewStyle }}</label>
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
