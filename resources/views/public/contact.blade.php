{{-- Legacy ?section=contact (sections/contact.sec.php). Faithful to the
     legacy prefsContact gate: X = disabled + website link; N = officials
     list; Y = the message form (posts to /contact, legacy
     process.inc.php?dbTable=contacts&action=email). The form submission is
     validated + CSRF-protected; legacy's captcha/anti-spam layer is not
     ported (ponytail: ContactMail docblock). --}}
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation"
    :judging-started="$judgingStarted" :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible">
    <section id="contact" class="landing-page-section pb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.contact') }}</h1></header>

        @if (session('contactSent'))
            <p>{!! __('site.contact_sent') !!} <a href="{{ url('/contact') }}">{{ __('site.contact_send_another') }}</a></p>
        @endif

        @if ($mode === 'X')
            <p>{{ __('site.contacts_disabled') }}</p>
            @if ($ctx->contestStr('contestHostWebsite'))
                <p><a class="hide-loader" href="{{ $ctx->contestStr('contestHostWebsite') }}" target="_blank" rel="noopener">{{ __('site.website') }}</a></p>
            @endif
        @elseif ($mode === 'N' || $mode === 'Y')
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
                    <div class="alert alert-error mb-4">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <form method="post" action="{{ route('contact.store') }}" class="needs-validation" novalidate>
                    @csrf
                    <label class="floating-label w-full mb-4">
                        <select class="select select-bordered w-full" name="to" required>
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->id }}" @selected(old('to') == $contact->id)>
                                    {{ $contact->contactFirstName }} {{ $contact->contactLastName }} &ndash; {{ $contact->contactPosition }}
                                </option>
                            @endforeach
                        </select>
                        <span>{{ __('site.contact_to') }}</span>
                    </label>
                    <label class="floating-label w-full mb-4">
                        <input class="input input-bordered input-lg w-full" name="from_name" type="text"
                               value="{{ old('from_name') }}" required>
                        <span>{{ __('site.name') }}</span>
                    </label>
                    <label class="floating-label w-full mb-4">
                        <input class="input input-bordered input-lg w-full" name="from_email" type="email"
                               value="{{ old('from_email') }}" required>
                        <span>{{ __('site.email_address') }}</span>
                    </label>
                    <label class="floating-label w-full mb-4">
                        <input class="input input-bordered input-lg w-full" name="subject" type="text"
                               value="{{ old('subject') }}" required>
                        <span>{{ __('site.contact_subject') }}</span>
                    </label>
                    <label class="floating-label w-full mb-4">
                        <textarea class="textarea textarea-bordered w-full" name="message" rows="6" required>{{ old('message') }}</textarea>
                        <span>{{ __('site.contact_message') }}</span>
                    </label>
                    <div class="alert alert-warning">{{ __('site.contact_form_required') }}</div>
                    <button type="submit" class="btn btn-primary">{{ __('site.contact_send_message') }}</button>
                </form>
            @endif
        @endif
    </section>
</x-public-layout>
