<x-public-layout :ctx="$ctx" :show-hero="false">
    <section id="past-winners" class="landing-page-section pb-4">
        @include('public.partials.results', ['suffix' => $suffix])
    </section>
</x-public-layout>
