@php
    // C1-03: the four Competition Info text areas render as their own headed
    // sections beside the Competition Rules block. contestBottles stays
    // visible while drop-off/shipping is open or judging has not started;
    // contestBOSAward/contestAwards whenever set; contestCircuit whenever set
    // (this whole surface already sits behind the public-entries gate).
    $judgingNotStarted = $windows->judgingState(time(), $ctx->contestEpoch('contestAwardsLocDate')) === 0;
    $acceptanceRules = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestBottles'));
    $bosAward = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestBOSAward'));
    $awardsStructure = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestAwards'));
    $circuitQualifying = \App\Support\Tenant\ContestRules::renderText($ctx->contestStr('contestCircuit'));
    $dropOffLocations = $dropOffLocations ?? collect();
@endphp
<section id="rules" class="landing-page-section pb-4">
    <header class="landing-page-section-header py-2"><h1>{{ __('site.rules') }}</h1></header>
    <div class="reveal-element">
        <h2>
            {{ __('site.registration') }}
            <span class="text-success">{{ $windows->registration === \App\Support\Tenant\WindowState::Open ? __('site.state_open') : '' }}</span>
        </h2>
        <p>
            {{ __('site.window_opens') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationOpen'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short') ?? __('site.not_set') }}.
            {{ __('site.window_closes') }}
            {{ \App\Support\Tenant\DateFmt::dateTime($ctx->contestEpoch('contestRegistrationDeadline'), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), $longDates ? 'long' : 'short') ?? __('site.not_set') }}.
        </p>
    </div>
    <div class="reveal-element">
        <h2>{{ __('site.comp_rules') }}</h2>
        {!! \App\Support\Tenant\ContestRules::renderCompetitionRules($ctx->contestStr('contestRules')) !!}
    </div>
    @if ($acceptanceRules !== '' && ($windows->dropoff === \App\Support\Tenant\WindowState::Open || $windows->shipping === \App\Support\Tenant\WindowState::Open || $judgingNotStarted))
        <div class="reveal-element">
            <h2>Entry Acceptance Rules</h2>
            {!! $acceptanceRules !!}
        </div>
    @endif
    @if ($dropOffLocations->isNotEmpty() && ($windows->dropoff === \App\Support\Tenant\WindowState::Open || $judgingNotStarted))
        <div class="reveal-element">
            <h2>Drop-Off Locations</h2>
            @foreach ($dropOffLocations as $location)
                @php
                    $dropOffAddress = rtrim((string) $location->dropLocation, '&amp;KeepThis=true');
                    $mapQuery = str_replace(' ', '+', $dropOffAddress);
                @endphp
                <p>
                    @if ($location->dropLocationWebsite !== null && preg_match('#^https?://#i', (string) $location->dropLocationWebsite))
                        <a class="hide-loader" href="{{ $location->dropLocationWebsite }}" target="_blank" rel="noopener" title="{{ $location->dropLocationName }} website"><strong>{{ $location->dropLocationName }}</strong></a><span class="fa fa-sm fa-external-link ms-2"></span>
                    @else
                        <strong>{{ $location->dropLocationName }}</strong>
                    @endif
                    @if ($dropOffAddress !== '')
                        <br>{{ $dropOffAddress }}
                        <a class="hide-loader" href="http://maps.google.com/maps?f=q&source=s_q&hl=en&q={{ $mapQuery }}" target="_blank" rel="noopener" title="Map to {{ $location->dropLocationName }}"><span class="fa fa-lg fa-map-marker ms-2"></span></a>
                        <a class="hide-loader ms-2" href="http://maps.google.com/maps?f=d&source=s_d&hl=en&daddr={{ $mapQuery }}" target="_blank" rel="noopener">Driving Directions</a>
                    @endif
                    @if (! empty($location->dropLocationPhone))<br>{{ $location->dropLocationPhone }}@endif
                    @if (! empty($location->dropLocationNotes))<br><em>{{ $location->dropLocationNotes }}</em>@endif
                </p>
            @endforeach
        </div>
    @endif
    @if ($bosAward !== '')
        <div class="reveal-element">
            <h2>Best of Show</h2>
            {!! $bosAward !!}
        </div>
    @endif
    @if ($awardsStructure !== '')
        <div class="reveal-element">
            <h2>Awards Structure</h2>
            {!! $awardsStructure !!}
        </div>
    @endif
    @if ($circuitQualifying !== '')
        <div class="reveal-element">
            <h2>Circuit Qualifying Events</h2>
            {!! $circuitQualifying !!}
        </div>
    @endif
</section>
