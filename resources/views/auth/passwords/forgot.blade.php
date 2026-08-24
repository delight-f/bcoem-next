<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="forgot-password" class="landing-page-section mt-4 mb-3">
        <h1>{{ $ctx->contestStr("contestName") }} - {{ __("reset.forgot_password_heading") }}</h1>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('password.forgot') }}" class="needs-validation" novalidate>
            @csrf
            <div class="form-floating mb-3">
                <input class="form-control form-control-lg" id="forgot-user-name" name="email" type="email"
                       placeholder="{{ __('site.email') }}" value="{{ old('email') }}" required autofocus>
                <label for="forgot-user-name">{{ __('site.email') }}</label>
            </div>
            <div class="d-grid gap-2 mx-auto mb-4">
                <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.submit') }}</button>
            </div>
        </form>

        <p><a href="{{ route('login') }}">{{ __('reset.back_to_login') }}</a></p>
    </section>
</x-public-layout>
