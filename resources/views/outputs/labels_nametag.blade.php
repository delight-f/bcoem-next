{{-- Staff nametags — legacy output/labels.output.php
     go=participants&action=judging_nametags. Avery 5395 = 8 labels/sheet
     (2 x 4). Each tag: staff name, role assignment, city/state. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Nametags</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td { border: 1px solid #000; height: 150px; padding: 8px 6px;
         vertical-align: top; overflow: hidden; }
    .name { font-weight: bold; text-align: center; }
    .role { text-align: center; }
    .loc { text-align: center; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@if (empty($labels)) @include('outputs.partials.no-data') @endif
@foreach (array_chunk($labels, 8) as $sheet)
    <table>
        @foreach (array_chunk($sheet, 2) as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>
                        <div class="name">{{ $cell['name'] }}</div>
                        <div class="role">{{ $cell['assignment'] }}</div>
                        <div class="loc">{{ $cell['location'] }}</div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < 2; $i++)
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
