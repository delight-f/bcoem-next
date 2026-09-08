{{-- go=all_entry_info — "Entries with Additional Info" (view=default)
     or judge inventories (view=judge_inventory).
     Columns (#, Style, Required Info, Optional Info, Brewer Specifics,
     Possible Allergens, Notes) match the legacy thead. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Entries with Additional Info</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 13px; margin: 0 0 6px; font-weight: normal; }
    h3 { font-size: 12px; margin: 10px 0 4px; }
    .lead { font-size: 12px; margin: 0 0 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: top; text-align: left; }
    th { background-color: #eee; }
    td.no { white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }
    p.info { margin: 0 0 3px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@php
    $head = '<tr><th width="5%">#</th><th width="15%">Style</th><th width="15%">Required Info</th><th width="15%">Optional Info</th><th width="15%">Brewer Specifics</th><th width="15%">Possible Allergens</th><th>Notes</th></tr>';
@endphp

@if (isset($inventory))
    @if (count($inventory) === 0)
        <h2>No Inventories Available</h2>
        <p><strong>No inventories available for this session.</strong> Entries must be marked as received for this report to return a list. If entries are marked as received, check that judges have been assigned to tables and/or flights.</p>
    @endif
    @foreach ($inventory as $inv)
        @php
            $roles = $inv['roles'] ?? '';
            $rolesHtml = '';
            if (str_contains($roles, 'HJ')) { $rolesHtml .= '<span style="margin-left:1.5em;">Head Judge</span>'; }
            if (str_contains($roles, 'MBOS')) { $rolesHtml .= '<span style="margin-left:1em;">Mini-BOS</span>'; }
        @endphp
        <h1>Judging Inventory for {{ $inv['judgeFirst'] }} {{ $inv['judgeLast'] }}</h1>
        <h2>Table {{ $inv['tableNumber'] }}: {{ $inv['tableName'] }}
            @if ($rolesHtml !== '')<small><em>{!! $rolesHtml !!}</em></small>@endif
        </h2>
        <h3>{{ $inv['locationLine'] }}</h3>
        <p class="lead">Flight {{ $inv['flight'] }}, Round {{ $inv['round'] }} <small style="margin-left:1em;">{{ $inv['entryCount'] }} Entries to Judge</small></p>
        <table>
            <thead>{!! $head !!}</thead>
            <tbody>
                @foreach ($inv['rows'] as $row)
                    @include('outputs.pullsheets_all_info_row', ['row' => $row, 'styleSet' => $styleSet])
                @endforeach
            </tbody>
        </table>
        <div class="page-break"></div>
    @endforeach
@else
    @foreach ($tables as $table)
        <h1>Table {{ $table['number'] }}: {{ $table['name'] }} <small><em>Entries with Additional Info</em></small></h1>
        @if ($table['locationLine'])
            <h3>{{ $table['locationLine'] }}</h3>
        @endif
        @if (count($table['rows']) > 0)
            <table>
                <thead>{!! $head !!}</thead>
                <tbody>
                    @foreach ($table['rows'] as $row)
                        @include('outputs.pullsheets_all_info_row', ['row' => $row, 'styleSet' => $styleSet])
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No entries at this table have additional information.</p>
        @endif
        <div class="page-break"></div>
    @endforeach
@endif
</body>
</html>
