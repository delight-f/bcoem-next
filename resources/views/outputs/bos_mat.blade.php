{{-- BOS cup mats, 2×3 tiles per page (legacy output/bos_mat.output.php). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $heading }} Cup Mats</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; }
    table.mat { width: 100%; table-layout: fixed; border-collapse: collapse; }
    table.mat td { width: 33.3%; height: 200pt; border: 0.75pt solid #333; padding: 6pt; vertical-align: top; position: relative; }
    .head { text-align: center; font-size: 9pt; margin: 0 0 4pt 0; }
    h3 { font-size: 12pt; margin: 4pt 0; }
    h4 { font-size: 10.5pt; margin: 3pt 0; }
    p.note { font-size: 8.5pt; margin: 2pt 0; font-style: italic; }
    .allergens { width: 100%; padding: 3pt; border: 0.75pt solid #999; border-radius: 4pt; font-size: 8.5pt; margin-top: 4pt; }
    .num { text-align: right; margin-top: 6pt; font-size: 8.5pt; }
    .tablename { text-align: right; font-size: 9pt; font-weight: bold; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>

@if ($blank)
    <table class="mat">
        @foreach (array_chunk(range(1, 6), 3) as $rowTiles)
            <tr>
                @foreach ($rowTiles as $tile)
                    <td>
                        <p class="head"><strong>*** {{ $heading }} ***</strong></p>
                        <p class="head">Style _____________________________</p>
                        <div class="num" style="margin-top: 130pt;">Entry # ____________</div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
@else
    @php
        // Flatten groups → pages of six tiles so the trailing page break is exact.
        $pages = [];
        foreach ($groups as $group) {
            if ($group['rows'] === []) {
                continue;
            }
            foreach (array_chunk($group['rows'], 6) as $tiles) {
                $pages[] = ['title' => $group['title'], 'type' => $group['type'], 'tiles' => $tiles];
            }
        }
    @endphp

    @if ($pages === [])
        <h1>No {{ strtolower($heading) }} entries are present.</h1>
    @endif

    @foreach ($pages as $page)
        <table class="mat">
            @foreach (array_chunk($page['tiles'], 3) as $rowTiles)
                <tr>
                    @foreach ($rowTiles as $row)
                        @php($style = $row->brewCategory.$row->brewSubCategory)
                        <td>
                            @if ($page['title'] !== null)
                                <p class="head"><strong>{{ $page['title'] }}</strong></p>
                            @endif
                            <h3>{{ $style }}: {{ $row->brewStyle }}</h3>
                            @if ($labelByTable)
                                <h4>{{ $tables->get($row->scoreTable, 'Table') }}</h4>
                            @else
                                <h4>{{ ltrim((string) $row->brewCategory, '0') }}: {{ $subcats[$row->brewCategorySort] ?? '' }}</h4>
                            @endif

                            @if ($page['type'] === 2 && ((string) $row->brewMead1 !== '' || (string) $row->brewMead2 !== ''))
                                <p class="note">{{ collect([$row->brewMead1, $row->brewMead2])->filter(fn ($v) => (string) $v !== '')->implode(', ') }}</p>
                            @elseif ($page['type'] === 3)
                                <p class="note">{{ collect([$row->brewMead1, $row->brewMead2, $row->brewMead3])->filter(fn ($v) => (string) $v !== '')->implode(', ') }}</p>
                            @endif

                            @if ((string) $row->brewInfo !== '')
                                <p class="note">{{ str_replace('^', ' | ', $row->brewInfo) }}</p>
                            @endif
                            @if ((string) $row->brewInfoOptional !== '')
                                <p class="note">{{ str_replace('^', ' | ', $row->brewInfoOptional) }}</p>
                            @endif
                            @if ((string) $row->brewComments !== '')
                                <p class="note">{{ $row->brewComments }}</p>
                            @endif
                            @if ((string) $row->brewPossAllergens !== '')
                                <div class="allergens"><strong>Possible allergens:</strong> <em>{{ $row->brewPossAllergens }}</em></div>
                            @endif
                            @if ($page['type'] !== null && (int) $row->brewerProAm === 1)
                                <p class="note"><strong>** NOT ELIGIBLE FOR PRO-AM **</strong></p>
                            @endif

                            <div class="num">#{{ str_pad((string) ($showEntryNumber ? $row->id : $row->brewJudgingNumber), 6, '0', STR_PAD_LEFT) }}</div>
                            @if (! $labelByTable)
                                <div class="tablename">{{ $tables->get($row->scoreTable, 'Table') }}</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        @unless ($loop->last)
            <div class="page-break"></div>
        @endunless
    @endforeach
@endif

</body>
</html>
