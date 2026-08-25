<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="login" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2"><h1>{{ $ctx->contestStr("contestName") }} - {{ __("site.log_in") }}</h1></header>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('login.store') }}" class="needs-validation" novalidate>
            @csrf
            <label class="floating-label w-full mb-4">
                <input class="input input-bordered input-lg w-full" id="login-user-name" type="email" name="loginUsername"
                       placeholder="{{ __('site.email') }}" value="{{ old('loginUsername') }}" required autofocus>
                <span>{{ __('site.email') }}</span>
            </label>
            <label class="floating-label w-full mb-4">
                <input class="input input-bordered input-lg w-full" id="login-password" type="password" name="loginPassword"
                       placeholder="{{ __('site.password') }}" required>
                <span>{{ __('site.password') }}</span>
            </label>
            <div class="grid gap-2 mx-auto mb-6">
                <button id="login-button" class="btn btn-lg btn-success" type="submit">
                    {{ __('site.log_in') }}<i class="fas fa-sign-in-alt ps-2"></i>
                </button>
            </div>
        </form>

        <p class="text-xl font-light">
            {{ __('site.forgot_password') }}
            <a href="{{ route('login') }}?go=password&amp;action=forgot">{{ __('site.reset_password') }}</a>
        </p>
    </section>
</x-public-layout>
