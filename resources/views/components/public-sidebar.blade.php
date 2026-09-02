{{-- Public anon sidebar (legacy sections/sidebar.sec.php, PARITY-006).
     Panels in legacy emit order: Past Winners → Judging Locations →
     Non-Judging Locations → Account Registration (anon) → Entry Window →
     Drop-Off → Shipping. Used by the sidebar-layout section pages via
     public-layout's withSidebar prop. --}}
@php
    $sbCtx = \App\Support\Tenant\TenantContext::load();
    $sbWindows = \App\Support\Tenant\Windows::derive($sbCtx, time());
    $sbLoggedIn = auth()->check();

    // 600: Past Winners — archive suffixes with display flag + winner link.
    $sbArchives = \App\Support\Results\ResultsRepository::archives();
    $sbWinnerLink = (string) ($sbCtx->contestStr('contestWinnerLink') ?? '');

    // 400/700: judging + non-judging locations.
    $sbJudging = [];
    $sbNonJudging = [];
    foreach (\Illuminate\Support\Facades\DB::table('judging_locations')->orderBy('judgingDate')->orderBy('judgingLocName')->get() as $sbLoc) {
        if ((int) $sbLoc->judgingLocType === 2) {
            $sbNonJudging[] = $sbLoc;
        } else {
            $sbJudging[] = $sbLoc;
        }
    }

    $sbTz = $sbCtx->prefsStr('prefsTimeZone');
    $sbDf = $sbCtx->prefsStr('prefsDateFormat');
    $sbTf = $sbCtx->prefsStr('prefsTimeFormat');
    $sbShort = fn ($epoch): string => \App\Support\Tenant\DateFmt::dateTime($epoch, $sbTz, $sbDf, $sbTf, 'short') ?? '';

    // Window open/closed facts for the registration + entry panels.
    $sbRegOpen = $sbWindows->registration === \App\Support\Tenant\WindowState::Open;
    $sbRegState = match ($sbWindows->registration->value) {
        0 => 'before', 1 => 'open', 2 => 'after', default => 'default'
    };
    $sbEntryOpen = $sbWindows->entry === \App\Support\Tenant\WindowState::Open;
    $sbJudgeOpen = $sbWindows->judge === \App\Support\Tenant\WindowState::Open;
    $sbJudgeCap = $sbWindows->judgeCapReached;
    $sbStewardCap = $sbWindows->stewardCapReached;
@endphp
<div class="sidebar col col-lg-3 col-md-4 col-sm-12 col-xs-12">
    {{-- 600: Past Winners --}}
    @if ($sbArchives !== [] || $sbWinnerLink !== '')
        <div class="panel panel-info mb-3">
            <div class="panel-heading"><h4 class="panel-title m-0">{{ __('site.past_winners') }}</h4></div>
            <div class="panel-body">
                <ul class="list-unstyled m-0">
                    @foreach ($sbArchives as $sbArchive)
                        <li><i class="fa fa-fw fa-trophy"></i> <a href="{{ url('/past-winners/'.$sbArchive->archiveSuffix) }}">{{ $sbArchive->archiveSuffix }}</a></li>
                    @endforeach
                    @if ($sbWinnerLink !== '')
                        <li><i class="fa fa-fw fa-external-link-alt"></i> <a class="hide-loader" href="{{ $sbWinnerLink }}" target="_blank" rel="noopener">{{ __('site.view') }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
    @endif

    {{-- 400: Judging Locations --}}
    <div class="panel panel-info mb-3 d-print-none">
        <div class="panel-heading"><h4 class="panel-title m-0">{{ __('site.judging_locations') }}</h4></div>
        <div class="panel-body">
            @if ($sbJudging === [])
                <p>{{ __('site.no_judging_locations') }}</p>
            @else
                @foreach ($sbJudging as $sbLoc)
                    <p class="mb-2">
                        @if ($sbLoc->judgingLocName !== '')<strong>{{ $sbLoc->judgingLocName }}</strong>@endif
                        @if ((int) $sbLoc->judgingLocType === 0 && $sbLoc->judgingLocation !== '')
                            @if ($sbLoggedIn)
                                <a class="hide-loader" href="http://maps.google.com/maps?f=q&source=s_q&hl=en&q={{ str_replace(' ', '+', rtrim((string) $sbLoc->judgingLocation, '&amp;KeepThis=true')) }}" target="_blank" rel="noopener" title="Map to {{ $sbLoc->judgingLocName }}"> <span class="fa fa-lg fa-map-marker"></span></a>
                            @else
                                <a class="hide-loader" href="#" title="{{ __('site.login_for_map') }}"> <span class="fa fa-lg fa-map-marker"></span></a>
                            @endif
                        @endif
                        @if ($sbLoc->judgingDate)
                            <br>{{ $sbShort($sbLoc->judgingDate) }}
                            @if ($sbLoc->judgingDateEnd)
                                {{ __('site.and') }} {{ $sbShort($sbLoc->judgingDateEnd) }}
                            @endif
                        @endif
                        @if ((int) $sbLoc->judgingLocType === 1 && $sbLoc->judgingLocation !== '')
                            <br><small>{{ $sbLoc->judgingLocation }}</small>
                        @endif
                    </p>
                @endforeach
            @endif
        </div>
    </div>

    {{-- 700: Non-Judging Locations --}}
    @if ($sbNonJudging !== [])
        <div class="panel panel-info mb-3 d-print-none">
            <div class="panel-heading"><h4 class="panel-title m-0">{{ __('site.non_judging_locations') }}</h4></div>
            <div class="panel-body">
                @foreach ($sbNonJudging as $sbLoc)
                    <p class="mb-2">
                        @if ($sbLoc->judgingLocName !== '')<strong>{{ $sbLoc->judgingLocName }}</strong>@endif
                        @if ((int) $sbLoc->judgingLocType === 2 && $sbLoc->judgingLocation !== '')
                            @if ($sbLoggedIn)
                                <a class="hide-loader" href="http://maps.google.com/maps?f=q&source=s_q&hl=en&q={{ str_replace(' ', '+', rtrim((string) $sbLoc->judgingLocation, '&amp;KeepThis=true')) }}" target="_blank" rel="noopener" title="Map to {{ $sbLoc->judgingLocName }}"> <span class="fa fa-lg fa-map-marker"></span></a>
                            @else
                                <a class="hide-loader" href="#" title="{{ __('site.login_for_map') }}"> <span class="fa fa-lg fa-map-marker"></span></a>
                            @endif
                        @endif
                        @if ($sbLoc->judgingDate)<br>{{ $sbShort($sbLoc->judgingDate) }}@endif
                    </p>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 100: Account Registration (anon only) --}}
    @if (! $sbLoggedIn)
        @php
            $sbPanelClass = ($sbRegOpen || ($sbJudgeOpen && ! $sbJudgeCap)) || ($sbJudgeOpen && ! $sbStewardCap) ? 'panel-success' : (($sbWindows->registration === \App\Support\Tenant\WindowState::After && $sbWindows->judge === \App\Support\Tenant\WindowState::After) ? 'panel-danger' : 'panel-default');
        @endphp
        <div class="panel {{ $sbPanelClass }} mb-3">
            <div class="panel-heading">
                <h4 class="panel-title m-0">
                    {{ __('site.account_registration') }}
                    @if ($sbRegOpen) {{ __('site.open') }}
                    @elseif ($sbJudgeOpen && ! $sbJudgeCap && ! $sbStewardCap) {{ __('site.js_still_open') }}
                    @elseif ($sbJudgeOpen && $sbJudgeCap && ! $sbStewardCap) {{ __('site.steward_reg_open') }}
                    @elseif ($sbJudgeOpen && ! $sbJudgeCap && $sbStewardCap) {{ __('site.judge_reg_open') }}
                    @else {{ __('site.closed') }}
                    @endif
                </h4>
            </div>
            <div class="panel-body">
                <p class="mb-2">{{ __('site.account_reg_open') }} {{ $sbShort($sbCtx->contestEpoch('contestRegistrationOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestRegistrationDeadline')) }}.</p>
                @if (! $sbJudgeCap && ! $sbStewardCap)
                    <p class="mb-2">{{ __('site.js_reg_open') }} {{ $sbShort($sbCtx->contestEpoch('contestJudgeOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestJudgeDeadline')) }}.</p>
                @elseif ($sbJudgeCap && ! $sbStewardCap)
                    <p class="mb-2"><a href="{{ url('/register/steward') }}">{{ __('site.register_steward') }}</a> {{ $sbShort($sbCtx->contestEpoch('contestJudgeOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestJudgeDeadline')) }}.</p>
                @elseif (! $sbJudgeCap && $sbStewardCap)
                    <p class="mb-2"><a href="{{ url('/register/judge') }}">{{ __('site.register_judge') }}</a> {{ $sbShort($sbCtx->contestEpoch('contestJudgeOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestJudgeDeadline')) }}.</p>
                @endif
            </div>
        </div>
    @endif

    {{-- 200: Entry Window --}}
    @if (! $sbLoggedIn || true)
        <div class="panel {{ $sbEntryOpen && ! $sbWindows->compEntryLimitReached ? 'panel-success' : 'panel-danger' }} mb-3 d-print-none">
            <div class="panel-heading">
                <h4 class="panel-title m-0">
                    {{ __('site.entry_registration') }}
                    @if ($sbEntryOpen && ! $sbWindows->compEntryLimitReached && ! $sbWindows->compPaidEntryLimitReached) {{ __('site.open') }}
                    @else {{ __('site.closed') }}
                    @endif
                </h4>
            </div>
            <div class="panel-body">
                @if (! $sbWindows->compEntryLimitReached && ! $sbWindows->compPaidEntryLimitReached)
                    <p class="mb-2">{{ __('site.entry_window') }} {{ $sbShort($sbCtx->contestEpoch('contestEntryOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestEntryDeadline')) }}.</p>
                @endif
                @if ($sbWindows->compEntryLimitReached || $sbWindows->compPaidEntryLimitReached)
                    <span class="text-danger">
                        {{ $sbWindows->compEntryLimitReached ? __('site.entry_limit_reached') : __('site.paid_limit_reached') }}
                    </span>
                @endif
            </div>
        </div>
    @endif

    {{-- 300: Drop-Off --}}
    @if ((int) $sbCtx->prefsStr('prefsDropOff') === 1)
        <div class="panel {{ $sbWindows->dropoff === \App\Support\Tenant\WindowState::Open ? 'panel-success' : 'panel-danger' }} mb-3 d-print-none">
            <div class="panel-heading">
                <h4 class="panel-title m-0">
                    {{ __('site.entry_drop_off') }}
                    {{ $sbWindows->dropoff === \App\Support\Tenant\WindowState::Open ? __('site.open') : __('site.closed') }}
                </h4>
            </div>
            <div class="panel-body">
                <p class="mb-2">{{ __('site.dropoff_window') }} {{ $sbShort($sbCtx->contestEpoch('contestDropoffOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestDropoffDeadline')) }}.</p>
            </div>
        </div>
    @endif

    {{-- 500: Shipping --}}
    @if ((int) $sbCtx->prefsStr('prefsShipping') === 1)
        <div class="panel {{ $sbWindows->shipping === \App\Support\Tenant\WindowState::Open ? 'panel-success' : 'panel-danger' }} mb-3 d-print-none">
            <div class="panel-heading">
                <h4 class="panel-title m-0">
                    {{ __('site.entry_shipping') }}
                    {{ $sbWindows->shipping === \App\Support\Tenant\WindowState::Open ? __('site.open') : __('site.closed') }}
                </h4>
            </div>
            <div class="panel-body">
                <p class="mb-2">{{ __('site.shipping_window') }} {{ $sbShort($sbCtx->contestEpoch('contestShippingOpen')) }} {{ __('site.and') }} {{ $sbShort($sbCtx->contestEpoch('contestShippingDeadline')) }}.</p>
            </div>
        </div>
    @endif
</div>
