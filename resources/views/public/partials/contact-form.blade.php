{{-- Contact message form (legacy contact.sec.php mode=Y). Shared by the
     standalone /contact page and the landing #contact section so both surfaces
     behave identically. Posts to contact.store, which is CSRF-protected,
     throttle-limited and honeypot-guarded (spatie/laravel-honeypot) — the
     official's address is never printed, only posted as an id (issue #54). --}}
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
    <x-honeypot />
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
