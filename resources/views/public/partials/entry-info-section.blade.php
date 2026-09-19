<section id="entry-info" class="landing-page-section pb-4">
    <header class="landing-page-section-header py-2"><h1>{{ __('site.entry_info') }}</h1></header>
    <div class="reveal-element">
        <p>
            {{ __('site.window_opens') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestEntryOpen'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short', $ctx->showTimezone()) ?? __('site.not_set') }}.
            {{ __('site.window_closes') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestEntryDeadline'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short', $ctx->showTimezone()) ?? __('site.not_set') }}.
        </p>
    </div>
</section>
