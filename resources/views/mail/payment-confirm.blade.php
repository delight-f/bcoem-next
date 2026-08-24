<x-mail::message>
# {{ $contestName }}

{{ __('mail.pay_greeting', ['name' => $firstName]) }}

{{ __('mail.pay_body') }}

- **{{ __('mail.label_entries', ['count' => count($entries)]) }}:** {{ implode(', ', $entries) }}
- **{{ __('mail.label_amount') }}:** {{ $amount }} {{ $currency }}

{{ __('mail.pay_luck') }}

{{ __('mail.no_reply') }}
</x-mail::message>
