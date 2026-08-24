<x-public-layout :ctx="$ctx" :show-hero="true" :hero-image="$heroImage ?? null" :salutation="$salutation" :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions" :sponsors-visible="$sponsorsVisible">
    @php($style = $longDates ? 'long' : 'short')

    {{-- Legacy landing composition (index.pub.php): print-only heading, then
         the at-a-glance section (blurb / results / cards per state), the
         rules + entry-info sections while future sessions remain, volunteers
         before judging starts, then sponsors + contact. --}}

    <div class="d-none d-print-block landing-page-section p-3">
        <h1>{{ $ctx->contestStr('contestName') }}</h1>
    </div>

    <section id="at-a-glance" class="landing-page-section pb-3">
        {{-- judge_closed.pub.php: shown once registration/entry are closed
             and no future judging session remains (any winner-display state). --}}
        @if ($blurbCounts !== null)
            <p class="lead mt-3">{{ __('site.salutation_thanks') }} {{ $ctx->contestStr('contestName') }}.</p>
            <p class="lead"><small>{{ __('site.there_were') }} <strong class="text-success">{{ $blurbCounts['received'] }}</strong> {{ __('site.entries_judged') }} {{ __('site.and') }} <strong class="text-success">{{ $blurbCounts['participants'] }}</strong> {{ __('site.registered_participants') }}.</small></p>
        @endif

        @includeWhen($resultsVisible, 'public.partials.results', [
            'suffix' => $resultsSuffix ?? null,
        ])

        @includeWhen($cardsVisible, 'public.partials.glance', ['cards' => $glance])
    </section>

    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.rules-section')
    @includeWhen($windows->futureJudgingSessions > 0, 'public.partials.entry-info-section')

    @includeUnless($judgingStarted, 'public.partials.volunteers')

    <section id="contact" class="landing-page-section pb-3 d-print-none">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>
        @include('public.partials.contacts')
    </section>

</x-public-layout>
