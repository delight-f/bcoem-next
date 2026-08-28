<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Best of Show (BOS) Entries and Places</h1>

        {{-- Legacy control set: admin/judging_scores_bos.admin.php (dbTable=default, action=default). --}}
        <div class="bcoem-admin-element print:hidden mb-3">
            <div class="btn-group" role="group">
                <a class="btn btn-default" href="{{ route('admin.judging.scores.index') }}"><span class="fa fa-arrow-circle-left"></span> All Scores</a>
            </div>
            <div class="btn-group" role="group">
                <a class="btn btn-default" href="{{ route('admin.judging.tables.index') }}"><span class="fa fa-arrow-circle-left"></span> All Tables</a>
            </div>

            @if (count($types) > 0)
                {{-- Position 2: Enter/Edit Dropdown Button Group. --}}
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-default dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                        <span class="fa fa-plus-circle"></span> Add or Update...
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($types as $type)
                            <li class="small"><a href="{{ route('admin.judging.bos.edit', ['styleType' => $type->id]) }}">BOS Places for {{ $type->styleTypeName }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Position 4: Print Button Dropdown Group. Legacy per-style-type
                items open output.inc.php?section=pullsheets&go=judging_scores_bos&id=<styleType>
                (judging_scores_bos.admin.php:107); the port pullsheet output
                dispatches the same shape. The Cup Mats outputs exist. --}}
            <div class="btn-group hidden-xs hidden-sm print:hidden" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-print"></span> Print...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    @foreach ($types as $type)
                        <li class="small"><a data-fancybox data-type="iframe" class="modal-window-link hide-loader menuItem" href="{{ route('outputs.pullsheets', ['go' => 'judging_scores_bos', 'id' => $type->id]) }}" title="Print the {{ $type->styleTypeName }} BOS Pullsheet">BOS Pullsheet for {{ $type->styleTypeName }}</a></li>
                    @endforeach
                    <li class="small"><a href="{{ route('outputs.bos_mat') }}" title="Print BOS Cup Mats">BOS Cup Mats (Judging Numbers)</a></li>
                    <li class="small"><a href="{{ route('outputs.bos_mat', ['filter' => 'entry']) }}" title="Print BOS Cup Mats">BOS Cup Mats (Entry Numbers)</a></li>
                </ul>
            </div>
        </div>

        @foreach ($groups as $group)
            @php($rows = $group->rows)
            @php($type = $group->type)
            <h3>BOS Entries and Places for {{ $type->styleTypeName }}</h3>
            @if (count($rows) === 0)
                <p>No entries are eligible.</p>
            @else
                <table class="table table-zebra table-bordered">
                    <thead>
                        <tr>
                            <th>Entry</th>
                            <th>Judging</th>
                            <th>Table</th>
                            <th>Style</th>
                            <th>Table Score</th>
                            <th>Table Place</th>
                            <th>BOS Place</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ str_pad((string) $row->eid, 6, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $row->brewJudgingNumber ?? '' }}</td>
                                <td>{{ $row->tableNumber }}: {{ $row->tableName }}</td>
                                <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewName }}</td>
                                <td>{{ $row->scoreEntry }}</td>
                                {{-- BOS surface treats '5' and literal 'HM' as equivalent (scoring ledger). --}}
                                <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                                <td>{{ $row->bosPlace === null ? '' : \App\Support\Results\Place::label($row->bosPlace) }}</td>
                                <td><a href="{{ route('admin.judging.bos.edit', ['styleType' => $type->id]) }}">Enter Places</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </section>
</x-public-layout>
