{{-- Category sorting sheets — legacy output/sorting.output.php. One sheet
     per category with a manual-sorting grid (paid checkbox, empty "sorted"
     and box cells); go=cheat renders the judging-number affix sheets.
     Page break after every category. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sorting Sheets</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; }
    h2 { font-size: 15px; margin: 0 0 6px; }
    h4 { font-size: 11px; font-weight: normal; margin: 8px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th, td { border: 1px solid #444; padding: 3px 5px; text-align: left;
             vertical-align: top; }
    th { background-color: #eee; }
    .no { font-family: monospace; white-space: nowrap; }
    .box { width: 40px; height: 40px; border: 1px solid #000;
           display: inline-block; }
    .box-small { width: 16px; height: 16px; border: 1px solid #000;
                 display: inline-block; text-align: center; line-height: 16px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@foreach ($categories as $cat)
    <h2>{{ $cat['title'] }}</h2>

    @if (! $cheat)
        <table>
            <thead>
                <tr>
                    <th width="5%" style="white-space:nowrap;">Entry</th>
                    @if ($showJudging)
                        <th width="5%" style="white-space:nowrap;">Judging</th>
                    @endif
                    <th width="20%">Brewer</th>
                    <th>Name</th>
                    <th width="20%">Style</th>
                    <th width="5%">Subcategory</th>
                    <th width="5%">Contact</th>
                    <th width="5%">Paid</th>
                    <th width="5%">Sorted</th>
                    <th width="5%">Box</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cat['rows'] as $row)
                    <tr>
                        <td class="no">{{ $row['entryNo'] }}</td>
                        @if ($showJudging)
                            <td class="no">{{ $row['judgingNo'] }}</td>
                        @endif
                        <td>{{ $row['brewer'] }}
                            @if ($row['coBrewer'] !== '')
                                <br>{{ $row['coBrewer'] }}
                            @endif
                        </td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['style'] }}</td>
                        <td>{{ $row['subCategory'] }}</td>
                        <td><small>{{ $row['contact'] ?? '' }}</small></td>
                        <td><span class="box-small">{{ $row['paid'] ? "\u{2713}" : '' }}</span></td>
                        <td><span class="box-small"></span></td>
                        <td><span class="box"></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <h4>Affix labels in the numbered order shown.</h4>
        <table>
            <thead>
                <tr>
                    <th width="5%" style="white-space:nowrap;">Subcategory</th>
                    <th width="20%" style="white-space:nowrap;">Entry</th>
                    <th width="20%" style="white-space:nowrap;">Judging</th>
                    <th>Affixed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cat['rows'] as $row)
                    <tr>
                        <td>{{ $row['subCategory'] }}</td>
                        <td class="no">{{ $row['entryNo'] }}</td>
                        <td class="no">{{ $row['readableJudgingNo'] }}</td>
                        <td><span class="box-small">&nbsp;</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Legacy appends the break after every category, last one included. --}}
    <div class="page-break"></div>
@endforeach
</body>
</html>
