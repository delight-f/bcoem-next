{{-- Per-table pull sheets — legacy output/pullsheets.output.php default branch.
     Columns per legacy: Pull Order, #, Style, Info, Box, [Mini-BOS], Score,
     Place. Non-queued renders one table per flight; queued renders one flat
     table per sheet; filter=mini_bos merges flights into one table. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pull Sheets</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 13px; margin: 0 0 6px; font-weight: normal; }
    h3 { font-size: 12px; margin: 10px 0 4px; }
    .lead { font-size: 12px; margin: 0 0 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: top; text-align: left; }
    th { background-color: #eee; }
    td.no { white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }
    p.info { margin: 0 0 3px; }
    p.box_small { margin: 0; }
    .page-break { page-break-after: always; }
    ul { margin: 0; padding-left: 16px; }
</style>
</head>
<body>
@php
    $miniBos = $miniBos ?? false;
    $queued = $queued ?? false;
@endphp
@foreach ($tables as $table)
    <h1>Table {{ $table['number'] }}: {{ $table['name'] }}{{ $miniBos ? ' - Mini-BOS' : '' }}</h1>

    @if ($table['locationLine'] && ! $miniBos)
        <h2>{{ $table['locationLine'] }}</h2>
    @endif

    @unless ($miniBos)
        <p class="lead">Entries: {{ $table['entryCount'] }}<br>
            Flights: {{ $table['flightCount'] ?? 0 }}</p>
        <ul>
            <li>If there are no entries showing below, flights at this table have not been assigned to rounds.</li>
            <li>If entries are missing, all entries have not been assigned to a flight or round OR they have been assigned to a different round.</li>
        </ul>
    @endunless

    @php $hasRows = false; @endphp

    @if ($queued)
        @foreach ($table['flights'] as $flight)
            @if (count($flight['rows']) > 0)
                @php $hasRows = true; @endphp
                <table>
                    <thead>@include('outputs.pullsheets_thead', ['miniBos' => $miniBos])</thead>
                    <tbody>
                        @foreach ($flight['rows'] as $row)
                            @include('outputs.pullsheets_row', ['row' => $row, 'miniBos' => $miniBos, 'styleSet' => $styleSet ?? ''])
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
        <div class="page-break"></div>
    @else
        @foreach ($table['flights'] as $flight)
            @if (count($flight['rows']) > 0)
                @php $hasRows = true; @endphp
                @if (! $miniBos && $flight['label'])
                    <h3>Table {{ $table['number'] }}: {{ $table['name'] }} - {{ $flight['label'] }}</h3>
                @endif
                <table>
                    <thead>@include('outputs.pullsheets_thead', ['miniBos' => $miniBos])</thead>
                    <tbody>
                        @foreach ($flight['rows'] as $row)
                            @include('outputs.pullsheets_row', ['row' => $row, 'miniBos' => $miniBos, 'styleSet' => $styleSet ?? ''])
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
        <div class="page-break"></div>
    @endif

    @unless ($hasRows)
        <p>{{ $miniBos ? 'No Mini-BOS entries available.' : 'No entries available.' }}</p>
    @endunless
@endforeach
</body>
</html>
