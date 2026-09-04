{{-- Judge/participant notes printout. Three sections, legacy
     judge_notes.output.php go=org_notes|allergens|admin.
     $rows = org_notes objects; $entries = allergens/admin array rows. --}}
<h1>{{ $contestName }} —
    @if ($section === 'org_notes')
        Notes to Organizer
    @elseif ($section === 'allergens')
        Possible Allergens
    @else
        Notes
    @endif
</h1>

@if ($section === 'org_notes')
    @if (count($rows) > 0)
        <table width="100%" border="1" cellpadding="4" cellspacing="0">
            <tr><th width="30%">Name</th><th>Notes</th></tr>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->brewerLastName }}, {{ $row->brewerFirstName }}</td>
                    <td>{{ $row->brewerJudgeNotes }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p>No organizer notes found.</p>
    @endif
@else
    @if (count($entries) > 0)
        <table width="100%" border="1" cellpadding="4" cellspacing="0">
            <tr>
                <th>Entry #</th>
                <th>Judging #</th>
                <th>Style</th>
                <th>Table</th>
                @if ($section === 'allergens')
                    <th>Possible Allergens</th>
                @else
                    <th>Admin Notes</th>
                    <th>Staff Notes</th>
                @endif
            </tr>
            @foreach ($entries as $entry)
                <tr>
                    <td>{{ sprintf('%06s', $entry['id']) }}</td>
                    <td>{{ sprintf('%06s', $entry['brewJudgingNumber']) }}</td>
                    <td>{{ $entry['brewStyle'] }}</td>
                    <td>
                        @if ($entry['tableNumber'] !== null)
                            {{ $entry['tableNumber'] }}: {{ $entry['tableName'] }}
                            @if ($showFlight)
                                <br>Round {{ $entry['flightRound'] }}
                                <br>Flight {{ $entry['flightNumber'] }}
                            @endif
                        @endif
                    </td>
                    @if ($section === 'allergens')
                        <td>{{ $entry['brewPossAllergens'] }}</td>
                    @else
                        <td>{{ $entry['brewAdminNotes'] }}</td>
                        <td>{{ $entry['brewStaffNotes'] }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @else
        <p>No entries found.</p>
    @endif
@endif
