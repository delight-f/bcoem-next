{{-- Legacy ?section=contact (sections/contact.sec.php). Faithful to the
     legacy prefsContact gate: X = disabled + website link; N = officials
     list; Y = the message form (posts to /contact, legacy
     process.inc.php?dbTable=contacts&action=email). The form submission is
     validated + CSRF-protected; legacy's captcha/anti-spam layer is not
     ported (ponytail: ContactMail docblock). --}}
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
                <p>{{ __('site.contact_intro') }}</p>
                <ul>
                    @foreach ($contacts as $contact)
                        <li>{{ $contact->contactFirstName }} {{ $contact->contactLastName }}, {{ $contact->contactPosition }}
                            &ndash; <a href="mailto:{{ $contact->contactEmail }}">{{ $contact->contactEmail }}</a></li>
                    @endforeach
                </ul>
            @else
                <p>{{ __('site.contact_use_form') }}</p>
                @if ($errors->any())
                    <div class="alert alert-danger mb-4">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <form method="post" action="{{ route('contact.store') }}" class="needs-validation" novalidate>
                    @csrf
                    <div class="form-floating mb-4">
                        <select class="form-select" name="to" required>
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->id }}" @selected(old('to') == $contact->id)>
                                    {{ $contact->contactFirstName }} {{ $contact->contactLastName }} &ndash; {{ $contact->contactPosition }}
                                </option>
                            @endforeach
                        </select>
                        <label for="to">{{ __('site.contact_to') }}</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input class="form-control" id="from_name" name="from_name" type="text"
                               value="{{ old('from_name') }}" required>
                        <label for="from_name">{{ __('site.name') }}</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input class="form-control" id="from_email" name="from_email" type="email"
                               value="{{ old('from_email') }}" required>
                        <label for="from_email">{{ __('site.email_address') }}</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input class="form-control" id="subject" name="subject" type="text"
                               value="{{ old('subject') }}" required>
                        <label for="subject">{{ __('site.contact_subject') }}</label>
                    </div>
                    <div class="form-floating mb-4">
                        <textarea class="form-control" id="message" name="message" rows="6" required>{{ old('message') }}</textarea>
                        <label for="message">{{ __('site.contact_message') }}</label>
                    </div>
                    <div class="alert alert-warning">{{ __('site.contact_form_required') }}</div>
                    <button type="submit" class="btn btn-primary">{{ __('site.contact_send_message') }}</button>
                </form>
            @endif
        @endif
    </section>
</x-public-layout>
