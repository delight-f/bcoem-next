@php $row = $editing ?? null; @endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $row !== null ? 'Edit the '.e($row->styleTypeName).' Style Type' : 'Style Types' }}</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Style types updated.</div>
        @elseif ((int) request('msg') === 9)
            <div class="alert alert-success">Style type saved.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @php
            $meadCider = $styleTypes->firstWhere('styleTypeName', 'Mead/Cider');
            $combined = $meadCider !== null && $meadCider->styleTypeBOS === 'Y';
        @endphp

        @if ($row === null)
            <table class="table table-zebra table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Entry Limit</th>
                        <th>BOS Enabled?</th>
                        <th>BOS Pull Method</th>
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
                            <td>{{ $type->styleTypeEntryLimit }}</td>
                            <td>{{ $type->styleTypeBOS === 'Y' ? '<span class="text-success">Yes</span>' : '<span class="text-error">No</span>' }}</td>
                            <td>{{ $type->styleTypeBOS === 'Y' ? ['1st place only', '1st and 2nd places', '1st, 2nd, and 3rd places'][(int) $type->styleTypeBOSMethod - 1] ?? '' : 'N/A' }}</td>
                            <td>
                                <a class="btn btn-sm btn-outline btn-secondary" href="{{ url('/admin/style-types/'.$type->id.'/edit') }}">Edit</a>
                                @if ($type->styleTypeOwn !== 'bcoe')
                                    <form method="post" action="{{ url('/admin/style-types/'.$type->id) }}" class="inline"
                                        onsubmit="return confirm('Are you sure you want to delete this style type? This cannot be undone.');">
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

            <div class="mb-4">
                <a class="btn btn-primary" href="{{ url('/admin/style-types/create') }}">Add a Custom Style Type</a>
                @if ($combined)
                    <form method="post" action="{{ url('/admin/style-types/separate') }}" class="inline"
                        onsubmit="return confirm('Separate mead and cider into two distinct style types? This will clear any Mead/Cider BOS scores already entered.');">
                        @csrf
                        <button type="submit" class="btn btn-success">Separate Mead and Cider?</button>
                    </form>
                @else
                    <form method="post" action="{{ url('/admin/style-types/combine') }}" class="inline"
                        onsubmit="return confirm('Combine mead and cider into a single style type? This will also enable BOS for the combined type and clear any Mead or Cider BOS scores already in the database.');">
                        @csrf
                        <button type="submit" class="btn btn-success">Combine Mead and Cider?</button>
                    </form>
                @endif
            </div>
        @endif

        @if ($row !== null || request()->routeIs('admin.style_types.create'))
            <h2>{{ $row !== null ? 'Edit Style Type' : 'Add a Custom Style Type' }}</h2>
            <form method="post" action="{{ url($row !== null ? '/admin/style-types/'.$row->id : '/admin/style-types') }}">
                @csrf
                @method($row !== null ? 'put' : 'post')
                <input type="hidden" name="styleTypeOwn" value="{{ $row->styleTypeOwn ?? 'custom' }}">
                <div class="mb-4 row">
                    <label for="styleTypeName" class="col-sm-4 col-form-label">Name</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="styleTypeName" name="styleTypeName" type="text" value="{{ $row->styleTypeName ?? '' }}"
                            @disabled($row !== null && $row->styleTypeOwn === 'bcoe') required>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="styleTypeEntryLimit" class="col-sm-4 col-form-label">Entry Limit</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="styleTypeEntryLimit" name="styleTypeEntryLimit" type="number" min="0" style="width:auto;" value="{{ $row->styleTypeEntryLimit ?? '' }}">
                        <span class="help-block">Limit for this style type only.</span>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label class="col-sm-4 col-form-label">BOS for Style Type</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="styleTypeBOS" value="Y" id="bosYes" @checked($row === null || $row->styleTypeBOS === 'Y')>
                            <label class="form-check-label" for="bosYes">Yes</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="radio" type="radio" name="styleTypeBOS" value="N" id="bosNo" @checked($row !== null && $row->styleTypeBOS === 'N')>
                            <label class="form-check-label" for="bosNo">No</label>
                        </div>
                        <div class="help-block">Indicate whether there will be a Best of Show round for this style type.</div>
                    </div>
                </div>
                <fieldset class="mb-4">
                    <legend class="text-sm">BOS Pull Method</legend>
                    @foreach ([1 => '1st place only', 2 => '1st and 2nd places', 3 => '1st, 2nd, and 3rd places'] as $value => $label)
                        <div class="form-check">
                            <input class="radio" type="radio" name="styleTypeBOSMethod" value="{{ $value }}" id="bosMethod{{ $value }}"
                                @checked((string) ($row->styleTypeBOSMethod ?? '1') === (string) $value)>
                            <label class="form-check-label" for="bosMethod{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </fieldset>
                <button type="submit" class="btn btn-primary">{{ $row !== null ? 'Edit' : 'Add' }} Style Type</button>
            </form>
        @endif
    </section>
</x-public-layout>
