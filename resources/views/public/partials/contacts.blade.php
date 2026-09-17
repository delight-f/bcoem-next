@php($mode = $ctx->prefsStr('prefsContact'))
@php($contacts = \Illuminate\Support\Facades\DB::table('contacts')->orderBy('id')->get())

{{-- Landing #contact section: driven by the same prefsContact gate as the
     standalone /contact page (issue #54 — mode Y rendered the officials list
     here, never the form, so "Enable Contact Form" had no visible effect). --}}
@if ($mode === 'X')
    <p>{{ __('site.contacts_disabled') }}</p>
    @if ($ctx->contestStr('contestHostWebsite'))
        <p><a href="{{ $ctx->contestStr('contestHostWebsite') }}" target="_blank" rel="noopener">{{ __('site.website') }}</a></p>
    @endif
@elseif ($contacts->isEmpty())
    <p>{{ __('site.no_contacts') }}</p>
@elseif ($mode === 'Y')
    <p>{{ __('site.contact_use_form') }}</p>
    @include('public.partials.contact-form', ['contacts' => $contacts])
@else
    @include('public.partials.contact-list', ['contacts' => $contacts])
@endif
