{{-- In-site error page (P3 Slice 5, PARITY-022). Legacy renders HTTP
     errors as numeric public sections (index.pub.php:113-121): the contest
     chrome + a salutation line "<strong>{code} Error.</strong> {text}".
     Laravel's default error views are bare pages; this view renders the
     public layout with the legacy error salutation. Text per
     lang/en/en-US.lang.php:2186-2190. --}}
@php
    // That chrome reads contest rows, so an unreachable database used to fail
    // the error render as well — a bare 500 exactly when the error page is
    // most needed. Degrade to a minimal page instead of failing.
    // ponytail: only this load is guarded; the layout's mods query can still
    // fail if the database drops mid-render. Add a guard there if ever seen.
    try {
        $ctx = \App\Support\Tenant\TenantContext::load();
    } catch (\Throwable) {
        $ctx = null;
    }
    $messages = [
        400 => 'Invalid request.',
        401 => 'Permission is needed for your request.',
        403 => 'Action forbidden.',
        404 => 'Page not found.',
        500 => 'Server misconfiguration.',
    ];
    $message = $messages[$status ?? 404] ?? 'Something went wrong.';
    $salutation = '<p class="landing-page-salutation"><strong>'
        .e((string) ($status ?? 404)).' '.__('site.error_label').'.</strong> '
        .'<small class="text-secondary">'.e($message).'</small></p>';
@endphp
@if ($ctx === null)
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{{ $status ?? 404 }} {{ __('site.error_label') }}</title>
        </head>
        <body>
            <h1>{{ $status ?? 404 }} {{ __('site.error_label') }}.</h1>
            <p>{{ $message }}</p>
        </body>
    </html>
@else
    <x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation">
        <section class="landing-page-section py-4">
            <p class="lead">{{ $message }}</p>
        </section>
    </x-public-layout>
@endif
