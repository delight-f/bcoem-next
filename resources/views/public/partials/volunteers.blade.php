<section id="volunteers" class="landing-page-section pb-3">
    <header class="landing-page-section-header py-2"><h1>{{ __('site.volunteers') }}</h1></header>
    <h2>{{ __('site.judges_and_stewards') }}</h2>
    <p>{{ __('site.volunteer_judge_blurb') }}</p>
    <h2>{{ __('site.other_volunteer_info') }}</h2>
    @php($body = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestVolunteers')))
    @if ($body !== '')
        {!! $body !!}
    @else
        <p>{{ __('site.volunteer_coming_soon') }}</p>
    @endif
</section>
