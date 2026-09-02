@php $row = $editing ?? null; @endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $row !== null ? 'Edit a Custom Style' : ($row === null && request()->routeIs('admin.styles.create') ? 'Add a Custom Style' : 'Accepted Styles') }}</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Accepted styles updated.</div>
        @elseif ((int) request('msg') === 9)
            <div class="alert alert-success">Style saved.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($row === null && ! request()->routeIs('admin.styles.create'))
            {{-- Accepted styles checklist (bulk update) --}}
            <p class="text-xl font-light"><span class="text-sm">Check or uncheck the styles your competition will accept (any custom styles are at the top of the list).</span></p>
            <form method="post" action="{{ url('/admin/styles') }}">
                @csrf
                @method('put')
                <table class="table table-striped table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Accept</th>
                            <th>Style Name</th>
                            <th>#</th>
                            <th>Style Type</th>
                            <th>Requirements</th>
                            <th>Restrict Entries</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($styles as $style)
                            <tr>
                                <td><input type="hidden" name="id[]" value="{{ $style->id }}">
                                    <input type="checkbox" name="brewStyleActive{{ $style->id }}" value="Y" @checked(array_key_exists($style->id, $selected))></td>
                                <td>{{ $style->brewStyle }}@if ($style->brewStyleOwn !== 'bcoe') *Custom Style @endif</td>
                                <td>{{ $style->brewStyleGroup }}{{ $style->brewStyleNum }}</td>
                                <td>{{ optional($styleTypes->firstWhere('id', $style->brewStyleType))->styleTypeName }}</td>
                                <td>
                                    @if (((int) $style->brewStyleReqSpec) === 1) ReqSpec @endif
                                    @if (((int) $style->brewStyleStrength) === 1) Strength @endif
                                    @if (((int) $style->brewStyleCarb) === 1) Carb @endif
                                    @if (((int) $style->brewStyleSweet) === 1) Sweet @endif
                                </td>
                                <td><input type="checkbox" name="brewStyleAtLimit{{ $style->id }}" value="1" @checked(((int) $style->brewStyleAtLimit) === 1)></td>
                                <td>
                                    @if ($style->brewStyleOwn !== 'bcoe')
                                        <a class="btn btn-sm btn-outline btn-secondary" href="{{ url('/admin/styles/'.$style->id.'/edit') }}">Edit</a>
                                        <form method="post" action="{{ url('/admin/styles/'.$style->id) }}" class="inline"
                                            onsubmit="return confirm('Delete this custom style? This cannot be undone.');">
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="btn btn-sm btn-outline btn-error">Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary">Update Accepted Styles</button>
                <span class="form-text">Select "Update Accepted Styles" <em>before</em> paging through records.</span>
            </form>

            <p class="mt-4">
                <a class="btn btn-primary" href="{{ url('/admin/styles/create') }}">A Custom Style</a>
                <a class="btn btn-outline btn-primary" href="{{ url('/admin/style-types/create') }}">Add a Style Type</a>
            </p>
        @endif

        {{-- Custom style add/edit form --}}
        @if ($row !== null || request()->routeIs('admin.styles.create'))
            <h2>{{ $row !== null ? 'Edit Custom Style' : 'Add a Custom Style' }}</h2>
            <form method="post" action="{{ url($row !== null ? '/admin/styles/'.$row->id : '/admin/styles') }}">
                @csrf
                @method($row !== null ? 'put' : 'post')
                <input type="hidden" name="brewStyleOld" value="{{ $row->brewStyle ?? '' }}">
                <input type="hidden" name="brewStyleActive" value="{{ $row->brewStyleActive ?? 'Y' }}">
                <input type="hidden" name="brewStyleOwn" value="{{ $row->brewStyleOwn ?? 'custom' }}">

                <div class="mb-4 row">
                    <label for="brewStyle" class="col-md-4 col-form-label">Name</label>
                    <div class="col-md-9"><input class="form-control" id="brewStyle" name="brewStyle" type="text" value="{{ $row->brewStyle ?? '' }}" required></div>
                </div>
                <div class="mb-4 row">
                    <label for="brewStyleGroup" class="col-md-4 col-form-label">Style Number or Identifier</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewStyleGroup" name="brewStyleGroup" type="text" maxlength="3" value="{{ $row->brewStyleGroup ?? '' }}" required>
                        <span class="form-text">Overall identifier; three character limit.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewStyleNum" class="col-md-4 col-form-label">Sub-Style Number or Identifier</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewStyleNum" name="brewStyleNum" type="text" maxlength="2" value="{{ $row->brewStyleNum ?? '' }}" required>
                        <span class="form-text">Unique identifier; two character limit.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewStyleType" class="col-md-4 col-form-label">Style Type</label>
                    <div class="col-md-9">
                        <select class="form-select" id="brewStyleType" name="brewStyleType" style="width:auto;" required>
                            @foreach ($styleTypes as $type)
                                @continue($type->styleTypeName === 'Mead/Cider')
                                <option value="{{ $type->id }}" @selected((string) ($row->brewStyleType ?? '') === (string) $type->id)>{{ $type->styleTypeName }}</option>
                            @endforeach
                        </select>
                        <span class="form-text"><a class="btn btn-sm btn-primary" href="{{ url('/admin/style-types/create') }}"><span class="fa fa-plus-circle"></span> Add a Style Type</a></span>
                    </div>
                </div>
                @foreach ([
                    'brewStyleReqSpec' => 'Required Info',
                    'brewStyleCarb' => 'Require Carbonation',
                    'brewStyleSweet' => 'Require Sweetness',
                    'brewStyleStrength' => 'Require Strength',
                ] as $field => $label)
                    <div class="mb-4 row">
                        <label class="col-md-4 col-form-label">{{ $label }}</label>
                        <div class="col-md-9">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="{{ $field }}" value="1" id="{{ $field }}_yes"
                                    @checked((string) ($row->{$field} ?? '0') === '1')>
                                <label class="form-check-label" for="{{ $field }}_yes">Yes</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="{{ $field }}" value="0" id="{{ $field }}_no"
                                    @checked((string) ($row->{$field} ?? '0') === '0')>
                                <label class="form-check-label" for="{{ $field }}_no">No</label>
                            </div>
                        </div>
                    </div>
                @endforeach
                <div class="mb-4 row">
                    <label for="brewStyleEntry" class="col-md-4 col-form-label">Entry Info</label>
                    <div class="col-md-9"><textarea class="form-control" id="brewStyleEntry" name="brewStyleEntry" rows="6">{{ $row->brewStyleEntry ?? '' }}</textarea></div>
                </div>
                <div class="mb-4 row">
                    <label for="brewStyleInfo" class="col-md-4 col-form-label">Description</label>
                    <div class="col-md-9"><textarea class="form-control" id="brewStyleInfo" name="brewStyleInfo" rows="6">{{ $row->brewStyleInfo ?? '' }}</textarea></div>
                </div>
                @foreach ([
                    'brewStyleOG' => 'OG Minimum', 'brewStyleOGMax' => 'OG Maximum',
                    'brewStyleFG' => 'FG Minimum', 'brewStyleFGMax' => 'FG Maximum',
                    'brewStyleABV' => 'ABV Minimum', 'brewStyleABVMax' => 'ABV Maximum',
                    'brewStyleIBU' => 'IBU Minimum', 'brewStyleIBUMax' => 'IBU Maximum',
                    'brewStyleSRM' => 'Color Minimum', 'brewStyleSRMMax' => 'Color Maximum',
                    'brewStyleLink' => 'Reference Link',
                ] as $field => $label)
                    <div class="mb-4 row">
                        <label for="{{ $field }}" class="col-md-4 col-form-label">{{ $label }}</label>
                        <div class="col-md-9"><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $row->{$field} ?? '' }}"></div>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">{{ $row !== null ? 'Edit' : 'Add' }} Custom Style</button>
            </form>
        @endif
    </section>
</x-public-layout>
