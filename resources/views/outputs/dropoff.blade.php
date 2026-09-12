{{-- Entries by drop-off location (legacy output/dropoff.output.php). go=default is the summary sheet, go=check lists every entry per location with an empty "received" box. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Drop-off {{ $mode === 'check' ? 'Check Sheets' : 'Summary' }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; }
    h1 { font-size: 16pt; margin-bottom: 4pt; }
    h2 { font-size: 13pt; margin: 0 0 2pt 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    th, td { border: 0.5pt solid #999; padding: 3pt 5pt; text-align: left; }
    thead th { background-color: #eee; }
    .count { text-align: right; }
    .box { display: inline-block; width: 14pt; height: 10pt; border: 0.75pt solid #000; }
    .page-break { page-break-after: always; }
    .footnote { font-size: 8.5pt; color: #444; }
</style>
</head>
<body>

@if (empty($locations)) @include('outputs.partials.no-data') @endif
@if ($mode === 'default')
    <h1>By Location</h1>
    <table>
        <thead>
            <tr><th>Name</th><th>Address</th><th class="count">Entries</th></tr>
        </thead>
        <tbody>
            @foreach ($locations as $location)
                <tr>
                    <td>{{ $location['name'] }}</td>
                    <td>{{ $location['address'] }}</td>
                    <td class="count">{{ $location['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2" style="text-align: right;">Total</th>
                <th class="count">{{ $total }}*</th>
            </tr>
        </tfoot>
    </table>
    <p class="footnote">*Entry count only reflects entrants who indicated a location in their account profile. The actual number of entries may be higher or lower.</p>
@else
    @foreach ($locations as $location)
        <h2>{{ $location['name'] }}</h2>
        <p style="font-size: 9pt; color: #555; margin: 0 0 4pt 0;">{{ $location['address'] }}</p>
        <p style="margin: 2pt 0;"><strong>Entries: {{ $location['count'] }}*</strong></p>
        <table>
            <thead>
                <tr><th style="width: 7%;">Entry #</th><th style="width: 43%;">Entry Name</th><th style="width: 43%;">Entrant</th><th style="width: 7%;">Received</th></tr>
            </thead>
            <tbody>
                @foreach ($location['entries'] as $entry)
                    <tr>
                        <td>{{ str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $entry->brewName }}</td>
                        <td>{{ $entry->brewerLastName }}, {{ $entry->brewerFirstName }}</td>
                        <td><span class="box"></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="footnote">*Entry count only reflects entrants who indicated a location in their account profile. The actual number of entries may be higher or lower.</p>
        @if (! $loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
@endif

</body>
</html>
