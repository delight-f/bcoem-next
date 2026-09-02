{{-- index.pub.php:440-448: landing sponsors section. --}}
@if ($sponsorsVisible)
    <section id="sponsors" class="landing-page-section pb-3 d-print-none">
        <header class="landing-page-section-header py-2">
            <h1>{{ __('site.sponsors') }}</h1>
        </header>
        @include('public.partials.sponsors-cards', [
            'sponsors' => $sponsors,
            'logos' => $logos,
            'logoFor' => $logoFor,
        ])
    </section>
@endif
