@php($isEdit = $category !== null)
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Custom Categories: {{ $isEdit ? 'Edit' : 'Add' }} a Custom Category</h1>
        <p class="mt-2"><a class="btn btn-outline btn-secondary btn-sm" href="{{ url('/admin/judging/special-best/create') }}"><span class="fa fa-plus-circle"></span> Add a Custom Category</a></p>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ $isEdit ? route('admin.specialbest.update', ['id' => $category->id]) : route('admin.specialbest.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="mb-4 row">
                <label for="sbi_name" class="col-md-3 col-form-label">Name</label>
                <div class="col-md-6">
                    <input class="form-control" id="sbi_name" name="sbi_name" type="text" required
                           value="{{ old('sbi_name', $category->sbi_name ?? '') }}"
                           placeholder="Pro-Am with XXX Brewery, People's Choice, etc.">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="sbi_places" class="col-md-3 col-form-label">Places</label>
                <div class="col-md-6">
                    <input class="form-control" id="sbi_places" name="sbi_places" type="number" min="1" step="1" required
                           value="{{ old('sbi_places', $category->sbi_places ?? 1) }}">
                    <div class="form-text">The number of places available for the category.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="sbi_description" class="col-md-3 col-form-label">Description</label>
                <div class="col-md-6">
                    <textarea class="form-control" id="sbi_description" name="sbi_description" rows="6">{{ old('sbi_description', $category->sbi_description ?? '') }}</textarea>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-md-3 col-form-label">Awards Deck</span>
                <div class="col-md-6">
                    <div class="form-check">
                        {{-- Hidden 0 so an unchecked box posts explicitly. --}}
                        <input type="hidden" name="sbi_display_places" value="0">
                        <input class="form-check-input" type="checkbox" name="sbi_display_places"
                               id="sbi_display_places" value="1"
                               @checked((string) old('sbi_display_places', $category->sbi_display_places ?? '') === '1')>
                        <label class="form-check-label" for="sbi_display_places">Show each entry's place on the awards deck.</label>
                    </div>
                    <div class="form-text">When off, winners are listed without place numbers.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="sbi_rank" class="col-md-3 col-form-label">Rank</label>
                <div class="col-md-6">
                    <select class="form-select" id="sbi_rank" name="sbi_rank">
                        @foreach (range(1, 20) as $i)
                            <option value="{{ $i }}" @selected((string) old('sbi_rank', $category->sbi_rank ?? '') === (string) $i)>{{ $i }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Display-order priority; the lower the number, the higher priority.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Edit' : 'Add' }} Custom Category</button>
        </form>
    </section>
</x-public-layout>
