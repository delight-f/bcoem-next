{{-- Legacy pay.pub.php (ticket 15): unpaid entries, batch fee total, and
     the Pay button routed through the active gateway. Alert codes follow
     alerts.pub.php: 13 = payment completed (success), 14 = cancelled
     (danger). States: disabled ($disable_pay), paid_limit
     ($comp_paid_entry_limit), settled (nothing owed / free comp),
     unavailable (no gateway configured), payable. --}}
<x-public-layout :ctx="$ctx" :salutation="__('site.pay')" :judging-started="false" :future-judging-sessions="$windows->futureJudgingSessions">
    @php($msg = (int) request('msg'))
    @if ($msg === 13)
        <p class="alert alert-success print:hidden">{{ __('site.payment_received') }}</p>
    @elseif ($msg === 14)
        <p class="alert alert-error print:hidden">{{ __('site.payment_cancelled') }}</p>
    @endif

    <section id="pay-fees" class="pb-4">
        <h2>{{ __('site.pay') }}</h2>

        @if ($state === 'disabled')
            <p>{{ $firstName }}, {{ __('site.pay_disabled') }}
                <a href="#contact">{{ __('site.contact_officials') }}</a></p>
        @elseif ($state === 'paid_limit')
            <p>{{ __('site.pay_paid_limit') }} <a href="#contact">{{ __('site.contact_officials') }}</a></p>
        @elseif ($state === 'settled')
            <p><span class="fa fa-lg fa-check-circle text-success-emphasis me-1"></span>
                {{ __('site.pay_fees_marked_paid') }}</p>
        @else
            <p class="text-xl font-light">
                <small>{{ __('site.pay_fees_are') }} <strong>{{ number_format((float) $fee, 2) }}</strong> {{ __('site.pay_per_entry') }}.</small>
            </p>
            <p class="text-xl font-light">
                <small>{{ __('site.pay_total_due') }} <strong>{{ number_format((float) $total, 2) }}</strong>.</small>
            </p>

            <p class="text-xl font-light"><small>{{ __('site.pay_unpaid_intro') }} {{ count($unpaid) }} {{ __('site.pay_unpaid_entries') }}:</small></p>
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
                <form method="post" action="{{ route('pay.checkout') }}" class="print:hidden">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('site.pay_pay_button') }}</button>
                </form>
            @endif
        @endif
    </section>
</x-public-layout>
