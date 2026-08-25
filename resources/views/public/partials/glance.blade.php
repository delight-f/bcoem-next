<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-3 g-4 justify-content-center mt-1 d-print-none">
    @foreach ($cards as $card)
        <div class="col">
            <div class="card h-100 glance-card-bg">
                <div class="card-body glance-card-body">
                    <h5 class="card-title pt-2 pb-2 glance-header">{{ $card['title'] }}</h5>
                    <div class="position-absolute top-0 start-50 translate-middle badge bg-{{ $card['color'] }}-glance-pill dark rounded-pill glance-status-pill">
                        @if ($card['color'] === 'success')<i class="fa fa-circle-check pe-2"></i>@elseif ($card['color'] === 'danger')<i class="fa fa-circle-exclamation pe-2"></i>@else<i class="fa fa-circle-info pe-2"></i>@endif
                        {{ $card['pill'] }}
                    </div>
                </div>
                <div class="card-body pt-0 glance-card-body">
                    {{-- Legacy at-a-glance.pub.php: one CTA per card when the
                        window is open; empty link renders the disabled variant. --}}
                    @if (! empty($card['button']['text']))
                        <div class="d-grid">
                            <a href="{{ $card['button']['link'] }}" class="btn btn-{{ $card['buttonColor'] }} {{ $card['button']['link'] === '' ? 'disabled' : '' }}">{{ $card['button']['text'] }}</a>
                        </div>
                    @endif
                    <p class="card-text glance-card-text"><small>{!! $card['body'] !!}</small></p>
                </div>
            </div>
        </div>
    @endforeach
</div>
