<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="__('site.my_account')"
    :show-hero="false"
>
    <section id="edit-account" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.edit_account') }}</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ url('/list/edit-account') }}" class="needs-validation" novalidate>
            @csrf

            <div class="mb-4 row">
                <label for="brewerEmail" class="col-sm-3 col-form-label">{{ __('site.email') }} *</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerEmail" name="brewerEmail" type="email" required
                           value="{{ old('brewerEmail', $brewer->brewerEmail) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerFirstName" class="col-sm-3 col-form-label">{{ __('site.first_name') }} *</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerFirstName" name="brewerFirstName" type="text" required
                           value="{{ old('brewerFirstName', $brewer->brewerFirstName) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerLastName" class="col-sm-3 col-form-label">{{ __('site.last_name') }} *</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerLastName" name="brewerLastName" type="text" required
                           value="{{ old('brewerLastName', $brewer->brewerLastName) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerCountry" class="col-sm-3 col-form-label">{{ __('site.country') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerCountry" name="brewerCountry" type="text"
                           value="{{ old('brewerCountry', $brewer->brewerCountry ?? 'United States') }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerAddress" class="col-sm-3 col-form-label">{{ __('site.address') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerAddress" name="brewerAddress" type="text"
                           value="{{ old('brewerAddress', $brewer->brewerAddress) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerCity" class="col-sm-3 col-form-label">{{ __('site.city') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerCity" name="brewerCity" type="text"
                           value="{{ old('brewerCity', $brewer->brewerCity) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerState" class="col-sm-3 col-form-label">{{ __('site.state') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerState" name="brewerState" type="text"
                           value="{{ old('brewerState', $brewer->brewerState) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerZip" class="col-sm-3 col-form-label">{{ __('site.zip') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerZip" name="brewerZip" type="text"
                           value="{{ old('brewerZip', $brewer->brewerZip) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerPhone1" class="col-sm-3 col-form-label">{{ __('site.phone') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerPhone1" name="brewerPhone1" type="tel"
                           value="{{ old('brewerPhone1', $brewer->brewerPhone1) }}">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="brewerPhone2" class="col-sm-3 col-form-label">{{ __('site.phone2') }}</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerPhone2" name="brewerPhone2" type="tel"
                           value="{{ old('brewerPhone2', $brewer->brewerPhone2) }}">
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-sm-9 offset-sm-3">
                    <button type="submit" class="btn btn-lg btn-primary">{{ __('site.save') }}</button>
                </div>
            </div>
        </form>
    </section>
</x-public-layout>
