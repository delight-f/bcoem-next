<x-mail::message>
# {{ $contestName }}

{{ __('mail.reg_greeting', ['name' => $firstName]) }}

{{ __('mail.reg_body') }}

@foreach ($rows as $row)
- **{{ $row['label'] }}:** {{ $row['value'] }}
@endforeach

{{ __('mail.reg_footer') }} [{{ __('mail.reg_footer_link') }}]({{ route('login') }}) {{ __('mail.reg_footer_after') }}

{{ __('mail.no_reply') }}
</x-mail::message>
