<x-public-layout :ctx="$ctx" :show-hero="true" :hero-image="$heroImage ?? null" :salutation="$salutation" :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions" :sponsors-visible="$sponsorsVisible" :fyi-alerts="$fyiAlerts ?? []">
    @php($style = $longDates ? 'long' : 'short')

    {{-- Legacy landing composition (index.pub.php): print-only heading, then
         the at-a-glance section (blurb / results / cards per state), the
         rules + entry-info sections while future sessions remain, volunteers
         before judging starts, then sponsors + contact. --}}

    <section id="at-a-glance" class="landing-page-section pb-4">
        {{-- Deck header: shares .landing-page-section-header with every other
             landing section so the cards no longer start abruptly under the
             salutation band. Gated on $cardsVisible so it only titles the deck
             (not the results state) and matches the deck's d-print-none. --}}
        @if ($cardsVisible)
            <header class="landing-page-section-header py-2 d-print-none"><h1>{{ __('site.at_a_glance') }}</h1></header>
        @endif

        {{-- judge_closed.pub.php: shown once registration/entry are closed
             and no future judging session remains (any winner-display state). --}}
        @if ($blurbCounts !== null)
            <p class="fs-5 fw-light mt-4">{{ __('site.salutation_thanks') }} {{ $ctx->contestStr('contestName') }}.</p>
            <p class="fs-5 fw-light"><small>{{ __('site.there_were') }} <strong class="text-success">{{ $blurbCounts['received'] }}</strong> {{ __('site.entries_judged') }} {{ __('site.and') }} <strong class="text-success">{{ $blurbCounts['participants'] }}</strong> {{ __('site.registered_participants') }}.</small></p>
        @endif

        @includeWhen($resultsVisible, 'public.partials.results', [
            'suffix' => $resultsSuffix ?? null,
        ])

        @includeWhen($cardsVisible, 'public.partials.glance', ['cards' => $glance, 'reveal' => true])
    </section>

    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.rules-section')
    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.entry-info-section')

    @includeUnless($judgingStarted, 'public.partials.volunteers')

    @includeWhen($sponsorsVisible, 'public.partials.sponsors-landing')

    <section id="contact" class="landing-page-section pb-4 d-print-none">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>
        @include('public.partials.contacts')
    </section>

</x-public-layout>
