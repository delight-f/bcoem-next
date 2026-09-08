<x-mail::message>
# {{ $contestName }}

{{ __('mail.reset_greeting', ['name' => $name]) }}

{{ __('mail.reset_body_1', ['contest' => $contestName]) }}

{{ __('mail.reset_body_2') }}

<x-mail::button :url="$resetUrl">
    {{ __('mail.reset_button') }}
</x-mail::button>

{{ __('mail.reset_fallback') }} {{ $resetUrl }}

{{ __('mail.reset_sig') }}
</x-mail::message>
