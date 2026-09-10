@php($isEdit = $table !== null)
@php($selectedStyles = $isEdit ? array_filter(explode(',', (string) $table->tableStyles), fn ($v) => $v !== '') : old('tableStyles', []))
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Judging Tables: {{ $isEdit ? 'Edit' : 'Add' }} a Table</h1>

        {{-- Legacy control set (judging_tables.admin.php:778-792): View...
            dropdown — assignment views by name/table plus the
            not-assigned modals (edit view carries unassignedJudges/
            unassignedStewards; add falls back to the empty roster). --}}
        <div class="bcoem-admin-element d-print-none mb-3">
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
                <div class="col-md-9">
                    @if ($stylesByGroup->isEmpty())
                        <p class="form-text mb-0">No styles are available in the active style set.</p>
                    @else
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <label for="style-filter" class="visually-hidden">Filter styles</label>
                            <input id="style-filter" class="form-control form-control-sm" style="max-width:20rem;"
                                   type="search" placeholder="Filter by number or name&hellip;" autocomplete="off">
                            <button type="button" id="styles-select-shown" class="btn btn-sm btn-outline-primary">Select all shown</button>
                            <button type="button" id="styles-clear-all" class="btn btn-sm btn-outline-secondary">Clear all</button>
                            <span class="form-text mb-0" id="styles-count" aria-live="polite"></span>
                        </div>

                        <div id="style-groups">
                            @foreach ($stylesByGroup as $groupName => $groupStyles)
                                <fieldset class="border rounded p-2 mb-2 bcoem-style-group">
                                    <legend class="float-none w-auto px-2 fs-6 mb-1">
                                        {{ $groupName }}
                                        <span class="badge text-bg-secondary">{{ $groupStyles->count() }}</span>
                                    </legend>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary bcoem-group-select">Select group</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary bcoem-group-clear">Clear group</button>
                                    </div>
                                    <div class="row">
                                        @foreach ($groupStyles as $style)
                                            @php($at = $styleAssignments[$style->id] ?? null)
                                            @php($label = ltrim((string) $style->brewStyleGroup, '0').$style->brewStyleNum.': '.$style->brewStyle)
                                            <div class="col-12 col-xl-6 bcoem-style-item" data-style-search="{{ strtolower($label) }}">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tableStyles[]"
                                                           id="style_{{ $style->id }}" value="{{ $style->id }}"
                                                           @checked(in_array((string) $style->id, (array) $selectedStyles, true) || in_array((int) $style->id, (array) $selectedStyles, true))>
                                                    <label class="form-check-label" for="style_{{ $style->id }}">
                                                        {{ $label }}
                                                        @if ($at)
                                                            <em class="text-body-secondary d-block small">Already assigned to Table {{ $at->tableNumber }}: <a href="{{ route('admin.judging.tables.edit', ['id' => $at->id]) }}">{{ $at->tableName }}</a>.</em>
                                                        @endif
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        <p id="styles-no-match" class="form-text d-none">No styles match that filter.</p>

                        <script>
                            // Delegated so the group controls keep working as
                            // filters hide and show items.
                            (function () {
                                'use strict';

                                var filter = document.getElementById('style-filter');
                                var groups = document.getElementById('style-groups');
                                var countEl = document.getElementById('styles-count');
                                var noMatch = document.getElementById('styles-no-match');

                                function boxes() {
                                    return groups.querySelectorAll('input[type="checkbox"]');
                                }

                                function items() {
                                    return groups.querySelectorAll('.bcoem-style-item');
                                }

                                function refreshCount() {
                                    var all = boxes();
                                    var checked = 0;
                                    for (var i = 0; i < all.length; i++) {
                                        if (all[i].checked) { checked++; }
                                    }
                                    countEl.textContent = checked + ' of ' + all.length + ' selected';
                                }

                                function applyFilter() {
                                    var term = (filter.value || '').trim().toLowerCase();
                                    var shown = 0;
                                    var list = items();
                                    for (var i = 0; i < list.length; i++) {
                                        var hit = term === '' || list[i].getAttribute('data-style-search').indexOf(term) !== -1;
                                        list[i].classList.toggle('d-none', !hit);
                                        if (hit) { shown++; }
                                    }
                                    // Hide a group whose every style is filtered out.
                                    var fieldsets = groups.querySelectorAll('.bcoem-style-group');
                                    for (var g = 0; g < fieldsets.length; g++) {
                                        var visible = fieldsets[g].querySelectorAll('.bcoem-style-item:not(.d-none)').length;
                                        fieldsets[g].classList.toggle('d-none', visible === 0);
                                    }
                                    noMatch.classList.toggle('d-none', shown !== 0 || term === '');
                                }

                                filter.addEventListener('input', applyFilter);

                                groups.addEventListener('click', function (event) {
                                    var btn = event.target.closest('.bcoem-group-select, .bcoem-group-clear');
                                    if (!btn) { return; }
                                    var fieldset = btn.closest('.bcoem-style-group');
                                    var on = btn.classList.contains('bcoem-group-select');
                                    var list = fieldset.querySelectorAll('input[type="checkbox"]');
                                    for (var i = 0; i < list.length; i++) {
                                        list[i].checked = on;
                                    }
                                    refreshCount();
                                });

                                document.getElementById('styles-select-shown').addEventListener('click', function () {
                                    var list = groups.querySelectorAll('.bcoem-style-item:not(.d-none) input[type="checkbox"]');
                                    for (var i = 0; i < list.length; i++) {
                                        list[i].checked = true;
                                    }
                                    refreshCount();
                                });

                                document.getElementById('styles-clear-all').addEventListener('click', function () {
                                    var list = boxes();
                                    for (var i = 0; i < list.length; i++) {
                                        list[i].checked = false;
                                    }
                                    refreshCount();
                                });

                                groups.addEventListener('change', function (event) {
                                    if (event.target.type === 'checkbox') { refreshCount(); }
                                });

                                refreshCount();
                            })();
                        </script>
                    @endif
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Edit' : 'Add' }} Table</button>
            <a class="btn btn-secondary" href="{{ route('admin.judging.tables.index') }}">Back</a>
        </form>
    </section>
</x-public-layout>
