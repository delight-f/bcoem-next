{{-- Results PDF (legacy output/results.output.php + winners/bos/bestbrewer sections). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $contestName }} — Results</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10.5pt; }
    h1 { font-size: 17pt; margin: 0 0 4pt 0; }
    h2 { font-size: 14pt; margin: 14pt 0 4pt 0; }
    h3 { font-size: 12pt; margin: 10pt 0 3pt 0; }
    .lead { font-size: 10pt; color: #333; }
    table { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    th, td { border: 0.5pt solid #999; padding: 2.5pt 5pt; text-align: left; }
    thead th { background-color: #eee; }
</style>
</head>
<body>

<h1>{{ $contestName }}</h1>
@if ($lead !== null)
    <p class="lead">{{ $lead }}</p>
@endif

@if ($showBos)
    <h2>Best of Show</h2>
    @if ($bos === [])
        <p>No best-of-show results yet.</p>
    @else
        <table>
            <thead>
                <tr><th style="width: 10%;">Place</th><th>Brewer</th><th>Entry Name</th><th>Style</th></tr>
            </thead>
            <tbody>
                @foreach ($bos as $row)
                    <tr>
                        <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                        <td>{{ $row->brewerFirstName }} {{ $row->brewerLastName }}</td>
                        <td>{{ $row->brewName }}</td>
                        <td>{{ $row->brewStyle }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

@if ($showBest && $bestBrewers !== [])
    <h2>Best Brewer</h2>
    <table>
        <thead>
            <tr><th>Brewer</th><th>Club</th><th style="width: 15%;">Points</th></tr>
        </thead>
        <tbody>
            @foreach ($bestBrewers as $brewer)
                <tr>
                    <td>{{ $brewer->name }}</td>
                    <td>{{ $brewer->club }}</td>
                    <td>{{ rtrim(rtrim(number_format($brewer->points, 4, '.', ''), '0'), '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php($clubs = collect($bestBrewers)->whereNotNull('club')->filter(fn ($b) => (string) $b->club !== '')->groupBy('club')
        ->map(fn ($rows) => $rows->sum(fn ($b) => $b->points))->sortDesc())
    @if ($clubs->isNotEmpty())
        <h2>Best Club</h2>
        <table>
            <thead>
                <tr><th>Club</th><th style="width: 15%;">Points</th></tr>
            </thead>
            <tbody>
                @foreach ($clubs as $club => $points)
                    <tr>
                        <td>{{ $club }}</td>
                        <td>{{ rtrim(rtrim(number_format($points, 4, '.', ''), '0'), '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

@if ($showWinners)
    <h2>Winning Entries</h2>
    @if ($winners === [])
        <p>No winning entries yet.</p>
    @elseif ($winnerMethod === '1' || $winnerMethod === '2')
        @php($grouped = collect($winners)->groupBy($winnerMethod === '1'
            ? fn ($r) => $r->brewCategorySort
            : fn ($r) => $r->brewCategorySort.$r->brewSubCategory))
        @foreach ($grouped->all() as $group => $rows)
            <h3>Category {{ $group }}</h3>
            <table>
                <thead>
                    <tr><th style="width: 10%;">Place</th><th>Entry Name</th><th>Style</th><th>Brewer</th><th>Club</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                            <td>{{ $row->brewName }}</td>
                            <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewStyle }}</td>
                            <td>{{ $row->brewerFirstName }} {{ $row->brewerLastName }}</td>
                            <td>{{ $row->brewerClubs }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @else
        <table>
            <thead>
                <tr><th style="width: 10%;">Place</th><th>Entry Name</th><th>Style</th><th>Brewer</th><th>Club</th></tr>
            </thead>
            <tbody>
                @foreach ($winners as $row)
                    <tr>
                        <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                        <td>{{ $row->brewName }}</td>
                        <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewStyle }}</td>
                        <td>{{ $row->brewerFirstName }} {{ $row->brewerLastName }}</td>
                        <td>{{ $row->brewerClubs }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

</body>
</html>
