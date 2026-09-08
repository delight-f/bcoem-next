{{-- Awards presentation (legacy awards.php). Standalone reveal.js deck,
     no site layout. Theme from ?view= (white/black/moon). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $contestName }} - Awards</title>
    <noscript><p style="text-align:center;padding:2em;">{{ $noscript }}</p></noscript>
    <link rel="stylesheet" href="{{ asset('vendor/reveal/reset.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/reveal/reveal.css') }}">
    @if ($theme === 'black')
        <link rel="stylesheet" href="{{ asset('vendor/reveal/theme/black.css') }}" id="theme">
    @elseif ($theme === 'moon')
        <link rel="stylesheet" href="{{ asset('vendor/reveal/theme/moon.css') }}" id="theme">
    @else
        <link rel="stylesheet" href="{{ asset('vendor/reveal/theme/white.css') }}" id="theme">
    @endif
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @vite(['resources/css/awards.css', 'resources/js/awards.js'])
</head>
<body>
<div class="reveal">
    <div class="slides">
        {{-- Title slide (legacy :1216-1224) --}}
        <section>
            <h1 style="margin:0;padding:0" class="r-fit-text">{{ $contestName }}</h1>
            <h1 style="margin:0;padding:0" class="tight">Awards</h1>
            @if (! empty($contestLogo))
                <div class="logo-image"><img src="{{ url('user_images/'.$contestLogo) }}" alt=""></div>
            @endif
        </section>

        @if ($sponsors !== [])
            {{-- Sponsor slide (legacy :1226-1239) --}}
            <section>
                <h1 style="margin:0;padding:0" class="r-fit-text">{{ $contestName }}</h1>
                <h1 style="margin:0;padding:0" class="tight">Sponsors</h1>
                <ul class="sponsor-slider">
                    @foreach ($sponsors as $sponsor)
                        <li><img src="{{ $sponsor->url }}" alt="sponsor"></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($staffRolls['judges'] !== '')
            {{-- Judge list slide (legacy :1240-1254) --}}
            <section>
                <h1 style="margin:0;padding:0" class="tight">Judges</h1>
                <p><small>{{ $staffRolls['judges'] }}</small></p>
                @if ($staffRolls['bos'] !== '')
                    <h3 style="margin:0;padding:0" class="tight">Judges - BOS</h3>
                    <p><small>{{ $staffRolls['bos'] }}</small></p>
                @endif
            </section>
        @endif

        @if ($staffRolls['stewards'] !== '')
            {{-- Steward list slide (legacy :1256-1265) --}}
            <section>
                <h1 style="margin:0;padding:0" class="tight">Stewards</h1>
                <p><small>{{ $staffRolls['stewards'] }}</small></p>
            </section>
        @endif

        @if ($staffRolls['staff'] !== '' || $staffRolls['organizers'] !== '')
            {{-- Staff list slide (legacy :1267-1279) --}}
            <section>
                <h1 style="margin:0;padding:0" class="tight">Staff</h1>
                @if ($staffRolls['staff'] !== '')<p><small>{{ $staffRolls['staff'] }}</small></p>@endif
                @if ($staffRolls['organizers'] !== '')
                    <h2 style="margin:0;padding:0" class="tight">Organizer</h2>
                    <p><small>{{ $staffRolls['organizers'] }}</small></p>
                @endif
            </section>
        @endif

        {{-- Statistic slide (legacy :1281-1316) --}}
        <section>
            <h1 style="margin:0;padding:0" class="tight">By the Numbers</h1>
            @if ($stats['entries'] > 0 || $stats['entrants'] > 0)
                <p>
                    @if ($stats['entries'] > 0)<span style="margin-right: 15px;" class="fragment" data-fragment-index="1"><i class="fa fa-beer"></i> {{ $stats['entries'] }} Entries</span>@endif
                    @if ($stats['entrants'] > 0)<span class="fragment" data-fragment-index="1"><i class="fa fa-user"></i> {{ $stats['entrants'] }} Entrants</span>@endif
                </p>
            @endif
            <p>
                @if ($stats['judges'] > 0)<span style="margin-right: 15px;" class="fragment" data-fragment-index="2"><i class="fa fa-gavel"></i> {{ $stats['judges'] }} Judges</span>@endif
                @if ($stats['stewards'] > 0)<span style="margin-right: 15px;" class="fragment" data-fragment-index="2"><i class="fa fa-pencil"></i> {{ $stats['stewards'] }} Stewards</span>@endif
                @if ($stats['staff'] > 0)<span class="fragment" data-fragment-index="2"><i class="fa fa-user-circle"></i> {{ $stats['staff'] }} Staff</span>@endif
            </p>
            @if ($stats['placing'] > 0)
                <p><span style="margin-right: 15px;" class="fragment" data-fragment-index="3"><i class="fa fa-trophy"></i> {{ $stats['placing'] }} Placing Entries</span></p>
            @endif
            @if (! empty($contestLogo))
                <div class="logo-image"><img style="max-height: 225px;" src="{{ url('user_images/'.$contestLogo) }}" alt=""></div>
            @endif
        </section>

        {{-- Winner slides (table/category/subcategory) --}}
        @foreach ($winnerSlides as $slide)
            <section>
                <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
                <p class="entry-count">{{ $slide->subtitle }}</p>
                @if ($slide->judgesLine !== '')<p class="small entry-count">{{ $slide->judgesLine }}</p>@endif
                @forelse ($slide->winners as $w)
                    @include('awards.partials.medal-grid', ['w' => $w])
                @empty
                    <p>There are no winning entries at this table.</p>
                @endforelse
            </section>
        @endforeach

        {{-- BOS per style-type slides (legacy :448-516; Judges line :473) --}}
        @foreach ($bosSlides as $slide)
            <section>
                <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
                @if ($slide->subtitle !== '')<h3 class="entry-count">{{ $slide->subtitle }}</h3>@endif
                @if ($staffRolls['bos'] !== '')<p class="small entry-count">Judges: {{ $staffRolls['bos'] }}</p>@endif
                @forelse ($slide->winners as $w)
                    @include('awards.partials.medal-grid', ['w' => $w])
                @empty
                    <p>There are no winning entries at this table.</p>
                @endforelse
            </section>
        @endforeach

        {{-- Special/custom best-of slides --}}
        @foreach ($specialBestSlides as $slide)
            <section>
                <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
                @forelse ($slide->winners as $w)
                    @include('awards.partials.medal-grid', ['w' => $w])
                @empty
                    <p>There are no winning entries at this table.</p>
                @endforelse
            </section>
        @endforeach

        {{-- Best Brewer / Best Club slides (legacy :962-1101) --}}
        @foreach ($bestBrewerSlides as $slide)
            @include('awards.partials.best-brewer-slide', ['slide' => $slide])
        @endforeach

        {{-- Thank-you slide (legacy :1322-1335) --}}
        <section>
            <h1 style="margin:0;padding:0" class="r-fit-text">Thank You</h1>
            <h3 style="margin:0;padding:0">Congratulations to All Medal Winners</h3>
            @if (! empty($contestLogo))
                <div class="logo-image"><img height="200" src="{{ url('user_images/'.$contestLogo) }}" alt=""></div>
            @endif
        </section>
    </div>
    <div class="footer">{{ $contestName }} - Awards - {{ $today }}</div>
