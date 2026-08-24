{{-- Brewer info block (legacy brewer_info.pub.php): post-registration
     "thank you / next steps" lead + contact + volunteer summary.
     Contract: @include('brewer.info', ['brewer' => row-or-null,
     'email' => users.user_name, 'updated' => formatted datetime|null]). --}}
<section id="account-info" class="mb-4">
    @if ($brewer === null)
        <p class="lead">{{ __('site.no_profile_yet') }}</p>
    @else
        <p class="lead">
            {{ __('site.thanks_for_participating') }} {{ App\Support\Tenant\TenantContext::load()->contestStr('contestName') }}, {{ $brewer->brewerFirstName }}.
            <small class="text-muted">{{ __('site.account_last_updated') }} {{ $updated ?? '—' }}.</small>
        </p>

        <div class="row bcoem-account-info">
            <div class="col-12 col-md-4"><strong>{{ __('site.name') }}</strong></div>
            <div class="col-12 col-md-8">{{ $brewer->brewerFirstName }} {{ $brewer->brewerLastName }}</div>
        </div>
        <div class="row bcoem-account-info">
            <div class="col-12 col-md-4"><strong>{{ __('site.email') }}</strong></div>
            <div class="col-12 col-md-8">{{ $email }}</div>
        </div>
        <div class="row bcoem-account-info">
            <div class="col-12 col-md-4"><strong>{{ __('site.phone') }}</strong></div>
            <div class="col-12 col-md-8">{{ $brewer->brewerPhone1 ?: __('site.none_entered') }}</div>
        </div>
        <div class="row bcoem-account-info">
            <div class="col-12 col-md-4"><strong>{{ __('site.judge') }}</strong></div>
            <div class="col-12 col-md-8">{{ $brewer->brewerJudge === 'Y' ? __('site.yes') : __('site.no') }}
                @if ($brewer->brewerJudge === 'Y')
                    &mdash; {{ $brewer->brewerJudgeRank ?: __('site.rank_non_bjcp') }}
                @endif
            </div>
        </div>
        <div class="row bcoem-account-info">
            <div class="col-12 col-md-4"><strong>{{ __('site.stewarding') }}</strong></div>
            <div class="col-12 col-md-8">{{ $brewer->brewerSteward === 'Y' ? __('site.yes') : __('site.no') }}</div>
        </div>

        <a href="{{ url('/list/edit-account') }}" class="btn btn-outline-primary mt-2">{{ __('site.edit_account') }}</a>
        <a href="{{ url('/list/edit-judging') }}" class="btn btn-outline-primary mt-2">{{ __('site.edit_judging_prefs') }}</a>
    @endif
</section>
