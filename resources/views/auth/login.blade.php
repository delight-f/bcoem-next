{{-- Legacy pub/login.pub.php carries only the heading, rule and messages — the
     email/password form lives in the shell's #login-modal. That is a dead end
     for anyone who arrives here, and this is exactly where every
     authenticated-only screen sends a signed-out visitor: the page offered a
     password reset and no way to sign in. The form is now opened on arrival,
     with a visible Log In button for when the modal has been dismissed. --}}
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
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#login-modal">{{ __("site.log_in") }}</button>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#forgot-modal">{{ __("site.reset_password") }}</button>
        </div>

        <p class="fs-5 fw-light mb-1">{{ __('site.forgot_password') }}</p>

        <script>
            // The modal is part of the shell and appears earlier in the document
            // than this section, so it can be opened as soon as the page is
            // interactive. Guarded because the bundle may not have run yet; the
            // Log In button above is the path that always works.
            document.addEventListener('DOMContentLoaded', function () {
                var modal = document.getElementById('login-modal');
                if (modal && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            });
        </script>
    </section>
</x-public-layout>
