<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Edit Participant</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('backoffice.participants.update', ['uid' => $participant->uid]) }}">
            @csrf
            @method('PUT')

            <div class="mb-4 row">
                <label for="brewerFirstName" class="col-sm-4 col-form-label"><strong>First Name *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerFirstName" name="brewerFirstName" required
                           value="{{ old('brewerFirstName', $participant->brewerFirstName) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerLastName" class="col-sm-4 col-form-label"><strong>Last Name *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerLastName" name="brewerLastName" required
                           value="{{ old('brewerLastName', $participant->brewerLastName) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerEmail" class="col-sm-4 col-form-label"><strong>Email *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerEmail" name="brewerEmail" type="email" required
                           value="{{ old('brewerEmail', $participant->brewerEmail) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerPhone1" class="col-sm-4 col-form-label">Phone</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerPhone1" name="brewerPhone1"
                           value="{{ old('brewerPhone1', $participant->brewerPhone1) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerAddress" class="col-sm-4 col-form-label">Address</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerAddress" name="brewerAddress"
                           value="{{ old('brewerAddress', $participant->brewerAddress) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerCity" class="col-sm-4 col-form-label">City</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerCity" name="brewerCity"
                           value="{{ old('brewerCity', $participant->brewerCity) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerState" class="col-sm-4 col-form-label">State/Region</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerState" name="brewerState"
                           value="{{ old('brewerState', $participant->brewerState) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerZip" class="col-sm-4 col-form-label">Postal Code</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerZip" name="brewerZip"
                           value="{{ old('brewerZip', $participant->brewerZip) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerClubs" class="col-sm-4 col-form-label">Club(s)</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerClubs" name="brewerClubs"
                           value="{{ old('brewerClubs', $participant->brewerClubs) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerBreweryName" class="col-sm-4 col-form-label">Brewery Name (Pro)</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerBreweryName" name="brewerBreweryName"
                           value="{{ old('brewerBreweryName', $participant->brewerBreweryName) }}">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Participant</button>
            <a class="btn btn-link" href="{{ url('/backoffice/participants') }}">Cancel</a>
        </form>
    </section>
</x-public-layout>
