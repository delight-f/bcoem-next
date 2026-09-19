{{-- Staff availability for non-judging sessions (legacy assignments.output.php, filter=staff). --}}
<h1>Staff Availability<br><small>{{ $contestName }}</small></h1>

@if (count($rows) > 0)
    <p>Please note that the following people indicated that they are <em>available</em> to be a staff member for one or more non-judging locations. They may or may not be <em>assigned</em> as a staff member in the application by an Administrator, which is required for BJCP reporting purposes.</p>
    <table width="100%" border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th width="30%">Name</th>
            <th>Session</th>
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['name'] }}<br><small>{{ $row['email'] }}</small></td>
                <td>{{ $row['session'] }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p>No one has indicated that they are available to be a staff member for any non-judging session.</p>
@endif
