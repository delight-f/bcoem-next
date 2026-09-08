{{-- Brewer info block (pub/brewer_info.pub.php): thank-you lead + full
     account-info row set + judge/steward/staff availability tables.
     Contract: @include('brewer.info', ['brewer' => row-or-null,
     'email' => users.user_name, 'updated' => formatted datetime|null])
     plus the infoData() payload keys. --}}
<section id="account-info" class="mb-6">
    <h2>{{ __('site.account_info') }}</h2>
    @if ($brewer === null)
        <p class="fs-5 fw-light">{{ __('site.no_profile_yet') }}</p>
    @else
        <p class="lead">
            {{ __('site.thanks_for_participating') }} {{ App\Support\Tenant\TenantContext::load()->contestStr('contestName') }}, {{ $brewer->brewerFirstName }}.
            <small class="text-muted">{{ __('site.account_last_updated') }} {{ $updated ?? '—' }}.</small>
        </p>

        @php($row = function (string $label, $value) {
            echo '<div class="row bcoem-account-info"><div class="col-12 col-md-4"><strong>'.$label.'</strong></div><div class="col-12 col-md-8">'.$value.'</div></div>';
        })
        @php($yesNo = fn (string $v) => $v === 'Y' ? __('site.yes') : __('site.no'))

        {{-- pub/brewer_info.pub.php: the Contact/Organization label prefixes
             exist only in the pro-edition brewery branch; amateur edition
             renders bare labels. --}}
        @php($row(__('site.name'), e($brewer->brewerFirstName.' '.$brewer->brewerLastName)))
        @php($row(__('site.email_address'), e($email)))
        @php($phone = e($brewer->brewerPhone1 ?: __('site.none_entered')).($brewer->brewerPhone2 ? '<br>'.e($brewer->brewerPhone2) : ''))
        @php($row(__('site.phone'), $phone))
        @php($row(__('site.address'), e($address)))
        @php($row(__('site.city'), e($city)))
        @php($row(__('site.state'), e($state)))
        @php($row(__('site.zip'), e($zip)))
        @php($row(__('site.country'), e($country)))
        @if ($mhpDisplay)
            @php($row('<strong>'.__('site.mhp_number').'</strong> <span class="badge" style="color: #F2D06C; background-color: #000;">MHP</span>', '<a class="hide-loader" href="https://www.masterhomebrewerprogram.com" target="_blank" title="'.__('site.mhp_note').'">'.e($mhp).'</a>'))
        @endif
        @php($row(__('site.aha_number'), '<a class="hide-loader" href="http://www.homebrewersassociation.org/membership/join-or-renew/" target="_blank" title="'.__('site.aha_note').'">'.e($aha).'</a>'))
        @if ($country === 'United States')
            @php($row(__('site.pro_am'), $yesNo($proAm)))
        @endif
        @php($dropoffCell = e($dropoffName ?? __('site.none_entered')))
        @if ((int) $brewer->brewerDropOff === 0)
            @php($dropoffCell .= '<br><a class="hide-loader" href="'.url('/admin/output/shipping_label').'" title="'.__('site.shipping_labels_note').'">'.__('site.print_shipping_labels').'</a>')
        @endif
        @php($row(__('site.entry_delivery'), $dropoffCell))
        @php($row(__('site.club'), e($club)))

        @if ($brewer->brewerJudge === 'Y' || $brewer->brewerSteward === 'Y')
            <hr>
            @php($row(__('site.bjcp_id'), $judgeId !== '' && $judgeId !== '0' ? e($judgeId) : 'N/A'))
            @php($row(__('site.waiver'), $waiver !== '' ? $yesNo($waiver) : __('site.none_entered')))
        @endif

        @if ($judgeNotes !== '' && ($brewer->brewerJudge === 'Y' || $brewer->brewerSteward === 'Y' || $brewer->brewerStaff === 'Y'))
            @php($row(__('site.notes'), '<em>'.e($judgeNotes).'</em>'))
        @endif

        @if ($brewer->brewerJudge === 'Y')
            <hr>
            @php($row(__('site.judge'), $yesNo($brewer->brewerJudge).' <a href="'.url('/list/edit-judging').'" class="btn btn-dark btn-sm ms-2 d-print-none" style="--bs-btn-padding-y: .2rem; --bs-btn-padding-x: .4rem; --bs-btn-font-size: .75rem;">'.explode(' ', __('site.change_email'))[0].'</a>'))
            @php($row('BJCP '.__('site.bjcp_mead'), $yesNo($judgeMead)))
            @php($row('BJCP '.__('site.bjcp_cider'), $yesNo($judgeCider)))
            @php($row(__('site.designations'), e($designations)))
            @php($row(__('site.brewing_partners'), e($affiliations ?? __('site.none'))))
            @php($row(__('site.judge_comps'), e($judgeExp !== '' ? $judgeExp : __('site.none_entered'))))
            @php($row(__('site.judge_preferred'), e($judgeLikes)))
            @php($row(__('site.judge_non_preferred'), e($judgeDislikes)))
            @if ($judgeAvailability !== [])
                @if (collect($judgeAvailability)->every(fn ($r) => ! $r['available']))
                    <p class="alert alert-warning d-print-none"><i class="fa fa-exclamation-triangle"></i> {!! __('site.no_judge_availability') !!}</p>
                @endif
                @php($row(__('site.avail'), ''))
                <div class="row bcoem-account-info d-print-none">
                    <div class="col-12 col-md-8 offset-md-4">
                        <table class="table table-sm table-striped table-bordered border-dark-subtle">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 14%">{{ __('site.yes') }}/{{ __('site.no') }}</th>
                                    <th style="width: 43%">{{ __('site.session') }}</th>
                                    <th style="width: 43%">{{ __('site.date') }}</th>
                                    <th style="width: 43%">{{ __('site.notes') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($judgeAvailability as $r)
                                <tr>
                                    <td>{{ $r['available'] ? __('site.yes') : __('site.no') }}</td>
                                    <td>{{ $r['name'] }}</td>
                                    <td>{{ $r['date'] }}</td>
                                    <td>{{ $r['type'] === 1 ? $r['location'].' '.$r['notes'] : $r['notes'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif

        @if ($brewer->brewerSteward === 'Y')
            <hr>
            @php($row(__('site.stewarding'), $yesNo($brewer->brewerSteward).' <a href="'.url('/list/edit-judging').'" class="btn btn-dark btn-sm ms-2 d-print-none" style="--bs-btn-padding-y: .2rem; --bs-btn-padding-x: .4rem; --bs-btn-font-size: .75rem;">'.explode(' ', __('site.change_email'))[0].'</a>'))
            @if ($stewardAvailability !== [])
                @php($row(__('site.avail'), ''))
                <div class="row bcoem-account-info d-print-none">
                    <div class="col-12 col-md-8 offset-md-4">
                        <table class="table table-sm table-striped table-bordered border-dark-subtle">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 10%">{{ __('site.yes') }}/{{ __('site.no') }}</th>
                                    <th style="width: 30%">{{ __('site.session') }}</th>
                                    <th style="width: 25%">{{ __('site.date') }}</th>
                                    <th style="width: 35%">{{ __('site.notes') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($stewardAvailability as $r)
                                <tr>
                                    <td>{{ $r['available'] ? __('site.yes') : __('site.no') }}</td>
                                    <td>{{ $r['name'] }}</td>
                                    <td>{{ $r['date'] }}</td>
                                    <td>{{ $r['notes'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif

        @if ($brewer->brewerStaff === 'Y' && $staffAvailability !== [])
            <hr>
            @php($row(__('site.staff'), $yesNo($brewer->brewerStaff)))
            @php($row(__('site.avail'), ''))
            <div class="row bcoem-account-info d-print-none">
                <div class="col-12 col-md-8 offset-md-4">
                    <table class="table table-sm table-striped table-bordered border-dark-subtle">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 14%">{{ __('site.yes') }}/{{ __('site.no') }}</th>
                                <th style="width: 43%">{{ __('site.session') }}</th>
                                <th style="width: 43%">{{ __('site.date') }}</th>
                                <th style="width: 43%">{{ __('site.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($staffAvailability as $r)
                            <tr>
                                <td>{{ $r['available'] ? __('site.yes') : __('site.no') }}</td>
                                <td>{{ $r['name'] }}</td>
                                <td>{{ $r['date'] }}</td>
                                <td>{{ $r['notes'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</section>
