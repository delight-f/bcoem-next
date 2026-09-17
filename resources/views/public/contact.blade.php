{{-- Legacy ?section=contact (sections/contact.sec.php). Faithful to the
     legacy prefsContact gate: X = disabled (renders nothing); N = officials
     list; Y = the message form (posts to /contact, legacy
     process.inc.php?dbTable=contacts&action=email). The form shares its
     partial with the landing #contact section (issue #54) and is validated,
     CSRF-protected, throttled and honeypot-guarded. --}}
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation"
    :judging-started="$judgingStarted" :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible" :with-sidebar="true">
    <section id="contact" class="landing-page-section pb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>

        @if (session('contactSent'))
            <p>{!! __('site.contact_sent') !!} <a href="{{ url('/contact') }}">{{ __('site.contact_send_another') }}</a></p>
        @endif

        @if ($mode === 'N' || $mode === 'Y')
            @if ($contacts->isEmpty())
                <p>{{ __('site.no_contacts') }}</p>
            @elseif ($mode === 'N')
                @include('public.partials.contact-list', ['contacts' => $contacts])
            @else
                <p>{{ __('site.contact_use_form') }}</p>
                @include('public.partials.contact-form', ['contacts' => $contacts])
            @endif
        @endif
    </section>
</x-public-layout>
