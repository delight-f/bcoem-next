{{-- Per-table pull sheets — legacy output/pullsheets.output.php, all-tables mode.
     One sheet per table; page break after every table like the legacy
     `<div style="page-break-after:always;"></div>` separators. --}}
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
    .count { font-size: 12px; margin: 0 0 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: top; text-align: left; }
    th { background-color: #eee; }
    td.no { white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }
    p.info { margin: 0 0 3px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@foreach ($tables as $table)
    <h1>Table {{ $table['number'] }}: {{ $table['name'] }}</h1>
    @if ($table['locationLine'])
        <h2>{{ $table['locationLine'] }}</h2>
    @endif
    <p class="count">
        Entries: {{ $table['entryCount'] }}
        @if ($table['flightCount'] !== null)
            &middot; Flights: {{ $table['flightCount'] }}
        @endif
    </p>

    @php $hasRows = false; @endphp
    @foreach ($table['flights'] as $flight)
        @if (! $loop->first && $flight['label'])
            <div class="page-break"></div>
        @endif
        @if (count($flight['rows']) > 0)
            @php $hasRows = true; @endphp
            @if ($flight['label'])
                <h3>Table {{ $table['number'] }}: {{ $table['name'] }} — {{ $flight['label'] }}</h3>
            @endif
            <table>
                <thead>
                    <tr>
                        <th width="7%">Entry #</th>
                        <th width="7%">Judging #</th>
                        <th width="20%">Style</th>
                        <th width="14%">Brewer</th>
                        <th>Special Ingredients / Info</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($flight['rows'] as $row)
                        <tr>
                            <td class="no">{{ $row['entryNo'] }}</td>
                            <td class="no">{{ $row['judgingNo'] }}</td>
                            <td>{{ $row['style'] }}</td>
                            <td>{{ $row['brewer'] }}</td>
                            <td>
                                @foreach ($row['info'] as $info)
                                    <p class="info"><strong>{{ $info['label'] }}:</strong> {{ $info['value'] }}</p>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @unless ($hasRows)
        <p>No entries available.</p>
    @endunless

    {{-- Legacy appends this after every table, last one included. --}}
    <div class="page-break"></div>
@endforeach
</body>
</html>
