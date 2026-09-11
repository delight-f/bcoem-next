@php($stacked = $stacked ?? false)
@php($reveal = $reveal ?? false)
{{-- The landing deck (.glance-deck) is width-constrained and centres any
     incomplete final row, so 3, 5 and 7 cards all read well instead of
     leaving a ragged hole. The stacked sidebar variant keeps full width. --}}
<div class="row {{ $stacked ? 'row-cols-1 gy-3' : 'row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 justify-content-center glance-deck' }} mt-4 d-print-none">
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
                    <div class="d-flex align-items-start justify-content-between gap-2">
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
