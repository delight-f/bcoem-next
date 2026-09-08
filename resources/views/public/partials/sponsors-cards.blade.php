{{-- sponsors.pub.php: landing sponsors cards. Enabled sponsors only
     (sponsorEnable=1); card grid, logo when prefsSponsorLogos=Y with
     no_image.png fallback, optional location + text, visit/no-website
     button from sponsorURL. --}}
<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4 justify-content-center mt-4">
    @foreach ($sponsors as $sponsor)
        @if ($sponsor->sponsorEnable == '1')
            <div class="col">
                <div class="card h-100 sponsor-card-bg reveal-element">
                    <div class="card-body">
                        <header class="sponsor-header">{{ $sponsor->sponsorName }}</header>
                        @if (! empty($sponsor->sponsorLocation))
                            <div class="fs-6 text-secondary">{{ $sponsor->sponsorLocation }}</div>
                        @endif
                        @if ($logos)
                            <div class="d-flex align-content-center flex-wrap">
                                <img style="max-height: 200px" class="img-fluid rounded mx-auto mt-4 d-block" src="{{ $logoFor($sponsor) }}" border="0" alt="{{ $sponsor->sponsorName }}" title="{{ $sponsor->sponsorName }}">
                            </div>
                        @endif
                    </div>
                    <div class="card-footer" style="border:none; background-color: inherit;">
                        <p class="glance-card-text mt-2 lh-sm small align-middle"><small>{{ $sponsor->sponsorText }}</small></p>
                        @if ($sponsor->sponsorURL != '')
                            <div class="d-grid mb-2"><a href="{{ $sponsor->sponsorURL }}" class="btn btn-dark" target="_blank" rel="noopener">{{ __('site.visit') }}</a></div>
                        @else
                            <div class="d-grid mb-2"><a href="{{ $sponsor->sponsorURL }}" class="btn btn-outline-dark disabled">{{ __('site.no_website') }}</a></div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endforeach
</div>