</div>

{{-- Scoring-methodology dialog (legacy #scoring-method :1102-1133); opened
     by the [Scoring Methodology] links on the Best Brewer/Club slides. --}}
@if ($bestBrewerSlides !== [])
<dialog id="scoring-method">
    <h2>Scoring Methodology</h2>
    @if ($coaScoring)
        <p class="bold-text">{{ $coaLead }}</p>
        <p><img src="{{ $winnerMethod === 0 ? 'https://brewingcompetitions.com/00_images/CoA_Scoring_Tables.png' : 'https://brewingcompetitions.com/00_images/CoA_Scoring_Styles.png' }}" class="img-responsive" alt=""></p>
    @else
        <p class="bold-text">Each placing entry is given the following points:</p>
        <ul>
            <li>1st Place: {{ $placePoints[0] }}</li>
            <li>2nd Place: {{ $placePoints[1] }}</li>
            <li>3rd Place: {{ $placePoints[2] }}</li>
            @if ($placePoints[3] > 0)<li>4th Place: {{ $placePoints[3] }}</li>@endif
            @if ($placePoints[4] > 0)<li>HM: {{ $placePoints[4] }}</li>@endif
        </ul>
    @endif
    @if ($tiebreakers !== [])
        <p class="bold-text">The following tie-breakers have been applied, in order of priority:</p>
        <ol>
            @foreach ($tiebreakers as $tb)
                <li>{{ $tb }}</li>
            @endforeach
        </ol>
    @endif
    <form method="dialog"><button type="submit" class="btn btn-primary btn-sm">Close</button></form>
</dialog>
@endif
</body>
</html>