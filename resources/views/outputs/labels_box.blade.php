{{-- Judging box labels — legacy output/labels.output.php go=judging_tables
     (no filter). Avery 3422 = 24 labels/sheet, 5160 = 30/sheet (3 x N).
     Each label: big table number, table name, location name and the
     comma-separated style-number list; a grey fill when the table's
     location is virtual (judgingLocType=1, legacy loc_arr[4]==1). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Box Labels</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td { border: 1px solid #000; height: 96px; padding: 6px 4px;
         vertical-align: top; overflow: hidden; }
    td.grey { background: #e1e1e1; }
    .number { text-align: center; font-size: 36pt; font-weight: bold; }
    .name { font-weight: bold; }
    .loc { font-size: 9px; }
    .styles { font-size: 9px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@php $perSheet = $perSheet ?? 30; @endphp
@foreach (array_chunk($labels, $perSheet) as $sheet)
    <table>
        @foreach (array_chunk($sheet, 3) as $row)
            <tr>
                @foreach ($row as $cell)
                    <td class="{{ $cell['grey'] ? 'grey' : '' }}">
                        <div class="number">{{ $cell['number'] }}</div>
                        <div class="name">{{ $cell['name'] }}</div>
                        <div class="loc">{{ $cell['location'] }}</div>
                        <div class="styles">{{ $cell['styles'] }}</div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < 3; $i++)
                    <td></td>
                @endfor
            </tr>
        @endforeach
    </table>
    @if (! $loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
