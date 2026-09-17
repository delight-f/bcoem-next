<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Banner Images</h1>

        @if (request('msg') === 'saved')
            <div class="alert alert-success">Hero images preferences saved successfully.</div>
        @elseif (request('msg') === 'uploaded')
            <div class="alert alert-success">Banner image uploaded successfully.</div>
        @elseif (request('msg') === 'deleted')
            <div class="alert alert-success">Banner image deleted successfully.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <p>Select which banner images are displayed on the homepage. Images are randomly selected based on your competition's accepted style types.</p>

        <div class="border rounded p-4 mb-4">
            <strong>How it works:</strong> Banner images appear as a large background strip at the top of the competition
            homepage. One image is picked at random each time a visitor loads the page. Miscellaneous images can appear at
            any time; Beer, Cider, and Mead images only appear when your competition accepts entries in those categories.
        </div>

        {{-- Upload --}}
        <form method="post" action="{{ url('/admin/hero-images/upload') }}" enctype="multipart/form-data" class="border rounded p-4 mb-4">
            @csrf
            <h4>Upload New Banner Image</h4>
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label for="hero_image_category" class="form-label">Category</label>
                    <select class="form-select" id="hero_image_category" name="hero_image_category" required>
                        <option value="">Select a category...</option>
                        <option value="0">Miscellaneous</option>
                        <option value="1">Beer</option>
                        <option value="2">Cider</option>
                        <option value="3">Mead</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label for="hero_image_file" class="form-label">Image File</label>
                    <input type="file" class="form-control" id="hero_image_file" name="hero_image_file" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success">Upload Image</button>
                </div>
            </div>
            <p class="form-text mt-2 mb-0">The uploaded file is renamed using the category as a prefix (e.g.
                <em>sunset.jpg</em> in Beer saves as <code>beer-sunset.jpg</code>). Minimum width 1200 px with at least a
                3.5:1 aspect ratio; 5 MB max.</p>
        </form>

        {{-- Rotation choices --}}
        <form method="post" action="{{ url('/admin/hero-images/save') }}">
            @csrf
            @foreach ($imagesByCategory as $categoryId => $images)
                <fieldset class="mb-4 border rounded p-4">
                    <legend class="fs-6">{{ $categories[$categoryId] }}
                        @if ($categoryId === '0')<span class="text-muted">(shown on all pages)</span>@endif
                    </legend>
                    @forelse ($images as $image)
                        @php $field = 'hero_image_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $image); @endphp
                        <div class="form-check form-check-inline mb-3">
                            <input class="form-check-input" type="checkbox" id="{{ $field }}" name="{{ $field }}" value="1"
                                @checked($prefs[$image] ?? true)>
                            <label class="form-check-label d-flex flex-column align-items-center text-center" for="{{ $field }}">
                                <img src="{{ asset('images/'.$image) }}" alt="" class="hero-image-thumb">
                                <span class="small text-muted text-break">{{ $image }}</span>
                            </label>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No images in this category.</p>
                    @endforelse
                </fieldset>
            @endforeach

            @php $allKnown = array_merge(...array_values($imagesByCategory)); @endphp
            @if ($allKnown !== [])
                <button type="submit" class="btn btn-primary">Save Changes</button>
            @endif
        </form>

        {{-- Delete --}}
        @if ($allKnown !== [])
            <form method="post" action="{{ url('/admin/hero-images/delete') }}" class="mt-4">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label for="hero_image_delete" class="form-label">Delete an image</label>
                        <select class="form-select" id="hero_image_delete" name="hero_image_delete" required>
                            @foreach ($allKnown as $image)
                                <option value="{{ $image }}">{{ $image }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-danger">Delete Image</button></div>
                </div>
            </form>
        @endif
    </section>
</x-public-layout>
