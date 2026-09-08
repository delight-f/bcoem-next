{{-- Legacy pub/login.pub.php: the page itself carries only the heading,
     rule, and messages — the email/password form lives in the shell's
     #login-modal (opened by the nav Log In button). Failed logins redirect
     here with errors and re-open the modal. --}}
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
            {{-- Legacy leaves the page bare; re-opening the modal keeps the
                 failed-login flow usable without a dead end. --}}
            <script>document.getElementById('login-modal')?.showModal();</script>
        @endif

        <p class="text-xl font-light">
            {{ __('site.forgot_password') }}
            <button type="button" class="link text-primary" data-open-modal="forgot-modal">{{ __('site.reset_password') }}</button>
        </p>
    </section>
</x-public-layout>
