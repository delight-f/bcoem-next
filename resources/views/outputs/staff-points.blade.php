{{-- BJCP experience-points report for organizer / judges / stewards / staff.
     Legacy: output/staff_points.output.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BJCP Points Report</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #000; }
    h1 { font-size: 18px; margin: 0 0 4px 0; }
    h2 { font-size: 14px; margin: 12px 0 4px 0; }
    .lead { font-size: 11px; margin: 4px 0; }
    ul { margin: 4px 0; padding-left: 14px; list-style: none; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; }
    th { background-color: #eee; }
    em.note { font-style: italic; }
</style>
</head>
<body>
<h1>{{ $contestName }} BJCP Points Report</h1>
<p class="lead">The points in this report are derived from the official BJCP Sanctioned Competition Requirements, available at https://www.bjcp.org/competitions/rules-regulations/.</p>

<ul>
    <li><strong>BJCP Competition ID:</strong> {{ $compId }}</li>
    <li><strong>Entries:</strong> {{ $entries }}</li>
    <li><strong>Days:</strong> {{ $days }}</li>
    <li><strong>Sessions:</strong> {{ $sessions }}</li>
    <li><strong>Flights:</strong> {{ $flights }} (includes Best of Show)</li>
    @if ($bosAlert !== '')
        <li><strong>Please Note:</strong> {{ $bosAlert }}</li>
    @endif
</ul>

@if ($organizer !== null)
    <h2>Organizer</h2>
    <table>
        <thead>
        <tr><th>Name</th><th>BJCP ID</th><th>Points</th></tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $organizer['name'] }}</td>
            <td>{{ $organizer['bjcpId'] ?? '' }}</td>
            <td>{{ $organizer['points'] }}</td>
        </tr>
        </tbody>
    </table>
@endif

@if (count($judges) > 0)
    <h2>Judges</h2>
    <table>
        <thead>
        <tr><th>Name</th><th>BJCP ID</th><th>Points</th><th>BOS</th></tr>
        </thead>
        <tbody>
        @foreach ($judges as $judge)
            <tr>
                <td>{{ $judge['name'] }}</td>
                <td>{{ $judge['bjcpId'] ?? '' }}</td>
                <td>{{ $judge['points'] }}</td>
                <td>{{ $judge['bos'] ? 'X' : '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if (count($stewards) > 0)
    <h2>Stewards</h2>
    <table>
        <thead>
        <tr><th>Name</th><th>BJCP ID</th><th>Points</th></tr>
        </thead>
        <tbody>
        @foreach ($stewards as $steward)
            <tr>
                <td>{{ $steward['name'] }}</td>
                <td>{{ $steward['bjcpId'] ?? '' }}</td>
                <td>{{ $steward['points'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if (count($staff) > 0)
    <h2>Staff</h2>
    <p>Total Staff Points Available: {{ $staffMax }}</p>
    @if ($staffOverflow)
        <p><em class="note">When submitting your report to the BJCP, it is possible that not all on the staff list will receive points. It is suggested that you allocate points to those with BJCP IDs first.</em></p>
    @endif
    <table>
        <thead>
        <tr><th>Name</th><th>BJCP ID</th><th>Points</th></tr>
        </thead>
        <tbody>
        @foreach ($staff as $member)
            <tr>
                <td>{{ $member['name'] }}</td>
                <td>{{ $member['bjcpId'] ?? '' }}</td>
                <td>{{ $member['points'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
