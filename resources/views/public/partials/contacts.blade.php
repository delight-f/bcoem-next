@php($mode = $ctx->prefsStr('prefsContact'))
@php($contacts = \Illuminate\Support\Facades\DB::table('contacts')->orderBy('id')->get())

@if ($mode === 'X')
    <p>{{ __('site.contacts_disabled') }}</p>
    @if ($ctx->contestStr('contestHostWebsite'))
        <p><a href="{{ $ctx->contestStr('contestHostWebsite') }}" target="_blank" rel="noopener">{{ __('site.website') }}</a></p>
    @endif
@elseif ($mode === 'N' || $mode === 'Y')
    @if ($contacts->isEmpty())
        <p>{{ __('site.no_contacts') }}</p>
    @else
        <p>{{ __('site.contact_intro') }}</p>
        <ul>
            @foreach ($contacts as $contact)
                <li>{{ $contact->contactFirstName }} {{ $contact->contactLastName }} &ndash; {{ $contact->contactPosition }}</li>
            @endforeach
        </ul>
    @endif
@endif
