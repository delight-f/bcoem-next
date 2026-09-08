<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="forgot-password" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2"><h1>{{ $ctx->contestStr("contestName") }} - {{ __("reset.forgot_password_heading") }}</h1></header>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('password.forgot') }}" class="needs-validation" novalidate>
            @csrf
            <label class="floating-label w-full mb-4">
                <input class="input input-bordered input-lg w-full" id="forgot-user-name" name="email" type="email"
                       placeholder="{{ __('site.email') }}" value="{{ old('email') }}" required autofocus>
                <span>{{ __('site.email') }}</span>
            </label>
            <div class="grid gap-2 mx-auto mb-6">
                <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.submit') }}</button>
            </div>
        </form>

        <p><a href="{{ route('login') }}">{{ __('reset.back_to_login') }}</a></p>
    </section>
</x-public-layout>
