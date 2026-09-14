{{-- CSRF failure (419): a form submitted from a tab that outlived its session,
     or one still carrying the token from before a failed login rotated it.
     Laravel's default 419 page is a dead end — the visitor cannot know they
     must re-open the form to get a fresh token. Render the public shell
     instead: its @guest block carries the login modal, so the recovery is a
     single click. Patterned on errors/404.blade.php. --}}
@php
    // Same guard as the 404 view: the chrome reads contest rows, and the error
    // page must render even when the database does not answer.
    try {
        $ctx = \App\Support\Tenant\TenantContext::load();
    } catch (\Throwable) {
        $ctx = null;
    }
    $salutation = '<p class="landing-page-salutation"><strong>'
        .e((string) ($status ?? 419)).' '.__('site.error_label').'.</strong> '
        .'<small class="text-secondary">Your session has expired. Sign in again to continue.</small></p>';
@endphp
@if ($ctx === null)
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{{ $status ?? 419 }} {{ __('site.error_label') }}</title>
        </head>
        <body>
            <h1>{{ $status ?? 419 }} {{ __('site.error_label') }}.</h1>
            <p>Your session has expired. Go back and try again.</p>
        </body>
    </html>
@else
    <x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation">
        <section id="login" class="landing-page-section py-4">
            <p class="lead">Your session has expired. Sign in again to continue.</p>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#login-modal">{{ __('site.log_in') }}</button>
            </div>

            <script>
                // The modal is part of the shell and appears earlier in the
                // document than this section; open it as soon as the page is
                // interactive. Guarded because the bundle may not have run
                // yet; the Log In button above is the path that always works.
                document.addEventListener('DOMContentLoaded', function () {
                    var modal = document.getElementById('login-modal');
                    if (modal && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    }
                });
            </script>
        </section>
    </x-public-layout>
@endif
