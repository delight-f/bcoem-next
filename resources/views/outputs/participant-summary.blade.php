{{-- Participant entry summary, one page per brewer with received entries.
     Legacy: output/participant_summary.output.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Participant Summary</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; }
    h1 { font-size: 18px; margin: 0 0 4px 0; }
    .lead { font-size: 12px; margin: 4px 0; }
    table.entries { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.entries th, table.entries td { border: 1px solid #999; padding: 3px 5px; text-align: left; }
    table.entries th { background-color: #eee; }
    .check { font-weight: bold; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@if (empty($participants)) @include('outputs.partials.no-data') @endif
@foreach ($participants as $i => $participant)
    <h1>{{ $contestName }} Summary for {{ $participant['name'] }}</h1>
    <p class="lead">Thank you for participating our competition, {{ strtok($participant['name'], ' ') }}. A summary of your entries, scores, and places is below.</p>
    <p class="lead"><small>{{ $receivedCount }} entries were judged.</small></p>

    <table class="entries">
        <thead>
        <tr>
            <th>Entry</th>
            <th>Judging</th>
            <th>Name</th>
            <th>Style</th>
            <th>Score</th>
            <th>Mini-BOS</th>
            <th>Best of Show</th>
            <th>Place</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($participant['rows'] as $row)
            <tr>
                <td>{{ $row['entry'] }}</td>
                <td>{{ $row['judging'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['style'] }}</td>
                <td>@if ($row['miniBos'])<span class="check">X</span>@endif</td>
                <td>{{ $row['bosPlace'] }}</td>
                <td>{!! $row['place'] !!}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if ($organizer !== null)
        <p>Cheers,</p>
        <p>{{ $organizer->brewerFirstName }} {{ $organizer->brewerLastName }}<br>Organizer, {{ $contestName }}</p>
    @endif

    @if (! ($loop->last))
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
