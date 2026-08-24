<x-public-layout :ctx="$ctx" :show-hero="true" :hero-image="$heroImage ?? null">
    @php($style = $longDates ? 'long' : 'short')

    {{-- Landing page: at-a-glance cards, window/rules sections, contacts,
         then the results block once judging is past and revealed. --}}

    <section id="at-a-glance" class="landing-page-section pb-3">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.at_a_glance') }}</h1></header>
        @include('public.partials.glance', ['cards' => $glance])
    </section>

    <section id="rules" class="landing-page-section pb-3">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.rules') }}</h1></header>
        <div class="reveal-element">
            <h2>
                {{ __('site.registration') }}
                <span class="text-success">{{ $windows->registration === \App\Support\Tenant\WindowState::Open ? __('site.state_open') : '' }}</span>
            </h2>
            <p>
                {{ __('site.window_opens') }}
                {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationOpen'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $style) ?? __('site.not_set') }}.
                {{ __('site.window_closes') }}
                {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationDeadline'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $style) ?? __('site.not_set') }}.
            </p>
        </div>
        <div class="reveal-element">
            <h2>{{ __('site.comp_rules') }}</h2>
            {!! \App\Support\Tenant\ContestRules::renderCompetitionRules($ctx->contestStr('contestRules')) !!}
        </div>
    </section>

    <section id="entry-info" class="landing-page-section pb-3">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.entry_info') }}</h1></header>
        <div class="reveal-element">
            <p>
                {{ __('site.window_opens') }}
                {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestEntryOpen'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $style) ?? __('site.not_set') }}.
                {{ __('site.window_closes') }}
                {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestEntryDeadline'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $style) ?? __('site.not_set') }}.
            </p>
        </div>
    </section>

    <section id="contact" class="landing-page-section pb-3 d-print-none">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>
        @include('public.partials.contacts')
    </section>

    @includeWhen($resultsVisible, 'public.partials.results', ['suffix' => null])
</x-public-layout>
