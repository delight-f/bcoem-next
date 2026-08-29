{{-- Awards presentation (legacy awards.php). reveal.js 4.1.0 deck,
     standalone page (no site layout), theme from ?view=. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $contestName }} - Awards Presentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/reset.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/reveal.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/theme/{{ $theme }}.min.css" id="theme">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/theme/fonts/league-gothic/league-gothic.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/theme/fonts/source-sans-pro/source-sans-pro.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .reveal .footer { position: fixed; bottom: 12px; left: 0; right: 0; text-align: center; font-size: 0.55em; opacity: 0.6; }
        .reveal .tight { margin: 0; padding: 0; }
        .reveal .entry-count { font-size: 0.75em; opacity: 0.85; }
        .reveal #medal-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 0.35em 1em; align-items: baseline; text-align: left; font-size: 0.85em; margin-top: 0.75em; }
        .reveal #medal-grid .col-right { text-align: right; font-weight: bold; }
        .reveal .pos-1-medal-color { color: #FFD700; } .reveal .pos-2-medal-color { color: #C0C0C0; }
        .reveal .pos-3-medal-color { color: #CD7F32; } .reveal .pos-4-medal-color, .reveal .pos-5-medal-color { color: #4a7a4a; }
        .reveal .logo-image { margin-top: 0.5em; }
        .reveal .logo-image img { max-height: 225px; }
    </style>
</head>
<body>
<div class="reveal">
    <div class="slides">
        {{-- Title slide --}}
        <section>
            <h1 style="margin:0;padding:0" class="r-fit-text">{{ $contestName }}</h1>
            <h1 style="margin:0;padding:0" class="tight">Awards</h1>
            @if (! empty($contestLogo))
                <div class="logo-image"><img src="{{ url('user_images/'.$contestLogo) }}" alt=""></div>
            @endif
        </section>

        @if ($sponsors !== [])
            {{-- Sponsor slide --}}
            <section>
                <h1 style="margin:0;padding:0" class="r-fit-text">{{ $contestName }}</h1>
                <h1 style="margin:0;padding:0" class="tight">Sponsors</h1>
                @foreach ($sponsors as $sponsor)
                    <img src="{{ $sponsor->url }}" height="200" alt="sponsor">
                @endforeach
            </section>
        @endif

        @if ($staffRolls['judges'] !== '')
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
            <section>
                <h1 style="margin:0;padding:0" class="tight">Stewards</h1>
                <p><small>{{ $staffRolls['stewards'] }}</small></p>
            </section>
        @endif

        @if ($staffRolls['staff'] !== '' || $staffRolls['organizers'] !== '')
            <section>
                <h1 style="margin:0;padding:0" class="tight">Staff</h1>
                @if ($staffRolls['staff'] !== '')<p><small>{{ $staffRolls['staff'] }}</small></p>@endif
                @if ($staffRolls['organizers'] !== '')
                    <h2 style="margin:0;padding:0" class="tight">Organizer</h2>
                    <p><small>{{ $staffRolls['organizers'] }}</small></p>
                @endif
            </section>
        @endif

        {{-- Stats slide --}}
        <section>
            <h1 style="margin:0;padding:0" class="tight">By The Numbers</h1>
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

        {{-- Winner slides: per-table (prefsWinnerMethod=0) --}}
        @foreach ($tableSlides as $slide)
            <section>
                <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
                <p class="entry-count">{{ $slide->count }} entries</p>
                @forelse ($slide->winners as $w)
                    <div id="medal-grid">
                        <div class="fragment justify-right col-right" data-fragment-index="{{ $w->fh }}"><i class="fa fa-trophy icon pos-{{ $w->fh }}-medal-color"></i>{{ $w->place }}</div>
                        <div class="fragment justify-left" data-fragment-index="{{ $w->fh }}">{{ $w->name }}</div>
                        @if (! $proEdition && $w->club !== '')
                            <div></div>
                            <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->club }}</div>
                        @endif
                        <div></div>
                        <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->entry }} ({{ $w->style }})</div>
                    </div>
                @empty
                    <p>No winning entries.</p>
                @endforelse
            </section>
        @endforeach

        {{-- BOS + special-best slides --}}
        @foreach ($bosSlides as $slide)
            <section>
                <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
                @if ($slide->subtitle !== '')<h3 class="entry-count">{{ $slide->subtitle }}</h3>@endif
                @foreach ($slide->winners as $w)
                    <div id="medal-grid">
                        <div class="fragment justify-right col-right" data-fragment-index="{{ $w->fh }}"><i class="fa fa-trophy icon pos-{{ $w->fh }}-medal-color"></i>{{ $w->place }}</div>
                        <div class="fragment justify-left" data-fragment-index="{{ $w->fh }}">{{ $w->name }}</div>
                        @if ($w->club !== '')
                            <div></div>
                            <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->club }}</div>
                        @endif
                        <div></div>
                        <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->entry }}@if($w->style !== '') ({{ $w->style }})@endif</div>
                    </div>
                @endforeach
            </section>
        @endforeach

        {{-- Thank-you slide --}}
        <section>
            <h1 style="margin:0;padding:0" class="r-fit-text">Thank You!</h1>
            <h3 style="margin:0;padding:0">Congratulations to the winners!</h3>
            @if (! empty($contestLogo))
                <div class="logo-image"><img height="200" src="{{ url('user_images/'.$contestLogo) }}" alt=""></div>
            @endif
        </section>
    </div>
    <div class="footer">{{ $contestName }} - Awards</div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/reveal.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.1.0/plugin/notes/notes.min.js"></script>
<script>
    Reveal.initialize({ hash: true, plugins: [ RevealNotes ] });
</script>
</body>
</html>
