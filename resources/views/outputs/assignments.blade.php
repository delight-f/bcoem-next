{{-- Judge/steward assignment roster + bull pen (legacy assignments.output.php). --}}
<h1>{{ $roleLabel }} Assignments<br><small>{{ $contestName }}</small></h1>

@if (count($rows) > 0)
    <table width="100%" border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th>Name</th>
            <th>Role</th>
            <th>Rank</th>
            <th>Session</th>
            <th width="5%">Table #</th>
            <th>Table Name</th>
            <th width="5%">Round</th>
            @if ($showFlight)<th width="5%">Flight</th>@endif
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td><strong>{{ $row['name'] }}</strong><br><small>{{ $row['club'] }}</small></td>
                <td>{{ $row['roles'] }}</td>
                <td>{{ $row['rank'] }}</td>
                <td>{{ $row['location'] }}</td>
                <td>{{ $row['tableNumber'] }}</td>
                <td>{{ $row['tableName'] }}</td>
                <td>{{ $row['round'] }}</td>
                @if ($showFlight)<td>{{ $row['flight'] }}</td>@endif
            </tr>
        @endforeach
    </table>
@else
    <p>No {{ strtolower($roleLabel) }} assignments found.</p>
@endif

<div style="page-break-after: always;">&nbsp;</div>

<h1>Bull Pen</h1>
@if (count($bullPen) > 0)
    <p>These {{ strtolower($roleLabel) }}s have not been assigned to any table.</p>
    <table width="100%" border="1" cellpadding="4" cellspacing="0">
        <tr><th>Name</th><th>Rank</th></tr>
        @foreach ($bullPen as $judge)
            <tr><td>{{ $judge['name'] }}</td><td>{{ $judge['rank'] }}</td></tr>
        @endforeach
    </table>
@else
    <p>All {{ strtolower($roleLabel) }}s are assigned.</p>
@endif
