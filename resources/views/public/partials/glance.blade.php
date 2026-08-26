@php($stacked = $stacked ?? false)
<div class="row {{ $stacked ? 'row-cols-1 gy-3' : 'row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 justify-center' }} mt-1 print:hidden">
    @foreach ($cards as $card)
        <div class="col">
            <div class="card h-full glance-card-bg">
                <div class="card-body glance-card-body">
                    <h5 class="card-title pt-2 pb-2 glance-header text-{{ $card['color'] }}-glance-header">{{ $card['title'] }}</h5>
                    <div class="position-absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 badge bg-{{ $card['color'] }}-glance-pill rounded-full glance-status-pill">
                        @if ($card['color'] === 'success')<i class="fa fa-circle-check pe-2"></i>@elseif ($card['color'] === 'danger')<i class="fa fa-circle-exclamation pe-2"></i>@else<i class="fa fa-circle-info pe-2"></i>@endif
                        {{ $card['pill'] }}
                    </div>
                    <p class="card-text glance-card-text"><small>{!! $card['body'] !!}</small></p>
                    {{-- Legacy at-a-glance.pub.php: one CTA per card when the
                        window is open; empty link renders the disabled variant. --}}
                    @if (! empty($card['button']['text']))
                        <div class="grid">
                            <a href="{{ $card['button']['link'] }}" class="btn btn-{{ $card['buttonColor'] }} {{ $card['button']['link'] === '' ? 'btn-disabled' : '' }}">{{ $card['button']['text'] }}</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
