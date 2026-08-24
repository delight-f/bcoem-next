<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-3 g-4 justify-content-center mt-1 d-print-none">
    @foreach ($cards as $card)
        <div class="col">
            <div class="card h-100 glance-card-bg">
                <div class="card-body glance-card-body">
                    <h5 class="card-title pt-2 pb-2 glance-header">{{ $card['title'] }}</h5>
                    <div class="position-absolute top-0 start-50 translate-middle badge bg-secondary-glance-pill dark rounded-pill glance-status-pill">
                        {{ $card['pill'] }}
                    </div>
                    <p class="card-text glance-card-text"><small>{!! $card['body'] !!}</small></p>
                </div>
            </div>
        </div>
    @endforeach
</div>
