{{-- Legacy pub/list.pub.php account surface body: alerts, brewer
     info + button stack, glance sidebar, entries section. Shared by
     /list (public/account) and /pay (public/pay) — index.pub.php renders
     the same list.pub.php block for both sections. --}}
    @php($msg = (int) request('msg'))
    @if ($msg === 5)
        <p class="alert alert-danger d-print-none">{{ __('site.deleted_ok') }}</p>
    @elseif ($msg === 2)
        <p class="alert alert-success d-print-none">{{ __('site.updated_ok') }}</p>
    @elseif ($msg === 7)
        <p class="alert alert-success d-print-none">{{ __('site.registration_complete') }}</p>
    @elseif ($msg === 1)
        <p class="alert alert-success d-print-none"><strong>{{ __('site.info_added') }}</strong></p>
    @elseif ($msg === 12)
        <p class="alert alert-warning d-print-none">{{ __('site.entry_limit_style_reached') }}</p>
    @elseif ($msg === 13)
        <p class="alert alert-warning d-print-none">{{ __('site.entry_labels_unpaid') }}</p>
    @endif

    <a name="my-account"></a>
    <div class="row g-3 mb-6">
        <div class="col-12 col-md-8 col-lg-9">
            {{-- BrewerForm2 owns this partial; include tolerates its landing order. --}}
            @includeIf('brewer.info', $info)
        </div>
        <div class="col-12 col-md-4 col-lg-3 d-print-none">
            {{-- pub/list.pub.php $user_edit_links button stack --}}
            <div class="d-grid gap-2 mb-5">
                @if (count($rows) > 0)
                    <a class="btn btn-primary" href="#entries"><i class="fa fa-list me-2"></i>{{ __('site.entries') }}</a>
                @endif
                @if ($addEntryShow)
                    <a class="btn btn-primary" href="{{ url('/brew') }}"><i class="fa fa-plus-circle me-2"></i>{{ __('site.add_entry') }}</a>
                @endif
                {{-- Disabled when nothing is payable. The tooltip lives on a
                     wrapper: .btn.disabled sets pointer-events:none, so the
                     disabled anchor itself can never fire a hover. --}}
                <span class="d-grid" @if ($payDisabled) data-toggle="tooltip" data-bs-placement="top" title="No fees are payable." @endif>
                    <a class="btn btn-primary hide-loader {{ $payDisabled ? 'disabled' : '' }}" href="{{ url('/pay') }}" @if ($payDisabled) aria-disabled="true" tabindex="-1" @endif><i class="fa fa-lg fa-money-bill me-2"></i>{{ __('site.pay') }}</a>
                </span>
                <a class="btn btn-dark" href="{{ url('/list/edit-account') }}"><i class="fa fa-user me-2"></i>{{ __('site.edit_account') }}</a>
                <a class="btn btn-dark" href="{{ url('/user/username') }}"><i class="fa fa-envelope me-2"></i>{{ __('site.change_email') }}</a>
                <a class="btn btn-dark" href="{{ url('/user/password') }}"><i class="fa fa-key me-2"></i>{{ __('site.change_password') }}</a>
                @if ((int) $ctx->prefsStr('prefsEval') === 1 && ($info['brewer']->brewerJudge ?? '') === 'Y' && ! $judgingStarted)
                    <a class="btn btn-primary" href="{{ url('/eval') }}"><i class="fa fa-gavel me-2"></i>{{ __('site.judging_dashboard') }}</a>
                @endif
            </div>

            {{-- Legacy brewer_info.sec.php:445 — judge scoresheet-label row
                 (Avery 5160 / Avery 3422) renders when the viewer is a
                 judge. --}}
            @if (($info['brewer']->brewerJudge ?? '') === 'Y')
                <div class="bcoem-account-info d-print-none"><strong>&nbsp;</strong>
                    <div>Print your judging scoresheet labels
                        <a class="hide-loader" href="{{ route('outputs.labels', ['action' => 'judging_labels', 'go' => 'participants', 'id' => $info['brewer']->id, 'psort' => 5160]) }}" data-toggle="tooltip" title="Avery 5160">Letter</a>
                        <a class="hide-loader" href="{{ route('outputs.labels', ['action' => 'judging_labels', 'go' => 'participants', 'id' => $info['brewer']->id, 'psort' => 3422]) }}" data-toggle="tooltip" title="Avery 3422">A4</a>
                    </div>
                </div>
            @endif
            @include('public.partials.glance', ['cards' => $glance, 'stacked' => true])
        </div>
    </div>

    {{-- Legacy mobile-only sticky button stack (brewer_entries.pub.php):
         d-md-none grid; the Add Entry href always carries the brewer id. --}}
    <section class="mb-3 d-block d-sm-block d-md-none">
        <div class="d-grid gap-2 mb-5 d-print-none">
            <a class="btn btn-primary {{ $windows->entry === App\Support\Tenant\WindowState::Before ? 'disabled' : '' }}" href="{{ url('/brew?filter='.$info['brewer']->id) }}"><i class="fa fa-plus-circle me-2"></i>Add Entry</a>
            <span class="d-grid" @if ($payDisabled) data-toggle="tooltip" data-bs-placement="top" title="No fees are payable." @endif>
                <a class="btn btn-primary hide-loader {{ $payDisabled ? 'disabled' : '' }}" href="{{ url('/pay') }}#pay-fees" @if ($payDisabled) aria-disabled="true" tabindex="-1" @endif><i class="fa fa-lg fa-money-bill me-2"></i>Pay Entry Fees</a>
            </span>
            <a class="btn btn-dark" href="{{ url('/list/edit-account') }}"><i class="fa fa-user me-2"></i>Edit Account</a>
        </div>
    </section>

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
