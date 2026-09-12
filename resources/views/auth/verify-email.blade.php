{{-- Email verification notice (opt-in, Task 4). Reached only when the
     `verified` middleware is active and the user has not clicked the link. --}}
<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="verify-email" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2">
            <h1>{{ $ctx->contestStr('contestName') }} - {{ __('site.verify_email') }}</h1>
        </header>

        <p class="fs-5 fw-light">{{ __('site.verify_email_sent') }}</p>

        @if (session('status') === 'verification-link-sent')
            <div class="alert alert-success">{{ __('site.verify_email_resent') }}</div>
        @endif

        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('site.verify_email_resend') }}</button>
        </form>
    </section>
</x-public-layout>
