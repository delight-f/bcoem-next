{{-- Legacy ?section=volunteers (sections/volunteers.sec.php). Faithful to
     the legacy branches: judges/stewards blurb gated by the judge window and
     auth state, the staff block gated by registration-open, and the
     contestVolunteers markdown body. --}}
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation"
    :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible" :with-sidebar="true">
    <section id="volunteers" class="landing-page-section pb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.volunteers') }}</h1></header>

        <h2>{{ __('site.judges_and_stewards') }}</h2>
        @if ($judgeOpen && ! auth()->check())
            <p>{!! __('site.volunteer_judge_blurb') !!}
                @unless ($registrationClosed)
                    {!! __('site.volunteer_not_registered') !!}
                @endunless
            </p>
        @elseif ($judgeOpen && auth()->check())
            <p>{!! __('site.volunteer_logged_in', ['link' => url('/list')]) !!}</p>
        @else
            <p>{{ __('site.volunteer_register_on') }} {{ $judgeOpenWhen ?? '(not set)' }}.</p>
        @endif

        {{-- volunteers.sec.php: registration_open < 2 (before or open) --}}
        @unless ($registrationClosed)
            <h2>{{ __('site.staff') }}</h2>
            <p>{{ __('site.volunteer_staff_nudge') }}</p>
            @if ($staffLocations !== [])
                <p>{{ __('site.volunteer_staff_sessions') }}</p>
                <ul>
                    @foreach ($staffLocations as $loc)
                        <li>{{ $loc['name'] }} &ndash; {{ $loc['when'] }}</li>
                    @endforeach
                </ul>
            @endif
        @endunless

        <h2>{{ __('site.other_volunteer_info') }}</h2>
        @php($body = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestVolunteers')))
        @if ($body !== '')
            {!! $body !!}
        @else
            <p>{{ __('site.volunteer_coming_soon') }}</p>
        @endif
    </section>
</x-public-layout>
