{{-- Round bottle labels — legacy output/labels.output.php
     action=bottle-entry-round|bottle-judging-round|bottle-category-round.
     Grid matches the Avery sheet: OL32 = 11x14 @ 12.7mm, OL5275WR =
     9x12 @ 19.05mm; each label is a fixed-size square cell centered. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Round Bottle Labels</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 7px; }
    body { margin: 0; }
    table { border-collapse: separate; border-spacing: 0; }
    td { text-align: center; vertical-align: middle; overflow: hidden;
         padding: 0; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@php
    // Round bottle sheets (OL32 / OL5275WR) plus the medal-round sheets
    // (5293, OL3012, EU30095 / OL5375 default) from pdf_label.php.
    [$cols, $rows, $cellW, $cellH, $size, $pitch] = match ($psort) {
        'OL32' => [11, 14, '12.7mm', '12.7mm', '7px', 19.05],
        'OL5275WR' => [9, 12, '19.05mm', '19.05mm', '9px', 20.65],
        '5293' => [4, 6, '41.28mm', '41.28mm', '9px', 49.28],
        'OL3012' => [4, 5, '50.8mm', '50.8mm', '9px', 52.39],
        'EU30095' => [4, 6, '45mm', '45mm', '9px', 47],
        default => [4, 5, '50.8mm', '50.8mm', '9px', 52.39],
    };
    $cell = $cellW;
@endphp
@foreach (array_chunk($cells, $cols * $rows) as $sheet)
    <table style="width: {{ $cols * $pitch }}mm;">
        @foreach (array_chunk($sheet, $cols) as $row)
            <tr>
                @foreach ($row as $cellLines)
                    <td style="width: {{ $cell }}; height: {{ $cell }}; font-size: {{ $size }};">
                        @foreach ($cellLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </td>
                @endforeach
                @for ($i = count($row); $i < $cols; $i++)
                    <td style="width: {{ $cell }}; height: {{ $cell }};"></td>
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
