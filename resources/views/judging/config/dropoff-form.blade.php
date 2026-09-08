@php($isEdit = $location !== null)
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Drop-Off Locations: {{ $isEdit ? 'Edit' : 'Add' }} a Drop-Off Location</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ $isEdit ? route('admin.judging.dropoff.update', ['id' => $location->id]) : route('admin.judging.dropoff.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="mb-4 row">
                <label for="dropLocationName" class="col-sm-3 col-form-label">Name</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="dropLocationName" name="dropLocationName" type="text" required value="{{ old('dropLocationName', $location->dropLocationName ?? '') }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="dropLocationPhone" class="col-sm-3 col-form-label">Phone</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="dropLocationPhone" name="dropLocationPhone" type="tel" required value="{{ old('dropLocationPhone', $location->dropLocationPhone ?? '') }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="dropLocation" class="col-sm-3 col-form-label">Address</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="dropLocation" name="dropLocation" type="text" maxlength="255" required value="{{ old('dropLocation', $location->dropLocation ?? '') }}">
                    <div class="form-text">Provide the street address, city, and zip code. 255 character limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="dropLocationWebsite" class="col-sm-3 col-form-label">Website</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="dropLocationWebsite" name="dropLocationWebsite" type="url" placeholder="http://www.yoursite.com" value="{{ old('dropLocationWebsite', $location->dropLocationWebsite ?? '') }}">
                    <div class="form-text">Be sure to include the full website URL including the http:// or https://</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="dropLocationNotes" class="col-sm-3 col-form-label">Notes</label>
                <div class="col-sm-6">
                    <input class="input input-bordered" id="dropLocationNotes" name="dropLocationNotes" type="text" maxlength="255" value="{{ old('dropLocationNotes', $location->dropLocationNotes ?? '') }}">
                    <div class="form-text">Catch-all for items such as when entries will be picked up at the location, etc. 255 character limit.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Edit' : 'Add' }} Drop-Off Location</button>
            <a class="btn btn-secondary" href="{{ route('admin.judging.dropoff.index') }}">Back</a>
        </form>
    </section>
</x-public-layout>
