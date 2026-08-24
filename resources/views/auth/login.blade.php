<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="login" class="landing-page-section mt-4 mb-3">
        <h1>{{ __('site.log_in') }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('login.store') }}" class="needs-validation" novalidate>
            @csrf
            <div class="form-floating mb-3">
                <input class="form-control form-control-lg" id="login-user-name" type="email" name="loginUsername"
                       placeholder="{{ __('site.email') }}" value="{{ old('loginUsername') }}" required autofocus>
                <label for="login-user-name">{{ __('site.email') }}</label>
            </div>
            <div class="form-floating mb-3">
                <input class="form-control form-control-lg" id="login-password" type="password" name="loginPassword"
                       placeholder="{{ __('site.password') }}" required>
                <label for="login-password">{{ __('site.password') }}</label>
            </div>
            <div class="d-grid gap-2 mx-auto mb-4">
                <button id="login-button" class="btn btn-lg btn-success" type="submit">
                    {{ __('site.log_in') }}<i class="fas fa-sign-in-alt ps-2"></i>
                </button>
            </div>
        </form>

        <p class="lead">
            {{ __('site.forgot_password') }}
            <a href="{{ route('login') }}?go=password&amp;action=forgot">{{ __('site.reset_password') }}</a>.
        </p>
    </section>
</x-public-layout>
