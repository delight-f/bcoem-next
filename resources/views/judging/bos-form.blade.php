<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $type->styleTypeName }} — BOS Places</h1>

        <p><a href="{{ route('admin.judging.bos.index') }}">&larr; All BOS Entries and Places</a></p>

        {{-- Legacy judging_scores_bos.admin.php:98-112 — Print dropdown
             renders in list and enter modes: BOS pullsheets per BOS type
             + BOS cup mats. --}}
        <div class="btn-group d-none d-lg-block print:hidden" role="group">
            <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <span class="fa fa-print"></span> Print...
            </button>
            <ul class="dropdown-menu">
                @foreach ($types as $t)
                    <li><a data-fancybox data-type="iframe" class="dropdown-item modal-window-link hide-loader menuItem" href="{{ route('outputs.pullsheets', ['go' => 'judging_scores_bos', 'id' => $t->id]) }}" title="Print the {{ $t->styleTypeName }} BOS Pullsheet">BOS Pullsheet for {{ $t->styleTypeName }}</a></li>
                @endforeach
                <li><a data-fancybox data-type="iframe" class="dropdown-item modal-window-link hide-loader" href="{{ route('outputs.bos_mat') }}" title="Print BOS Cup Mats">BOS Cup Mats (Judging Numbers)</a></li>
                <li><a data-fancybox data-type="iframe" class="dropdown-item modal-window-link hide-loader" href="{{ route('outputs.bos_mat', ['filter' => 'entry']) }}" title="Print BOS Cup Mats">BOS Cup Mats (Entry Numbers)</a></li>
            </ul>
        </div>

        @if (count($rows) === 0)
            <p>No entries are eligible.</p>
        @else
            <form method="post" action="{{ route('admin.judging.bos.update', ['styleType' => $type->id]) }}">
                @csrf
                @method('PUT')

                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Entry</th>
                            <th>Style</th>
                            <th>Score</th>
                            <th>Place</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                {{-- Hidden id present ⇔ a BOS row already exists for the entry
                                     (legacy scorePrevious Y/N + id{eid}); clearing the place deletes it. --}}
                                <input type="hidden" name="score_id[]" value="{{ $row->eid }}">
                                <input type="hidden" name="eid{{ $row->eid }}" value="{{ $row->eid }}">
                                <input type="hidden" name="bid{{ $row->eid }}" value="{{ $row->bid }}">
                                <input type="hidden" name="scoreEntry{{ $row->eid }}" value="{{ $row->scoreEntry }}">
                                <input type="hidden" name="scoreType{{ $row->eid }}" value="{{ $row->scoreType }}">
                                @if ($row->bosId !== null)
                                    <input type="hidden" name="id{{ $row->eid }}" value="{{ $row->bosId }}">
                                @endif
                                <td>{{ str_pad((string) $row->eid, 6, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewName }}</td>
                                <td>{{ $row->scoreEntry }}</td>
                                <td>
                                    <select class="form-select" name="scorePlace{{ $row->eid }}">
                                        <option value=""></option>
                                        @for ($i = 1; $i <= $maxBos; $i++)
                                            <option value="{{ $i }}"
                                                @selected((string) old('scorePlace'.$row->eid, $row->bosPlace ?? '') === (string) $i)>{{ \App\Support\Results\Place::label((string) $i) }}</option>
                                        @endfor
                                        {{-- '5' is written, never literal 'HM', so public winners pages see it. --}}
                                        <option value="5"
                                            @selected((string) old('scorePlace'.$row->eid, $row->bosPlace ?? '') === '5')>Hon. Men.</option>
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <button type="submit" class="btn btn-primary">Update BOS Places</button>
            </form>
        @endif
    </section>
</x-public-layout>
