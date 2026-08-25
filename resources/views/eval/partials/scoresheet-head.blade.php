{{-- scoresheet_head.eval.php port: identity header shared by the
     scoresheet form and its printable output. --}}
<div class="card mb-6">
    <div class="card-body">
        <h2 class="h5 mb-1">{{ $entry->brewName }}</h2>
        <p class="mb-0 text-sm">
            Entry #{{ $entry->id }} &middot;
            Style: {{ $entry->brewCategorySort }}{{ $entry->brewSubCategory }} {{ $entry->brewStyle }}
            @if ($style ?? null)
                &middot; {{ $style->brewStyle }}
            @endif
        </p>
        @if (! empty($entry->brewSpecialIngredients))
            <p class="mb-0 text-sm"><strong>Special ingredients:</strong> {{ $entry->brewSpecialIngredients }}</p>
        @endif
    </div>
</div>
