{{-- Legacy pub/list.pub.php account surface: brewer info block (left),
     button stack + at-a-glance sidebar (right), entries table below.
     Band = index.pub.php section==list branch: contest-name h1 + welcome.
     Section alert codes follow alerts.pub.php's `list` branch: 5 = deleted
     (danger), 2 = edited (success). --}}
@php($band = '<h1 class="fw-bold">'.e($ctx->contestStr('contestName')).'</h1>'
    .'<p class="landing-page-salutation"><small>'.__('site.welcome').' '.e($info['brewer']->brewerFirstName ?? '').'!</small></p>')
<x-public-layout :ctx="$ctx" :salutation="$band" :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions">
    @php($msg = (int) request('msg'))
    @if ($msg === 5)
        <p class="alert alert-error print:hidden">{{ __('site.deleted_ok') }}</p>
    @elseif ($msg === 2)
        <p class="alert alert-success print:hidden">{{ __('site.updated_ok') }}</p>
    @elseif ($msg === 7)
        <p class="alert alert-success print:hidden">{{ __('site.registration_complete') }}</p>
    @endif

    <a name="my-account"></a>
    <div class="row g-3 mb-6">
        <div class="col-12 col-md-8 col-lg-9">
            {{-- BrewerForm2 owns this partial; include tolerates its landing order. --}}
            @includeIf('brewer.info', $info)
        </div>
        <div class="col-12 col-md-4 col-lg-3 print:hidden">
            {{-- pub/list.pub.php $user_edit_links button stack --}}
            <div class="d-grid gap-2 mb-5">
                @if (count($rows) > 0)
                    <a class="btn btn-primary" href="#entries"><i class="fa fa-list me-2"></i>{{ __('site.entries') }}</a>
                @endif
                @if ($addEntryShow)
                    <a class="btn btn-primary" href="{{ url('/brew') }}"><i class="fa fa-plus-circle me-2"></i>{{ __('site.add_entry') }}</a>
                @endif
                <a class="btn btn-primary hide-loader {{ $payDisabled ? 'disabled' : '' }}" href="{{ url('/pay') }}"><i class="fa fa-lg fa-money-bill me-2"></i>{{ __('site.pay') }}</a>
                <a class="btn btn-dark" href="{{ url('/list/edit-account') }}"><i class="fa fa-user me-2"></i>{{ __('site.edit_account') }}</a>
                <a class="btn btn-dark" href="{{ url('/list/edit-account') }}"><i class="fa fa-envelope me-2"></i>{{ __('site.change_email') }}</a>
                @if ((int) $ctx->prefsStr('prefsEval') === 1 && ($info['brewer']->brewerJudge ?? '') === 'Y' && ! $judgingStarted)
                    <a class="btn btn-primary" href="{{ url('/eval') }}"><i class="fa fa-gavel me-2"></i>{{ __('site.judging_dashboard') }}</a>
                @endif
            </div>
            @include('public.partials.glance', ['cards' => $glance, 'stacked' => true])
        </div>
    </div>

    <section id="entries" class="pb-4">
        <h2>{{ __('site.entries') }}</h2>
        @include('public.partials.entries-table', ['rows' => $rows])
    </section>
</x-public-layout>
