{{-- Legacy pub/list.pub.php account surface body: alerts, brewer
     info + button stack, glance sidebar, entries section. Shared by
     /list (public/account) and /pay (public/pay) — index.pub.php renders
     the same list.pub.php block for both sections. --}}
    @php($msg = (int) request('msg'))
    @if ($msg === 5)
        <p class="alert alert-error print:hidden">{{ __('site.deleted_ok') }}</p>
    @elseif ($msg === 2)
        <p class="alert alert-success print:hidden">{{ __('site.updated_ok') }}</p>
    @elseif ($msg === 7)
        <p class="alert alert-success print:hidden">{{ __('site.registration_complete') }}</p>
    @elseif ($msg === 1)
        <p class="alert alert-success print:hidden"><strong>{{ __('site.info_added') }}</strong></p>
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
                <a class="btn btn-dark" href="{{ url('/user/password') }}"><i class="fa fa-key me-2"></i>{{ __('site.change_password') }}</a>
                @if ((int) $ctx->prefsStr('prefsEval') === 1 && ($info['brewer']->brewerJudge ?? '') === 'Y' && ! $judgingStarted)
                    <a class="btn btn-primary" href="{{ url('/eval') }}"><i class="fa fa-gavel me-2"></i>{{ __('site.judging_dashboard') }}</a>
                @endif
            </div>
            @include('public.partials.glance', ['cards' => $glance, 'stacked' => true])
        </div>
    </div>

    <section id="entries" class="pb-4">
        <h2>{{ __('site.entries') }}</h2>
        {{-- pub/brewer_entries.pub.php: empty-state line + the two info
             cards render whenever the entry window has opened; the table
             itself only renders once entries exist. --}}
        @if ($windows->entry !== App\Support\Tenant\WindowState::Before)
            @if (count($rows) === 0)
                <p>{{ __('site.no_entries') }}</p>
            @endif
            @include('public.partials.entries-info', ['info' => $entryInfo])
        @endif
        @include('public.partials.entries-table', ['rows' => $rows])
    </section>
