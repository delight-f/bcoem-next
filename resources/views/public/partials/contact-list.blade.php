{{-- Officials list for prefsContact = N (legacy contact.sec.php). The email
     address is never printed: each row links to a signed, throttle-limited
     redirect that opens the visitor's mail program, so address harvesters
     scraping the HTML find nothing to collect (issue #54). --}}
<p>{{ __('site.contact_intro') }}</p>
<ul>
    @foreach ($contacts as $contact)
        <li>
            {{ $contact->contactFirstName }} {{ $contact->contactLastName }}@if ((string) $contact->contactPosition !== '') &ndash; {{ $contact->contactPosition }}@endif
            &ndash; <a href="{{ URL::signedRoute('contact.email', ['contact' => $contact->id]) }}">{{ __('site.contact_email_link') }}</a>
        </li>
    @endforeach
</ul>
