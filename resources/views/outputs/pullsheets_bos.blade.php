{{-- go=judging_scores_bos — Best-of-Show pull sheets per style type
     (legacy output/pullsheets.output.php judging_scores_bos branch).
     Columns: Pull Order, #, Table Place, Style, Info, Box, Score, BOS Place. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BOS Pull Sheets</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: top; text-align: left; }
    th { background-color: #eee; }
    td.no { white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }
    p.info { margin: 0 0 3px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@foreach ($groups as $group)
    <h1>Best of Show: {{ $group['name'] }}</h1>
    @if (count($group['rows']) > 0)
        <table>
            <thead>
                <tr>
                    <th width="5%" nowrap>Pull Order</th>
                    <th width="5%">#</th>
                    <th width="5%">Table Place</th>
                    <th width="20%">Style</th>
                    <th>Info</th>
                    <th width="5%" nowrap>Box</th>
                    <th width="5%" nowrap>Score</th>
                    <th width="5%" nowrap>BOS Place</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td><p>&nbsp;</p></td>
                        <td class="no">{{ $row['numCol'] }}</td>
                        <td>{{ $row['scorePlace'] }}</td>
                        <td>
                            @if ($styleSet === 'BA')
                                {{ $row['brewStyle'] }}
                            @else
                                {{ $row['styleNum'] }} {{ $row['brewStyle'] }}@if ($row['category'] !== '')<em><br>{{ $row['category'] }}</em>@endif
                            @endif
                        </td>
                        <td>
                            @foreach ($row['info'] as $info)
                                <p class="info"><strong>{{ $info['label'] }}:</strong> {{ $info['value'] }}</p>
                            @endforeach
                        </td>
                        <td>{{ $row['box'] }}</td>
                        <td><p>&nbsp;</p></td>
                        <td><p>&nbsp;</p></td>
                    </tr>
                    @if ($row['proAm'] >= 1)
                        <tr><td colspan="8"><p><strong>** NOT ELIGIBLE FOR PRO-AM **</p></td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @else
        <p>No BOS entries were found for {{ $group['name'] }}.</p>
    @endif
    <div class="page-break"></div>
@endforeach
</body>
</html>
