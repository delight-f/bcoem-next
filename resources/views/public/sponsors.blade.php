{{-- Legacy ?section=sponsors (sections/sponsors.sec.php): standalone
     sponsors page. Grid of enabled sponsors — name linked to
     sponsorURL, location, logo (prefsSponsorLogos=Y, no_image.png
     fallback), text. --}}
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation"
    :judging-started="$judgingStarted" :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible" :with-sidebar="true">
    <section id="sponsors" class="landing-page-section pb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.sponsors') }}</h1></header>
        <div class="row">
            @foreach ($sponsors as $sponsor)
                @if ($sponsor->sponsorEnable == '1')
                    <div class="col-12 col-sm-6 col-md-6 col-lg-3 bcoem-sponsor-container">
                        <div class="bcoem-sponsor-name">
                            <h5>
                                @if ($sponsor->sponsorURL != '')
                                    <a class="hide-loader" href="{{ $sponsor->sponsorURL }}" title="{{ $sponsor->sponsorName }} website" target="_blank" rel="noopener">{{ $sponsor->sponsorName }}</a>
                                @else
                                    {{ $sponsor->sponsorName }}
                                @endif
                            </h5>
                        </div>
                        <div class="bcoem-sponsor-location">{{ ! empty($sponsor->sponsorLocation) ? $sponsor->sponsorLocation : '&nbsp;' }}</div>
                        @if ($logos)
                            <img class="img-fluid img-thumbnail" src="{{ $logoFor($sponsor) }}" border="0" alt="{{ $sponsor->sponsorName }}" title="{{ $sponsor->sponsorName }}">
                        @endif
                        @if ($sponsor->sponsorText != '')
                            <div class="bcoem-sponsor-text small">{{ $sponsor->sponsorText }}</div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </section>
</x-public-layout>
