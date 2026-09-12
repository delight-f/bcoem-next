{{-- Participant address/name label sheets — legacy output/labels.output.php,
     go=participants&action=address_labels. Avery 5160 = 30 labels/sheet
     (3 x 10), 3422 = 24/sheet (3 x 8); page break per sheet. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Address Labels</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td { border: 1px solid #000; height: 72px; padding: 6px 4px;
         vertical-align: top; overflow: hidden; }
    tr.void td { border: none; height: 0; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@php $perSheet = $perSheet ?? 30; @endphp
@if (empty($labels)) @include('outputs.partials.no-data') @endif
@foreach (array_chunk($labels, $perSheet) as $sheet)
    <table>
        @foreach (array_chunk($sheet, 3) as $row)
            <tr>
                @foreach ($row as $lines)
                    <td>
                        @foreach ($lines as $line)
                            @if (trim((string) $line) !== '')
                                <div>{{ $line }}</div>
                            @endif
                        @endforeach
                    </td>
                @endforeach
                {{-- pad short final rows so borders still draw --}}
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
