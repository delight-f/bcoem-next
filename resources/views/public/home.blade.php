<x-public-layout :ctx="$ctx" :show-hero="true" :hero-image="$heroImage ?? null">
    @php($style = $longDates ? 'long' : 'short')

    {{-- Legacy shows the login nudge after an account-gated redirect (msg=99
         travels as a query param there; array sessions cannot flash). --}}
    @if ((int) request('msg') === 99)
        <p class="alert alert-warning">{{ __('site.please_log_in') }}</p>
    @endif

    {{-- Landing page: salutation, at-a-glance (pre-reveal only), info
         sections, then the results block once judging is past. --}}

    <div class="d-none d-print-block landing-page-section p-3">
        <h1>{{ $ctx->contestStr('contestName') }}</h1>
    </div>

    <section id="identity">
        <p>{!! $salutation !!}</p>
    </section>

    @includeWhen(! $resultsVisible, 'public.partials.glance', ['cards' => $glance])

    @includeWhen($resultsVisible, 'public.partials.results', [
        'suffix' => $resultsSuffix ?? null,
        'salutationCounts' => $salutationCounts,
    ])

    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.rules-section')
    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.entry-info-section')

    @includeUnless($windows->firstJudgingDate !== null && time() > $windows->firstJudgingDate, 'public.partials.volunteers')

    <section id="contact" class="landing-page-section pb-3 d-print-none">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>
        @include('public.partials.contacts')
    </section>

</x-public-layout>
