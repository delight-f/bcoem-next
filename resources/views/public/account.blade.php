{{-- Legacy list.pub.php account surface (ticket 08): brewer info block
     (left), at-a-glance sidebar (right), entries table below. Section
     alert codes follow alerts.pub.php's `list` branch: 5 = deleted
     (danger), 2 = edited (success). --}}
<x-public-layout :ctx="$ctx" :salutation="__('site.my_account')" :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions">
    @php($msg = (int) request('msg'))
    @if ($msg === 5)
        <p class="alert alert-danger d-print-none">{{ __('site.deleted_ok') }}</p>
    @elseif ($msg === 2)
        <p class="alert alert-success d-print-none">{{ __('site.updated_ok') }}</p>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-8 col-lg-9">
            {{-- BrewerForm2 owns this partial; include tolerates its landing order. --}}
            @includeIf('brewer.info', ['brewer' => $info['brewer'], 'email' => $info['email'], 'updated' => $info['updated']])
        </div>
        <div class="col-12 col-md-4 col-lg-3">
            @include('public.partials.glance', ['cards' => $glance])
        </div>
    </div>

    <section id="entries" class="pb-3">
        <h2>{{ __('site.entries') }}</h2>
        @include('public.partials.entries-table', ['rows' => $rows])
    </section>
</x-public-layout>
