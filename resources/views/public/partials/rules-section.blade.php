<section id="rules" class="landing-page-section pb-3">
    <header class="landing-page-section-header py-2"><h1>{{ __('site.rules') }}</h1></header>
    <div class="reveal-element">
        <h2>
            {{ __('site.registration') }}
            <span class="text-success">{{ $windows->registration === \App\Support\Tenant\WindowState::Open ? __('site.state_open') : '' }}</span>
        </h2>
        <p>
            {{ __('site.window_opens') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationOpen'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short') ?? __('site.not_set') }}.
            {{ __('site.window_closes') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationDeadline'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short') ?? __('site.not_set') }}.
        </p>
    </div>
    <div class="reveal-element">
        <h2>{{ __('site.comp_rules') }}</h2>
        {!! \App\Support\Tenant\ContestRules::renderCompetitionRules($ctx->contestStr('contestRules')) !!}
    </div>
</section>
