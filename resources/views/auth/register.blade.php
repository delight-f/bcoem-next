<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="register" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.register') }}</h1></header>

        @if (! $allowed)
            {{-- Legacy redirects to the reg_closed section (pub/reg_closed.pub.php:32):
                 "Thanks and Good Luck To All Who Entered the {contest}!" --}}
            <h2>{{ __('site.thanks_good_luck') }} {{ $ctx->contestStr('contestName') }}!</h2>
            <p class="fs-5 fw-light">{{ __('site.registration_closed') }}</p>
        @else
            @if ($adminRegister ?? false)
                {{-- Legacy register.sec.php:334-362 — admin register chrome:
                     All Participants back button + standard/quick register
                     dropdowns (judge/steward), admin-form hrefs. --}}
                <div class="bcoem-admin-element d-print-none mb-4">
                    <div class="btn-group" role="group">
                        <a class="btn btn-secondary" href="{{ url('/backoffice/participants') }}"><span class="fa fa-arrow-circle-left"></span> All Participants</a>
                    </div>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="fa fa-plus-circle"></span> Register Judge/Steward (Standard)
                        </button>
                        <ul class="dropdown-menu">
                            <li class="{{ (! $quickView && $go === 'judge') ? 'disabled' : '' }}"><a href="{{ url('/register/judge') }}">Judge</a></li>
                            <li class="{{ (! $quickView && $go === 'steward') ? 'disabled' : '' }}"><a href="{{ url('/register/steward') }}">Steward</a></li>
                        </ul>
                    </div>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="fa fa-plus-circle"></span> Register Judge/Steward (Quick)
                        </button>
                        <ul class="dropdown-menu">
                            <li class="{{ ($quickView && $go === 'judge') ? 'disabled' : '' }}"><a href="{{ url('/register/judge') }}?view=quick">Judge</a></li>
                            <li class="{{ ($quickView && $go === 'steward') ? 'disabled' : '' }}"><a href="{{ url('/register/steward') }}?view=quick">Steward</a></li>
                        </ul>
                    </div>
                </div>
            @endif

            {{-- register.sec.php:137-146 — admin lead, and the quick-view
                 explainer (register_text_012: dummy address/phone + bcoem). --}}
            @if ($adminRegister ?? false)
                <p class="lead">
                    @if ($quickView)Quick @endif Register
                    @if ($go === 'judge') a Judge @elseif ($go === 'steward') a Steward @endif
                </p>
                @if ($quickView)
                    <p class="fs-6">Quickly add a participant to the competition&rsquo;s judge/steward pool. A dummy address and phone number will be used and a default password of <em>bcoem</em> will be given to each participant added via this screen.</p>
                @endif
            @endif

            <ul class="nav nav-tabs mb-4">
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
                {{-- Honeypot + time trap (spatie/laravel-honeypot). Renders
                     nothing when HONEYPOT_ENABLED=false. --}}
                <x-honeypot />
                <input type="hidden" name="userLevel" value="2">
                <input type="hidden" name="brewerJudge" value="{{ $go === 'judge' ? 'Y' : 'N' }}">
                <input type="hidden" name="brewerSteward" value="{{ $go === 'steward' ? 'Y' : 'N' }}">

                <div class="mb-4 row">
                    <label for="user_name" class="col-md-3 col-form-label">{{ __('site.email') }} *</label>
                    <div class="col-md-9">
                        <input class="form-control" id="user_name" name="user_name" type="email" required
                               value="{{ old('user_name') }}" autocomplete="off">
                    </div>
                </div>
                {{-- Live checks (P3.7): legacy injected these fragments from
                     username.ajax.php / valid_email.ajax.php; the port keeps
                     the same element ids and fragment markup. --}}
                <div class="mb-4 row">
                    <div class="col-md-9 offset-md-3">
                        <div id="msg_email"></div>
                        <div id="username-status"></div>
                    </div>
                </div>

                {{-- register.sec.php:496-553 — password fields sit inside the
                     view=="default" block, so the admin quick form omits them
                     (legacy posts a fixed default password server-side). --}}
                @unless ($adminRegister && $quickView)
                    <div class="mb-4 row">
                        <label for="password" class="col-md-3 col-form-label">{{ __('site.password') }} *</label>
                        <div class="col-md-9">
                            <input class="form-control" id="password" name="password" type="password" required>
                        </div>
                    </div>
                    <div class="mb-4 row">
                        <label for="password-confirm" class="col-md-3 col-form-label">{{ __('site.confirm_password') }} *</label>
                        <div class="col-md-9">
                            <input class="form-control" id="password-confirm" name="password_confirmation" type="password" required>
                        </div>
                    </div>
                @endunless

                {{-- register.sec.php:555-597 — security Q/A only for
                     self-registration; admins get the legacy random defaults. --}}
                @unless ($adminRegister)
                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label">{{ __('site.security_question') }} *</label>
                        <div class="col-md-9">
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
                    <div class="mb-4 row">
                        <label for="userQuestionAnswer" class="col-md-3 col-form-label">{{ __('site.security_answer') }} *</label>
                        <div class="col-md-9">
                            <input class="form-control" id="userQuestionAnswer" name="userQuestionAnswer" type="text" required
                                   value="{{ old('userQuestionAnswer') }}">
                        </div>
                    </div>
                @endunless

                <div class="mb-4 row">
                    <label for="brewerFirstName" class="col-md-3 col-form-label">{{ __('site.first_name') }} *</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerFirstName" name="brewerFirstName" type="text" required
                               value="{{ old('brewerFirstName') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerLastName" class="col-md-3 col-form-label">{{ __('site.last_name') }} *</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerLastName" name="brewerLastName" type="text" required
                               value="{{ old('brewerLastName') }}">
                    </div>
                </div>
                {{-- register.sec.php:601-785 — country through pro-am sit inside
                     the view=="default" block. The admin quick form hides them
                     and submits the legacy dummy contact values instead. --}}
                @unless ($adminRegister && $quickView)
                    <div class="mb-4 row">
                        <label for="brewerCountry" class="col-md-3 col-form-label">{{ __('site.country') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerCountry" name="brewerCountry" type="text"
                               value="{{ old('brewerCountry', 'United States') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerAddress" class="col-md-3 col-form-label">{{ __('site.address') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerAddress" name="brewerAddress" type="text"
                               value="{{ old('brewerAddress') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerCity" class="col-md-3 col-form-label">{{ __('site.city') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerCity" name="brewerCity" type="text"
                               value="{{ old('brewerCity') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerState" class="col-md-3 col-form-label">{{ __('site.state') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerState" name="brewerState" type="text"
                               value="{{ old('brewerState') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerZip" class="col-md-3 col-form-label">{{ __('site.zip') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerZip" name="brewerZip" type="text"
                               value="{{ old('brewerZip') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerPhone1" class="col-md-3 col-form-label">{{ __('site.phone') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerPhone1" name="brewerPhone1" type="tel"
                               value="{{ old('brewerPhone1') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerClubs" class="col-md-3 col-form-label">{{ __('site.club') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerClubs" name="brewerClubs" type="text"
                               value="{{ old('brewerClubs') }}" placeholder="{{ __('site.club_other_hint') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerClubsOther" class="col-md-3 col-form-label">{{ __('site.club_other') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerClubsOther" name="brewerClubsOther" type="text"
                               value="{{ old('brewerClubsOther') }}">
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="brewerAHA" class="col-md-3 col-form-label">{{ __('site.aha_number') }}</label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerAHA" name="brewerAHA" type="text" pattern="[A-Za-z0-9]+"
                               value="{{ old('brewerAHA') }}">
                    </div>
                </div>
                @if ((int) $ctx->prefsStr('prefsMHPDisplay') === 1)
                    <div class="mb-4 row">
                        <label for="brewerMHP" class="col-md-3 col-form-label">{{ __('site.mhp_number') }}</label>
                        <div class="col-md-9">
                            <input class="form-control" id="brewerMHP" name="brewerMHP" type="text" pattern="\d*"
                                   value="{{ old('brewerMHP') }}">
                        </div>
                    </div>
                @endif
                <div class="mb-4 row">
                    <label for="brewerProAm" class="col-md-3 col-form-label">{{ __('site.pro_am') }}</label>
                    <div class="col-md-9">
                        <select class="form-select" id="brewerProAm" name="brewerProAm">
                            <option value="0" {{ old('brewerProAm', '0') === '0' ? 'selected' : '' }}>No</option>
                            <option value="1" {{ old('brewerProAm') === '1' ? 'selected' : '' }}>Yes</option>
                            <option value="2" {{ old('brewerProAm') === '2' ? 'selected' : '' }}>Opt out</option>
                        </select>
                    </div>
                </div>
                @else
                    {{-- register.sec.php:383-390 — quick-view dummy contact
                         values, hidden so the quick form stays minimal. --}}
                    <input type="hidden" name="brewerAddress" value="1234 Main Street">
                    <input type="hidden" name="brewerCity" value="Anytown">
                    <input type="hidden" name="brewerState" value="CO">
                    <input type="hidden" name="brewerZip" value="80000">
                    <input type="hidden" name="brewerCountry" value="United States">
                    <input type="hidden" name="brewerPhone1" value="1234567890">
                @endunless

                {{-- register.sec.php:822-990 — judge/steward profile fields. The
                     store already persists these (brewerJudgeID, rank CSV); the
                     form supplied none of them, so every role rendered alike. --}}
                @if ($go === 'judge' || $go === 'steward')
                    <div class="mb-4 row">
                        <label for="brewerJudgeID" class="col-md-3 col-form-label">{{ __('site.bjcp_id') }}</label>
                        <div class="col-md-9">
                            <input class="form-control" id="brewerJudgeID" name="brewerJudgeID" type="text" maxlength="25"
                                   value="{{ old('brewerJudgeID') }}">
                        </div>
                    </div>
                @endif
                @if ($go === 'judge')
                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label">{{ __('site.bjcp_rank') }}</label>
                        <div class="col-md-9">
                            @foreach ([
                                'Non-BJCP', 'Rank Pending', 'Apprentice', 'Provisional', 'Recognized',
                                'Certified', 'National', 'Master', 'Honorary Master', 'Grand Master',
                                'Honorary Grand Master',
                            ] as $i => $rank)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="brewerJudgeRank[]"
                                           value="{{ $rank }}" id="rank_{{ $i }}"
                                           @checked(old('brewerJudgeRank.0', 'Non-BJCP') === $rank)>
                                    <label class="form-check-label" for="rank_{{ $i }}">{{ $rank }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (($go === 'judge' || $go === 'steward') && ! $adminRegister)
                    <div class="mb-4 row">
                        <label for="brewerJudgeWaiver" class="col-md-3 col-form-label">{{ __('site.waiver') }} *</label>
                        <div class="col-md-9 form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="brewerJudgeWaiver" name="brewerJudgeWaiver"
                                   value="Y" checked required>
                            <label class="form-check-label" for="brewerJudgeWaiver">{{ __('site.waiver_accept') }}</label>
                        </div>
                    </div>
                @endif

                @if ($turnstileEnabled ?? false)
                    <div class="mb-4 row">
                        <div class="col-md-9 offset-md-3">
                            <x-turnstile-widget theme="auto" />
                            @error('cf-turnstile-response')
                                <p class="text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                <div class="row mb-4">
                    <div class="col-md-9 offset-md-3">
                        <button type="submit" class="btn btn-lg btn-primary">{{ __('site.register') }}</button>
                    </div>
                </div>
            </form>
        @endif
    </section>

    <script>
        // Live username availability + email format checks (P3.7), replacing
        // legacy checkAvailability()/AjaxFunction(). CSRF-protected POSTs.
        (function () {
            var input = document.getElementById('user_name');
            var csrf = document.querySelector('input[name="_token"]');
            if (! input || ! csrf) return;

            var token = csrf.value;

            function check(url, body, target) {
                var params = new URLSearchParams(body);
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token },
                    body: params.toString(),
                }).then(function (r) { return r.json(); }).then(function (d) {
                    document.getElementById(target).innerHTML = d.message || '';
                }).catch(function () {});
            }

            input.addEventListener('blur', function () {
                check('{{ url('/ajax/username') }}', { user_name: input.value }, 'username-status');
            });
            input.addEventListener('change', function () {
                check('{{ url('/ajax/valid-email') }}', { email: input.value }, 'msg_email');
            });
        })();
    </script>
</x-public-layout>
