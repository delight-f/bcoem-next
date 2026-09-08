{{-- In-site error page (P3 Slice 5, PARITY-022). Legacy renders HTTP
     errors as numeric public sections (index.pub.php:113-121): the contest
     chrome + a salutation line "<strong>{code} Error.</strong> {text}".
     Laravel's default error views are bare pages; this view renders the
     public layout with the legacy error salutation. Text per
     lang/en/en-US.lang.php:2186-2190. --}}
@php
    $ctx = \App\Support\Tenant\TenantContext::load();
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
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation">
    <section class="landing-page-section py-4">
        <p class="lead">{{ $message }}</p>
    </section>
</x-public-layout>
