<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="reset-password" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2"><h1>{{ $ctx->contestStr("contestName") }} - {{ __("reset.reset_password_heading") }}</h1></header>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($validity === 0)
            <form method="post" action="{{ route('password.reset') }}" class="needs-validation" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full" id="login-user-name" name="loginUsername" type="email"
                           placeholder="{{ __('site.email') }}" required autofocus>
                    <span>{{ __('site.email') }}</span>
                </label>
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full password-field" id="password-entry" name="newPassword1"
                           type="password" placeholder="{{ __('reset.new_password') }}" required>
                    <span>{{ __('reset.new_password') }}</span>
                </label>
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full password-field" id="password-confirm" name="newPassword2"
                           type="password" placeholder="{{ __('reset.confirm_new_password') }}" required>
                    <span>{{ __('reset.confirm_new_password') }}</span>
                </label>
                <div class="grid gap-2 mx-auto mb-6">
                    <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.reset_password_heading') }}</button>
                </div>
            </form>
        @else
            <div class="alert alert-error">{{ $validity === 2
                ? __('reset.reset_token_expired')
                : __('reset.reset_token_invalid') }}</div>
            <p><a href="{{ route('password.forgot') }}">{{ __('reset.forgot_password_heading') }}</a></p>
        @endif

        <p><a href="{{ route('login') }}">{{ __('reset.back_to_login') }}</a></p>
    </section>
</x-public-layout>
