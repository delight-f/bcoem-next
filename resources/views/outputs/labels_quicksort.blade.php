{{-- Quick-sort judging-number labels — legacy output/labels.output.php
     view=quicksort. Avery 5167 = 80 labels/sheet (4 x 20). Each label is a
     small cell; a top separator marks a style break (dashed within the same
     brewCategory, solid on a category change). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quick Sort Labels</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td { height: 48px; padding: 2px 3px; vertical-align: top;
         overflow: hidden; }
    td.dashed { border-top: 0.4px dashed #000; }
    td.solid { border-top: 1px solid #000; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@if (empty($cells)) @include('outputs.partials.no-data') @endif
@foreach (array_chunk($cells, 80) as $sheet)
    <table>
        @foreach (array_chunk($sheet, 4) as $row)
            <tr>
                @foreach ($row as $cell)
                    <td class="{{ $cell['sep'] !== '' ? $cell['sep'] : '' }}">
                        @foreach ($cell['lines'] as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </td>
                @endforeach
                @for ($i = count($row); $i < 4; $i++)
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
