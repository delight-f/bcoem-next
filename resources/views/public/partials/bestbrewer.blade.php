<div class="mt-4 reveal-element">
    <h2>{{ $ctx->prefsStr('prefsBestBrewerTitle') ?: __('site.best_brewer') }}</h2>
    <ol>
        @foreach ($rows as $row)
            <li>{{ $row->name }}@if($row->club) — {{ $row->club }}@endif ({{ \Illuminate\Support\Number::format($row->points, 2, locale: 'en') }} {{ __('site.points') }})</li>
        @endforeach
    </ol>
</div>
