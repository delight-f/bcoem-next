@php($stacked = $stacked ?? false)
@php($reveal = $reveal ?? false)
@php($count = count($cards))
@php($lgColumns = $count > 0 && $count % 4 === 0 ? 4 : 3)
{{-- The landing deck (.glance-deck) is width-constrained and centres any
     incomplete final row, so 3, 5 and 7 cards all read well instead of
     leaving a ragged hole. The stacked sidebar variant keeps full width.
     A deck whose count divides by four takes four columns: on a fixed 3-wide
     grid a 4-card deck wrapped to 3 + 1 and the lone card sat centred under
     the others, which reads as a mistake rather than a layout. --}}
<div class="row {{ $stacked ? 'row-cols-1 gy-3' : 'row-cols-1 row-cols-md-2 row-cols-lg-'.$lgColumns.' g-4 justify-content-center glance-deck' }} mt-4 d-print-none">
    @foreach ($cards as $card)
        @php($accent = $card['accent'] ?? 'blue')
        @php($icon = $card['icon'] ?? 'circle-info')
        @php($iconClass = $icon === 'sync-spin' ? 'fa fa-sync fa-spin' : 'fa fa-'.$icon)
        @php($buttons = $card['buttons'] ?? (empty($card['button']['text']) ? [] : [$card['button'] + ['color' => $card['buttonColor'] ?? $accent]]))
        <div class="col">
            {{-- Domain accent carries the card's identity; the pill carries its
                 state (open / not yet open / closed). --}}
            <div class="card h-100 glance-card glance-card--{{ $accent }} {{ $reveal ? 'reveal-element' : '' }}">
                <div class="card-body glance-card-body d-flex flex-column">
                    {{-- No flex-wrap here: wrapping dropped the status pill onto
                         its own line for whichever cards had the longer title,
                         so the pills and the bodies beneath them staggered
                         across the deck. The title shrinks and wraps instead,
                         keeping every pill on the header's first line at the
                         right edge (see .glance-card-head in app.css). --}}
                    <div class="glance-card-head d-flex align-items-start justify-content-between gap-2">
                        <h5 class="card-title glance-header glance-header--{{ $accent }} mb-0">{{ $card['title'] }}</h5>
                        <span class="glance-status-pill glance-status-pill--{{ $card['color'] }}"><i class="{{ $iconClass }} me-1"></i>{{ $card['pill'] }}</span>
                    </div>
                    <div class="glance-card-text mt-2 flex-grow-1">{!! $card['body'] !!}</div>
                    @if (! empty($buttons))
                        <div class="d-grid gap-2 mt-3">
                            @foreach ($buttons as $button)
                                @if (($button['link'] ?? '') !== '')
                                    <a href="{{ $button['link'] }}" class="btn btn-sm btn-{{ $button['color'] ?? $accent }}">{{ $button['text'] }}</a>
                                @else
                                    {{-- Gate-locked CTA: an inert span, not an anchor with a dead
                                         href that reloads the page. --}}
                                    <span class="btn btn-sm btn-{{ $button['color'] ?? $accent }} disabled" aria-disabled="true">{{ $button['text'] }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
