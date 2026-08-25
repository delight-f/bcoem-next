{{-- Table cards / sorting placards — legacy output/table_cards.output.php.
     Three modes: sorting-placards (one placard page per category),
     sorting-tables (master-list table or tent cards), default (tent cards
     with judge/steward rosters). Page break after every card/placard. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Table Cards</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
    h1 { font-size: 22px; margin: 6px 0 2px; }
    h1 small { font-size: 15px; font-weight: normal; }
    h2 { font-size: 13px; font-weight: normal; margin: 0 0 8px; }
    h4 { font-size: 11px; font-weight: normal; margin: 0 0 10px; }
    .lead { font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #444; padding: 3px 5px; text-align: left;
             vertical-align: top; }
    th { background-color: #eee; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@if (! empty($empty))
    <h2>No table information has been entered yet.</h2>
    <p class="lead">Add judging tables before generating table cards.</p>
@elseif (($mode ?? '') === 'placards')
    @foreach ($placards as $p)
        <div>
            <h1 style="margin-bottom:0;">{{ $p['number'] }} - {{ $p['name'] }}</h1>
            <h2><small>{{ $p['count'] }}</small></h2>
            <ul style="list-style:none; padding-left:0; margin-top:4px;">
                @foreach ($p['items'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
        {{-- Legacy appends the break after every placard, last one included. --}}
        <div class="page-break"></div>
    @endforeach
@elseif (($mode ?? '') === 'tables')
    @if ($masterList)
        <h2>Tables and Associated Styles &mdash; Master List</h2>
        <p><strong><sup>&#10029;</sup> Entry Count</strong> is <strong>prior</strong> to check-in in
            the system and may not reflect the true count of received entries.<br>
            <strong><sup>&#10033;</sup> Session</strong> is subject to change.</p>
        <table>
            <thead>
                <tr>
                    <th width="30%">Table</th>
                    <th width="25%">Styles</th>
                    <th>Entry Count<sup>&#10029;</sup></th>
                    <th width="30%">Session<sup>&#10033;</sup></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['number'] }}: {{ $r['name'] }}</td>
                        <td>{{ $r['styles'] }}</td>
                        <td>{{ $r['received'] }}</td>
                        <td>{{ $r['location'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        @foreach ($rows as $r)
            <div>
                <h1>Table {{ $r['number'] }}</h1>
                <h1><small>{{ $r['name'] }}</small></h1>
                <h2><small>Styles: {{ $r['styles'] }}</small></h2>
            </div>
            <div class="page-break"></div>
        @endforeach
    @endif
@else
    @foreach ($rows as $r)
        <div>
            <h1>Table {{ $r['number'] }}</h1>
            <h2>{{ $r['name'] }}</h2>
            @if ($r['location'])
                <h4>{{ $r['location'] }}</h4>
            @endif

            @if (count($r['people']) > 0)
                <table>
                    <tbody>
                        @foreach ($r['people'] as $person)
                            <tr>
                                <td>
                                    <strong>{{ $person['name'] }}</strong>
                                    @if ($person['rank'] !== '' && $person['assignment'] === 'Judge')
                                        ({{ rtrim($person['rank'], ', ') }})
                                    @endif
                                    @if ($person['role'] !== '')
                                        <br><em>{{ $person['role'] }}</em>
                                    @endif
                                </td>
                                <td width="5%" style="white-space:nowrap;">{{ $person['assignment'] }}</td>
                                <td width="5%" style="white-space:nowrap;">{{ $person['round'] }}</td>
                                @if ($showFlights)
                                    <td width="5%" style="white-space:nowrap;">{{ $person['flight'] }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="page-break"></div>
    @endforeach
@endif
</body>
</html>
