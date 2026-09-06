{{-- Entry bottle labels — legacy output/bottle_label.output.php. 3-column
     grid; page break every 9 labels (barcode variants, taller cells) or 12
     (plain). Legacy streamed HTML with a self-print timer; port renders PDF.
     Barcode/QR render as text in a bordered box — dompdf has remote images
     disabled and legacy pulled them from an external service. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Entry Bottle Labels</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $large ? '12px' : '9px' }}; }
    .row::after { content: ""; display: table; clear: both; }
    .cell { float: left; width: 33.33%; box-sizing: border-box;
            border: 1px solid #000; padding: 6px 4px; height: {{ $barcodeQr ? '290px' : '200px' }};
            overflow: hidden; text-align: left; }
    /* Fixed body region pins the QR block to the cell bottom (legacy
       min-height 290px; dompdf has no absolute positioning). */
    .body { height: 190px; overflow: hidden; }
    /* QR + code value as a deterministic table: right column 75px wide,
       number centered beneath the QR image (floats render unreliably). */
    .qrtab { width: 100%; border-collapse: collapse; }
    .qrtab td { padding: 0; }
    .qrtab .qrc { width: 75px; text-align: center; font-size: 0.85em; }
    .qrtab .qrimg img { display: block; }
    .title { text-align: center; font-weight: bold; margin-bottom: 6px;
             font-size: {{ $large ? '1.5em' : '1.1em' }}; }
    .code-large { text-align: center; font-size: 2.6em; font-weight: 900;
                  line-height: 1.1; margin: 8px 0; }
    .code-cat { text-align: center; font-size: 1.7em; font-weight: 900;
                line-height: 1.0; padding: 4px; }
    .code-box { text-align: center; border: 1px solid #dedede;
                border-radius: 5px; padding: 4px 0; font-size: 1.15em;
                margin-bottom: 6px; }
    div + div { margin-bottom: 3px; }
    .cat-name { font-size: 0.75em; }
    .small { font-size: 0.85em; }
    .break-long { overflow-wrap: break-word; word-break: break-all; }
</style>
</head>
<body>
@if ($info !== '')
    <p>{{ $info }}</p>
@endif
@foreach (array_chunk($cells, $perPage) as $page)
    @foreach (array_chunk($page, 3) as $row)
        <div class="row">
            @foreach ($row as $cell)
                <div class="cell">
                    <div class="body">
                    <div class="title">{{ $contest }}</div>

                    @if ($cell['largeNum'])
                        <div class="code-large">{{ $cell['code'] }}</div>
                    @elseif ($cell['largeText'])
                        <div class="code-cat">{{ $cell['code'] }}</div>
                    @else
                        <div class="code-box"><strong>Entry Number:</strong> {{ $cell['code'] }}</div>
                    @endif

                    @if ($cell['largeText'])
                        <div class="code-cat" style="margin-top:10px;">
                            @if ($cell['styleSet'] === 'BA')
                                <div>{{ $cell['styleName'] }}</div>
                            @elseif ($cell['styleSet'] === 'AABC')
                                <div>Category: {{ $cell['category'] }}</div>
                                <div class="cat-name">{{ $cell['styleName'] }}</div>
                            @else
                                <div>{{ $cell['category'] }}</div>
                                <div class="cat-name">{{ $cell['styleName'] }}</div>
                            @endif
                        </div>
                    @endif

                    @if (! $cell['anon'])
                        <div><strong>Entry Name:</strong> {{ $cell['entryName'] }}</div>
                    @endif

                    @if (! $cell['largeText'])
                        <div>
                            <strong>Category:</strong>
                            @if ($cell['styleSet'] === 'BA')
                                {{ $cell['styleName'] }}
                            @elseif ($cell['styleSet'] === 'AABC')
                                {{ $cell['category'] }} {{ $cell['styleName'] }}
                            @else
                                {{ $cell['category'] }} {{ $cell['styleName'] }}
                            @endif
                        </div>
                    @endif

                    @if ($cell['anon'])
                        @if ($cell['requiredInfo'] !== '')
                            <div><strong>{{ $cell['infoLabel'] }}</strong>
                                <span class="break-long">{{ $cell['requiredInfo'] }}</span></div>
                        @endif
                        @if ($cell['meadCider'] !== '')
                            <div>{{ $cell['meadCider'] }}</div>
                        @endif
                    @endif

                    @if ($cell['standard'])
                        @foreach ($cell['contact'] as $line)
                            <div class="small">{!! nl2br(e((string) $line)) !!}</div>
                        @endforeach
                    @endif

                    </div>
                    @if ($cell['barcodeQr'])
                        {{-- QR anchored bottom-right of the label box; the
                             code39 value centered directly beneath the QR. --}}
                        <table class="qrtab">
                            <tr>
                                <td></td>
                                <td class="qrc qrimg"><img src="data:image/svg+xml;base64,{{ base64_encode($cell['qrSvg']) }}" width="75" height="75" alt="QR"></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td class="qrc">[{{ $cell['code'] }}]</td>
                            </tr>
                        </table>
                    @endif
                </div>
            @endforeach
            {{-- pad incomplete rows --}}
            @for ($i = count($row); $i < 3; $i++)
                <div class="cell" style="border-color:#fff;"></div>
            @endfor
        </div>
    @endforeach
    @if (! $loop->last)
        <div style="page-break-before: always;"></div>
    @endif
@endforeach
</body>
</html>
