@php $row = $editing ?? null; @endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    @php
        $meadCider = $styleTypes->firstWhere('styleTypeName', 'Mead/Cider');
        $combined = $meadCider !== null && $meadCider->styleTypeBOS === 'Y';
        $bosMethods = [1 => '1st place only', 2 => '1st and 2nd places', 3 => '1st, 2nd, and 3rd places'];
    @endphp

    <p class="lead">{{ $ctx->contestStr('contestName') }}{{ $row !== null ? ': Edit the '.e($row->styleTypeName).' Style Type' : ' Style Types' }}</p>

    @if ((int) request('msg') === 2)
        <div class="alert alert-success">Style types updated.</div>
    @elseif ((int) request('msg') === 9)
        <div class="alert alert-success">Style type saved.</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="bcoem-admin-element hidden-print">
        <div class="btn-group" role="group" aria-label="all-styles">
            <a class="btn btn-default" href="{{ url('/admin/styles') }}"><span class="fa fa-arrow-circle-left"></span> All Styles</a>
        </div>
        @if ($row !== null || request()->routeIs('admin.style_types.create'))
            <div class="btn-group" role="group" aria-label="all-styles">
                <a class="btn btn-default" href="{{ url('/admin/style-types') }}"><span class="fa fa-arrow-circle-left"></span> All Style Types</a>
            </div>
        @endif
        @if ($row === null && ! request()->routeIs('admin.style_types.create'))
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa fa-plus-circle"></span> Add...
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li class="small"><a href="{{ url('/admin/style-types/create') }}">Add a Custom Style Type</a></li>
                    <li class="small"><a href="{{ url('/admin/styles/create') }}">A Custom Style</a></li>
                </ul>
            </div>
            <div class="btn-group" role="group" aria-label="all-styles">
                @if ($combined)
                    <form method="post" action="{{ url('/admin/style-types/separate') }}" class="inline"
                          onsubmit="return confirm('Are you sure you want to separate mead and cider into two distinct style types? This will clear any Mead/Cider BOS scores/places already entered in the database.');">
                        @csrf
                        <button type="submit" class="btn btn-success"><span class="fa fa-expand"></span> Separate Mead and Cider?</button>
                    </form>
                @else
                    <form method="post" action="{{ url('/admin/style-types/combine') }}" class="inline"
                          onsubmit="return confirm('Are you sure you want to combine mead and cider into a single style type? This will also enable Best of Show (BOS) for the combined style type and clear any Mead or Cider BOS scores/places already in the database.');">
                        @csrf
                        <button type="submit" class="btn btn-success"><span class="fa fa-compress"></span> Combine Mead and Cider?</button>
                    </form>
                @endif
            </div>
        @endif
    </div>

    @if ($row === null && ! request()->routeIs('admin.style_types.create'))
        <table class="table table-responsive table-striped table-bordered" id="sortable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th nowrap>Entry Limit</th>
                    <th nowrap>BOS Enabled?</th>
                    <th nowrap>BOS Pull Method</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($styleTypes as $type)
                    {{-- Hide Cider/Mead when combined, Mead/Cider when separate (legacy display rule). --}}
                    @continue($combined && in_array($type->styleTypeName, ['Cider', 'Mead']))
                    @continue(! $combined && $type->styleTypeName === 'Mead/Cider')
                    <tr>
                        <td>{{ $type->styleTypeName }}@if ($type->styleTypeOwn === 'custom') (Custom Style Type)@endif</td>
                        <td>@if (! empty($type->styleTypeEntryLimit)){{ $type->styleTypeEntryLimit }}@endif</td>
                        <td>
                            @if ($type->styleTypeBOS === 'Y')<span class="fa fa-lg fa-check text-success"></span>
                            @else<span class="fa fa-lg fa-times text-danger"></span>
                            @endif
                        </td>
                        <td>
                            @if ($type->styleTypeBOS === 'Y'){{ $bosMethods[(int) $type->styleTypeBOSMethod] ?? '' }}
                            @else N/A
                            @endif
                        </td>
                        <td>
                            <a href="{{ url('/admin/style-types/'.$type->id.'/edit') }}" data-toggle="tooltip" data-placement="top" title="Edit {{ $type->styleTypeName }}"><span class="fa fa-lg fa-pencil"></span></a>
                            @if ($type->styleTypeOwn !== 'bcoe')
                                <form method="post" action="{{ url('/admin/style-types/'.$type->id) }}" class="inline"
                                      onsubmit="return confirm('Are you sure you want to delete {{ $type->styleTypeName }}? This cannot be undone.');">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="btn btn-link" style="margin:0; padding:0;" data-toggle="tooltip" data-placement="top" title="Delete {{ $type->styleTypeName }}"><span class="fa fa-lg fa-trash-o"></span></button>
                                </form>
                            @else
                                <span class="fa fa-lg fa-trash-o text-muted"></span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($row !== null || request()->routeIs('admin.style_types.create'))
        <form data-toggle="validator" role="form" class="form-horizontal" method="post" action="{{ url($row !== null ? '/admin/style-types/'.$row->id : '/admin/style-types') }}">
            @csrf
            @method($row !== null ? 'put' : 'post')
            <input type="hidden" name="styleTypeOwn" value="{{ $row->styleTypeOwn ?? 'custom' }}">

            <div class="form-group">
                <label for="styleTypeName" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Name</label>
                <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                    <div class="input-group has-warning">
                        <input class="form-control" id="styleTypeName" name="styleTypeName" type="text" value="{{ $row->styleTypeName ?? '' }}" placeholder="" autofocus @disabled($row !== null && $row->styleTypeOwn === 'bcoe') required>
                        <span class="input-group-addon" id="styleTypeName-addon2" data-tooltip="true" title="This field is required."><span class="fa fa-star"></span></span>
                    </div>
                    <div class="help-block with-errors"></div>
                </div>
            </div>

            <div class="form-group">
                <label for="styleTypeEntryLimit" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">Entry Limit</label>
                <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                    <div class="input-group">
                        <input class="form-control" id="styleTypeEntryLimit" name="styleTypeEntryLimit" type="number" min="0" value="{{ $row->styleTypeEntryLimit ?? '' }}" placeholder="">
                        <span class="input-group-addon" id="styleTypeEntryLimit-addon2"><span class="fa fa-hashtag"></span></span>
                    </div>
                    <span class="help-block">Limit for this style type only.</span>
                </div>
            </div>

            @if ($row !== null && $row->styleTypeOwn === 'bcoe')
                <input type="hidden" name="styleTypeName" value="{{ $row->styleTypeName }}">
            @endif

            <div class="form-group">
                <label for="brewStyleReqSpec" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">BOS for Style Type</label>
                <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                    <div class="input-group">
                        <label class="radio-inline">
                            <input type="radio" name="styleTypeBOS" value="Y" id="styleTypeBOS_0" @checked($row === null || $row->styleTypeBOS === 'Y') /> Yes
                        </label>
                        <label class="radio-inline">
                            <input type="radio" name="styleTypeBOS" value="N" id="styleTypeBOS_1" @checked($row !== null && $row->styleTypeBOS === 'N') /> No
                        </label>
                    </div>
                    <div class="help-block with-errors"><p>Indicate whether there will be a Best of Show round for this style type.</p></div>
                </div>
            </div>

            <div class="form-group">
                <label for="styleTypeBOSMethod" class="col-lg-2 col-md-3 col-sm-4 col-xs-12 control-label">BOS Pull Method</label>
                <div class="col-lg-6 col-md-6 col-sm-8 col-xs-12">
                    <div class="input-group">
                        <div class="radio">
                            <label>
                                <input type="radio" name="styleTypeBOSMethod" value="1" id="styleTypeBOSMethod_0" @checked((string) ($row->styleTypeBOSMethod ?? '1') === '1') />1st place only
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="styleTypeBOSMethod" value="2" id="styleTypeBOSMethod_1" @checked($row !== null && (string) $row->styleTypeBOSMethod === '2') />1st and 2nd places
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="styleTypeBOSMethod" value="3" id="styleTypeBOSMethod_2" @checked($row !== null && (string) $row->styleTypeBOSMethod === '3') />1st, 2nd, and 3rd places
                            </label>
                        </div>
                    </div>
                    <div class="help-block with-errors"><p>Determine how many placing entries from each medal category should be pulled for this style type in the Best of Show round.</p></div>
                </div>
            </div>

            <div class="bcoem-admin-element hidden-print">
                <div class="form-group">
                    <div class="col-lg-offset-2 col-md-offset-3 col-sm-offset-4">
                        <input type="submit" name="Submit" id="updateStyle" class="btn btn-primary" value="{{ $row !== null ? 'Edit' : 'Add' }} Style Type" />
                    </div>
                </div>
            </div>
        </form>
    @endif
</x-public-layout>
