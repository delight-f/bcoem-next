{{-- Participant entries list: entry + judging numbers per participant.
     Legacy: output/participant_entries_list.output.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Participant Entries</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; }
    h1 { font-size: 18px; margin: 0 0 4px 0; }
    .lead { font-size: 12px; margin: 4px 0 10px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; }
    th { background-color: #eee; }
</style>
</head>
<body>
<h1>Participant Entries</h1>
<p class="lead">The following lists each participant's entries and associated judging number as assigned in the system. <small>For instance, this list could be useful for distributing scoresheets sorted by number after an awards ceremony.</small></p>

@if (empty($rows))
    @include('outputs.partials.no-data')
@else
<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Entry Number</th>
        <th>Judging Number</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['entryNumbers'] }}</td>
            <td>{{ $row['judgingNumbers'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
</body>
</html>
