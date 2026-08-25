<x-public-layout
    :ctx="$ctx"
    :show-hero="false"
>
    <section id="edit-clubs" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.club') }} / {{ __('site.aha_number') }}</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- brewer_form_1.pub.php #participant-clubs / #proAm / #aha-number /
             #mhp-number. Legacy offered a picker fed by the remote clubs
             corpus + contestClubs; the port reuses registration's free-text
             field with the same server-side storage semantics
             (App\Support\Brewer\Clubs). --}}
        <form method="post" action="{{ url('/list/edit-clubs') }}">
            @csrf

            <section id="participant-clubs" class="mb-6">
                <div class="mb-4 row">
                    <label for="brewerClubs" class="col-sm-3 col-form-label"><strong>{{ __('site.club') }}</strong></label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="brewerClubs" name="brewerClubs" type="text"
                               value="{{ old('brewerClubs', $brewer->brewerClubs === 'Other' ? '' : $brewer->brewerClubs) }}">
                        <div class="form-text">{{ __('site.club_other_hint') }}</div>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerClubsOther" class="col-sm-3 col-form-label"><strong>{{ __('site.club_other') }}</strong></label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="brewerClubsOther" name="brewerClubsOther" type="text"
                               value="{{ old('brewerClubsOther', $brewer->brewerClubs === 'Other' ? $brewer->brewerClubs : '') }}">
                    </div>
                </div>
            </section>

            <section id="proAm" class="mb-6">
                <div class="mb-4 row">
                    <label for="brewerProAm" class="col-sm-3 col-form-label"><strong>{{ __('site.pro_am') }}</strong></label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="brewerProAm" name="brewerProAm">
                            <option value="0" @selected(old('brewerProAm', $brewer->brewerProAm ?? '0') === '0')>No</option>
                            <option value="1" @selected(old('brewerProAm', $brewer->brewerProAm) === '1')>Yes</option>
                            <option value="2" @selected(old('brewerProAm', $brewer->brewerProAm) === '2')>Opt out</option>
                        </select>
                    </div>
                </div>
            </section>

            <section id="aha-number" class="mb-6">
                <div class="mb-4 row">
                    <label for="brewerAHA" class="col-sm-3 col-form-label"><strong>{{ __('site.aha_number') }}</strong></label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="brewerAHA" name="brewerAHA" type="text" pattern="[A-Za-z0-9]+"
                               value="{{ old('brewerAHA', $brewer->brewerAHA) }}">
                    </div>
                </div>
            </section>

            @if ($mhpDisplay)
                <section id="mhp-number" class="mb-6">
                    <div class="mb-4 row">
                        <label for="brewerMHP" class="col-sm-3 col-form-label"><strong>{{ __('site.mhp_number') }}</strong></label>
                        <div class="col-sm-9">
                            <input class="input input-bordered" id="brewerMHP" name="brewerMHP" type="text" pattern="\d*"
                                   value="{{ old('brewerMHP', $brewer->brewerMHP) }}">
                        </div>
                    </div>
                </section>
            @endif

            <button type="submit" class="btn btn-lg btn-primary">{{ __('site.save') }}</button>
        </form>
    </section>
</x-public-layout>
