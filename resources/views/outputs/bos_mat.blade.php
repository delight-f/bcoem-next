{{-- BOS cup mats, 2×3 tiles per page (legacy output/bos_mat.output.php). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $heading }} Cup Mats</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; }
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
                        <p class="head">Style ___________________________</p>
                        <div class="num" style="margin-top: 130pt;">Entry # ____________</div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
@else
            @foreach ($groups as $group)
        @continue ($group['rows'] === [])
        @php
            // Issue 32: a group with no qualifying entries used to render a
            // full page of blank squares — every table/style type without
            // entries printed one. Skip it. Groups longer than one 2×3 page
            // continue onto further pages like legacy's six-tile pagination,
            // the final page padded to six.
        @endphp
                @foreach (array_chunk($group['rows'], 6) as $tiles)
        <table class="mat">
            @for ($i = 0; $i < 2; $i++)
                <tr>
                    @for ($j = 0; $j < 3; $j++)
                                                @php
                            $idx = $i * 3 + $j;
                        @endphp
                        <td>
                            @if (isset($tiles[$idx]))
                                                                @php
                                    $row = $tiles[$idx];
                                    $style = $row->brewCategory.$row->brewSubCategory;
                                @endphp
                                @if ($group['title'] !== null)
                                    <p class="head"><strong>{{ $group['title'] }}</strong></p>
                                @endif
                                <h3>{{ $style }}: {{ $row->brewStyle }}</h3>
                                @if ($labelByTable)
                                    <h4>{{ $tables->get($row->scoreTable, 'Table') }}</h4>
                                @else
                                    <h4>{{ ltrim((string) $row->brewCategory, '0') }}: {{ $subcats[$row->brewCategorySort] ?? '' }}</h4>
                                @endif

                                @if ($action === 'default' && $group['type'] === 2 && ((string) $row->brewMead1 !== '' || (string) $row->brewMead2 !== ''))
                                    <p class="note">{{ collect([$row->brewMead1, $row->brewMead2])->filter(fn ($v) => (string) $v !== '')->implode(', ') }}</p>
                                @elseif ($action === 'default' && $group['type'] === 3)
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
                                @if ($action === 'default' && (int) $row->brewerProAm === 1)
                                    <p class="note"><strong>** NOT ELIGIBLE FOR PRO-AM **</strong></p>
                                @endif

                                <div class="num">#{{ str_pad((string) ($showEntryNumber ? $row->id : $row->brewJudgingNumber), 6, '0', STR_PAD_LEFT) }}</div>
                                @if (! $labelByTable)
                                    <div class="tablename">{{ $tables->get($row->scoreTable, 'Table') }}</div>
                                @endif
                            @else
                                &nbsp;
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
        <div class="page-break"></div>
                @endforeach
            @endforeach

        @if (! $anyTiles)
        {{-- Issue #49: keep the "Mini-BOS" proper-noun casing (a plain
             strtolower produced "mini-bos"); other headings keep the legacy
             lower-cased sentence form. --}}
        <h1>No {{ $heading === 'Mini-BOS' ? 'mini-BOS' : strtolower($heading) }} entries are present.</h1>
    @endif
@endif

</body>
</html>
