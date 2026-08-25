@php
    /** @var object|null $row editing target when set */
    $row = $editing ?? null;
@endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-4 mb-3">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $row !== null ? 'Edit a Sponsor' : 'Sponsors' }}</h1>

        @if ((int) request('msg') === 9)
            <div class="alert alert-success">Sponsors updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        {{-- List with inline enable/level/image/text editors --}}
        @foreach ($sponsors as $sponsor)
            <div class="border-bottom pb-2 mb-2">
                <form method="post" action="{{ url('/admin/sponsors') }}" class="row g-2 align-items-center">
                    @csrf
                    @method('put')
                    <input type="hidden" name="id[]" value="{{ $sponsor->id }}">
                    <div class="col-md-3">
                        <strong>{{ $sponsor->sponsorName }}</strong>
                        <span class="text-muted d-block small">{{ $sponsor->sponsorLocation }} &middot; {{ $sponsor->sponsorURL }}</span>
                    </div>
                    <div class="col-md-2">
                        <label class="visually-hidden" for="sponsorLevel{{ $sponsor->id }}">Level</label>
                        <select class="form-select form-select-sm" id="sponsorLevel{{ $sponsor->id }}" name="sponsorLevel{{ $sponsor->id }}">
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}" @selected((string) $sponsor->sponsorLevel === (string) $i)>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="visually-hidden" for="sponsorImage{{ $sponsor->id }}">Logo</label>
                        <select class="form-select form-select-sm" id="sponsorImage{{ $sponsor->id }}" name="sponsorImage{{ $sponsor->id }}">
                            <option value="">(no logo)</option>
                            @foreach ($sponsorImages as $file)
                                <option value="{{ $file }}" @selected($sponsor->sponsorImage === $file)>{{ $file }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="visually-hidden" for="sponsorText{{ $sponsor->id }}">Description</label>
                        <textarea class="form-control form-control-sm" id="sponsorText{{ $sponsor->id }}" name="sponsorText{{ $sponsor->id }}" rows="2">{{ $sponsor->sponsorText }}</textarea>
                    </div>
                    <div class="col-auto form-check ms-2">
                        <input class="form-check-input" type="checkbox" id="sponsorEnable{{ $sponsor->id }}" name="sponsorEnable{{ $sponsor->id }}" value="1" @checked(((int) $sponsor->sponsorEnable) === 1)>
                        <label class="form-check-label small" for="sponsorEnable{{ $sponsor->id }}">Display</label>
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Save</button></div>
                </form>
                <div class="mt-1">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ url('/admin/sponsors/'.$sponsor->id.'/edit') }}">Edit</a>
                    <form method="post" action="{{ url('/admin/sponsors/'.$sponsor->id) }}" class="d-inline"
                        onsubmit="return confirm('Are you sure you want to delete {{ $sponsor->sponsorName }} as a sponsor? This cannot be undone.');">
                        @csrf
                        @method('delete')
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
        @if ($sponsors->isEmpty())
            <p>There are no sponsors in the database.</p>
        @endif

        <p><a class="btn btn-primary" href="{{ url('/admin/sponsors/create') }}">Add a Sponsor</a></p>

        {{-- Add/edit form --}}
        @if ($row !== null || request()->routeIs('admin.sponsors.create'))
            <h2>{{ $row !== null ? 'Edit Sponsor' : 'Add a Sponsor' }}</h2>
            <form method="post" action="{{ url($row !== null ? '/admin/sponsors/'.$row->id : '/admin/sponsors') }}" class="form-horizontal">
                @csrf
                @method($row !== null ? 'put' : 'post')
                <div class="mb-3 row">
                    <label for="sponsorName" class="col-sm-3 col-form-label">Name</label>
                    <div class="col-sm-9"><input class="form-control" id="sponsorName" name="sponsorName" type="text" maxlength="255" value="{{ $row->sponsorName ?? '' }}" required></div>
                </div>
                <div class="mb-3 row">
                    <label for="sponsorLocation" class="col-sm-3 col-form-label">Location</label>
                    <div class="col-sm-9"><input class="form-control" id="sponsorLocation" name="sponsorLocation" type="text" value="{{ $row->sponsorLocation ?? '' }}"></div>
                </div>
                <div class="mb-3 row">
                    <label for="sponsorLevel" class="col-sm-3 col-form-label">Level</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="sponsorLevel" name="sponsorLevel" style="width:auto;">
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}" @selected((string) ($row->sponsorLevel ?? '1') === (string) $i)>{{ $i }}</option>
                            @endfor
                        </select>
                        <span class="help-block">1 is the highest level; 5 the lowest.</span>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="sponsorURL" class="col-sm-3 col-form-label">Website</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="sponsorURL" name="sponsorURL" type="text" value="{{ $row->sponsorURL ?? '' }}">
                        <span class="help-block">Be sure to include the full website URL including the http://</span>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="sponsorImage" class="col-sm-3 col-form-label">Logo File Name</label>
                    <div class="col-sm-9">
                        @if ($sponsorImages !== [])
                            <select class="form-select" id="sponsorImage" name="sponsorImage" style="width:auto;">
                                <option value=""></option>
                                @foreach ($sponsorImages as $file)
                                    <option value="{{ $file }}" @selected(($row->sponsorImage ?? '') === $file)>{{ $file }}</option>
                                @endforeach
                            </select>
                        @else
                            <p>No images exist in the user_images directory.</p>
                        @endif
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="sponsorText" class="col-sm-3 col-form-label">Description</label>
                    <div class="col-sm-9"><textarea class="form-control" id="sponsorText" name="sponsorText" rows="6">{{ $row->sponsorText ?? '' }}</textarea></div>
                </div>
                <div class="mb-3 row">
                    <label class="col-sm-3 col-form-label">Display?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="sponsorEnable" value="1" id="sponsorEnableYes" @checked($row === null || ((int) $row->sponsorEnable) === 1)>
                            <label class="form-check-label" for="sponsorEnableYes">Yes</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="sponsorEnable" value="0" id="sponsorEnableNo" @checked($row !== null && ((int) $row->sponsorEnable) === 0)>
                            <label class="form-check-label" for="sponsorEnableNo">No</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ $row !== null ? 'Edit' : 'Add' }} Sponsor</button>
            </form>
        @endif
    </section>
</x-public-layout>
