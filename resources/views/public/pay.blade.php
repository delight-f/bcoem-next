{{-- Legacy section=pay: index.pub.php renders the SAME list.pub.php
     account block as section=list (brewer info, buttons, glance, entries)
     plus $pay_modal (the PayPal "Return to Merchant" confirmation modal,
     echoed for both list and pay) and — when payments are enabled — the
     pay.pub.php fee section below. The port keeps its own gateway flow
     inside #pay-fees; alert codes follow alerts.pub.php: 13 = payment
     completed (success), 14 = cancelled (danger). --}}
@php($band = '<h1 class="fw-bold">'.e($ctx->contestStr('contestName')).'</h1>'
    .'<p class="landing-page-salutation"><small>'.__('site.welcome').' '.e($firstName).'!</small></p>')
<x-public-layout :ctx="$ctx" :salutation="$band" :judging-started="false" :future-judging-sessions="$windows->futureJudgingSessions">
    @include('public.partials.account-main')

    @php($msg = (int) request('msg'))
    @if ($msg === 13)
        <p class="alert alert-success d-print-none">{{ __('site.payment_received') }}</p>
    @elseif ($msg === 14)
        <p class="alert alert-danger d-print-none">{{ __('site.payment_cancelled') }}</p>
    @endif

    <section id="pay-fees" class="pb-4">
        <h2>{{ __('site.pay') }}</h2>

        @if ($state === 'disabled')
            <p>{{ $firstName }}, {{ __('site.pay_disabled') }}
                <a href="#contact">{{ __('site.contact_officials') }}</a></p>
        @elseif ($state === 'paid_limit')
            <p>{{ __('site.pay_paid_limit') }} <a href="#contact">{{ __('site.contact_officials') }}</a></p>
        @elseif ($state === 'settled')
            <p class="lead"><small><span class="me-1 fa fa-fw fa-lg fa-check-circle text-success-emphasis"></span>
                {{ __('site.pay_total_entry_fees') }} <strong>{{ $ctx->currencySymbol() }}{{ number_format((float) $total, 2) }}</strong>.</small></p>
            <p class="lead"><small><span class="me-1 fa fa-fw fa-lg fa-check-circle text-success-emphasis"></span>
                {{ __('site.pay_fees_marked_paid') }}</small></p>
        @else
            <p class="fs-5 fw-light">
                {{-- pay.pub.php:80 — symbol prepended: {symbol}{amount} per entry --}}
                <small>{{ __('site.pay_fees_are') }} <strong>{{ $ctx->currencySymbol() }}{{ number_format((float) $fee, 2) }}</strong> {{ __('site.pay_per_entry') }}.</small>
            </p>
            <p class="fs-5 fw-light">
                <small>{{ __('site.pay_total_due') }} <strong>{{ number_format((float) $total, 2) }}</strong>.</small>
            </p>

            <p class="fs-5 fw-light"><small>{{ __('site.pay_unpaid_intro') }} {{ count($unpaid) }} {{ __('site.pay_unpaid_entries') }}:</small></p>
            <ul class="ms-12 list-unstyled">
                @foreach ($unpaid as $entry)
                    <li class="mb-1">{{ __('site.entry') }} #{{ str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT) }}:
                        <strong>{{ $entry->brewName }}</strong>
                        <small class="ms-2 text-muted"><em>{{ $entry->brewCategory.$entry->brewSubCategory }} &ndash; {{ $entry->brewStyle }}</em></small>
                    </li>
                @endforeach
            </ul>

            @if ($state === 'unavailable')
                <p>{{ __('site.pay_unavailable') }} <a href="#contact">{{ __('site.contact_officials') }}</a></p>
            @else
                <form method="post" action="{{ route('pay.checkout') }}" class="d-print-none">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('site.pay_pay_button') }}</button>
                </form>
            @endif
        @endif
    </section>

    {{-- Legacy $pay_modal (index.pub.php) was a PayPal-specific
         confirmation dialog; Stripe Checkout needs no leave-site coaching
         (payments plan W3). --}}
</x-public-layout>
