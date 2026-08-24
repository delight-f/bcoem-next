<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="register" class="landing-page-section mt-4 mb-3">
        <h1>{{ __('site.register') }}</h1>

        @if (! $allowed)
            <p class="lead">{{ __('site.registration_closed') }}</p>
        @else
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link {{ $go === 'entrant' ? 'active' : '' }}"
                       href="{{ url('/?section=register&go=entrant') }}">{{ __('site.entrant') }}</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $go === 'judge' ? 'active' : '' }}"
                       href="{{ url('/?section=register&go=judge') }}">{{ __('site.judge') }}</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $go === 'steward' ? 'active' : '' }}"
                       href="{{ url('/?section=register&go=steward') }}">{{ __('site.steward') }}</a>
                </li>
            </ul>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="post" action="{{ url('/register/'.$go) }}" class="needs-validation" novalidate>
                @csrf
                <input type="hidden" name="userLevel" value="2">
                <input type="hidden" name="brewerJudge" value="{{ $go === 'judge' ? 'Y' : 'N' }}">
                <input type="hidden" name="brewerSteward" value="{{ $go === 'steward' ? 'Y' : 'N' }}">

                <div class="mb-3 row">
                    <label for="user_name" class="col-sm-3 col-form-label">{{ __('site.email') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="user_name" name="user_name" type="email" required
                               value="{{ old('user_name') }}">
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="password" class="col-sm-3 col-form-label">{{ __('site.password') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="password" name="password" type="password" required>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="password-confirm" class="col-sm-3 col-form-label">{{ __('site.confirm_password') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="password-confirm" name="password_confirmation" type="password" required>
                    </div>
                </div>

                <div class="mb-3 row">
                    <label class="col-sm-3 col-form-label">{{ __('site.security_question') }} *</label>
                    <div class="col-sm-9">
                        <select class="form-select" name="userQuestion" required>
                            <option value="">{{ __('site.select_security_question') }}</option>
                            <option value="What is your favorite all-time beer to drink?" {{ old('userQuestion') === 'What is your favorite all-time beer to drink?' ? 'selected' : '' }}>
                                What is your favorite all-time beer to drink?
                            </option>
                            <option value="What is the name of your first pet?" {{ old('userQuestion') === 'What is the name of your first pet?' ? 'selected' : '' }}>
                                What is the name of your first pet?
                            </option>
                            <option value="In what city were you born?" {{ old('userQuestion') === 'In what city were you born?' ? 'selected' : '' }}>
                                In what city were you born?
                            </option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="userQuestionAnswer" class="col-sm-3 col-form-label">{{ __('site.security_answer') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="userQuestionAnswer" name="userQuestionAnswer" type="text" required
                               value="{{ old('userQuestionAnswer') }}">
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="brewerFirstName" class="col-sm-3 col-form-label">{{ __('site.first_name') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerFirstName" name="brewerFirstName" type="text" required
                               value="{{ old('brewerFirstName') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerLastName" class="col-sm-3 col-form-label">{{ __('site.last_name') }} *</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerLastName" name="brewerLastName" type="text" required
                               value="{{ old('brewerLastName') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerCountry" class="col-sm-3 col-form-label">{{ __('site.country') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerCountry" name="brewerCountry" type="text"
                               value="{{ old('brewerCountry', 'United States') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerAddress" class="col-sm-3 col-form-label">{{ __('site.address') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerAddress" name="brewerAddress" type="text"
                               value="{{ old('brewerAddress') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerCity" class="col-sm-3 col-form-label">{{ __('site.city') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerCity" name="brewerCity" type="text"
                               value="{{ old('brewerCity') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerState" class="col-sm-3 col-form-label">{{ __('site.state') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerState" name="brewerState" type="text"
                               value="{{ old('brewerState') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerZip" class="col-sm-3 col-form-label">{{ __('site.zip') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerZip" name="brewerZip" type="text"
                               value="{{ old('brewerZip') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerPhone1" class="col-sm-3 col-form-label">{{ __('site.phone') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerPhone1" name="brewerPhone1" type="tel"
                               value="{{ old('brewerPhone1') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerClubs" class="col-sm-3 col-form-label">{{ __('site.club') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerClubs" name="brewerClubs" type="text"
                               value="{{ old('brewerClubs') }}" placeholder="{{ __('site.club_other_hint') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerClubsOther" class="col-sm-3 col-form-label">{{ __('site.club_other') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerClubsOther" name="brewerClubsOther" type="text"
                               value="{{ old('brewerClubsOther') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerAHA" class="col-sm-3 col-form-label">{{ __('site.aha_number') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerAHA" name="brewerAHA" type="text" pattern="[A-Za-z0-9]+"
                               value="{{ old('brewerAHA') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerMHP" class="col-sm-3 col-form-label">{{ __('site.mhp_number') }}</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="brewerMHP" name="brewerMHP" type="text" pattern="\d*"
                               value="{{ old('brewerMHP') }}">
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="brewerProAm" class="col-sm-3 col-form-label">{{ __('site.pro_am') }}</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="brewerProAm" name="brewerProAm">
                            <option value="0" {{ old('brewerProAm', '0') === '0' ? 'selected' : '' }}>No</option>
                            <option value="1" {{ old('brewerProAm') === '1' ? 'selected' : '' }}>Yes</option>
                            <option value="2" {{ old('brewerProAm') === '2' ? 'selected' : '' }}>Opt out</option>
                        </select>
                    </div>
                </div>

                @if ($go === 'judge' || $go === 'steward')
                    <div class="mb-3 row">
                        <label for="brewerJudgeWaiver" class="col-sm-3 col-form-label">{{ __('site.waiver') }} *</label>
                        <div class="col-sm-9 form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="brewerJudgeWaiver" name="brewerJudgeWaiver"
                                   value="Y" checked required>
                            <label class="form-check-label" for="brewerJudgeWaiver">{{ __('site.waiver_accept') }}</label>
                        </div>
                    </div>
                @endif

                <div class="row mb-3">
                    <div class="col-sm-9 offset-sm-3">
                        <button type="submit" class="btn btn-lg btn-primary">{{ __('site.register') }}</button>
                    </div>
                </div>
            </form>
        @endif
    </section>
</x-public-layout>
