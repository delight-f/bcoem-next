{{-- pub/brewer_entries.pub.php $page_info1: the two small info cards
     under the Entries heading (bottles/edit-deadline left; confirmed,
     unpaid, fees right). Rendered whenever the entry window has opened,
     with or without entries. --}}
@php($bottles = $info['bottles'])
<div class="row g-2 mb-3 d-print-none">
    <div class="col-12 col-lg-6">
        <div class="card h-100 bg-light-subtle border-secondary-subtle">
            <div class="card-body">
                <small><ul class="list-unstyled m-0 p-0">
                    @if ($bottles !== null && $bottles !== '')
                        <li><strong>{{ __('site.number_bottles_per_entry') }}:</strong> {{ $bottles }}</li>
                    @endif
                    <li><strong>{{ __('site.entry_edit_deadline') }}:</strong> {{ $info['editDeadline'] }}</li>
                </ul></small>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100 bg-light-subtle text-dark border-secondary-subtle">
            <div class="card-body">
                <small><ul class="list-unstyled m-0 p-0">
                    <li><strong>{{ __('site.confirmed_entries') }}:</strong> {{ $info['confirmed'] }}</li>
                    @if ($info['unconfirmed'] > 0)
                        <li class="text-danger"><strong>{{ __('site.unconfirmed_entries') }}:</strong> {{ $info['unconfirmed'] }}<i class="fa fa-exclamation-circle ms-1"></i></li>
                    @endif
                    <li><strong>{{ __('site.unpaid_confirmed_entries') }}:</strong> {{ $info['unpaidConfirmed'] }}</li>
                    <li><strong>{{ __('site.entry_fees_to_pay') }}:</strong> {{ $info['currency'] }}{{ number_format($info['feesToPay'], 2) }}</li>
                </ul></small>
            </div>
        </div>
    </div>
</div>
