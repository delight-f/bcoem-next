{{-- Legacy admin/entries.admin.php action=print (:988-1027): the entries table
     as an HTML print view — no Actions column, no hidden-print columns,
     server-side psort ordering, self-print on load. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $header }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 13px; margin: 20px; }
        h1 { font-size: 24px; margin-bottom: 15px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; font-weight: bold; }
        tr:nth-child(even) { background: #fafafa; }
    </style>
</head>
<body onload="setTimeout(function(){ window.print(); }, 300);">
<h1>{{ $header }}</h1>
<table class="table table-responsive table-bordered" id="sortable">
    <thead>
    <tr>
        <th nowrap>Entry</th>
        <th nowrap>Judging</th>
        <th>Name</th>
        <th>Style</th>
        <th>{{ $proEdition ? 'Organization' : 'Brewer' }}</th>
        @unless ($proEdition)
            <th>Club</th>
        @endunless
        <th>Updated</th>
        <th>Paid?</th>
        <th>Rec'd?</th>
        <th>Admin Notes</th>
        <th>Staff Notes</th>
        <th>Loc/Box</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($entries as $e)
        <tr>
            <td>{{ sprintf('%06s', $e->id) }}</td>
            <td nowrap="nowrap">{{ strtoupper((string) $e->brewJudgingNumber) }}</td>
            <td>{{ $e->brewName }}</td>
            <td>
                <span class="hidden">{{ $e->brewCategorySort.$e->brewSubCategory }}</span>
                {{ $e->brewCategorySort.$e->brewSubCategory.' - '.$e->brewStyle }}
            </td>
            <td nowrap="nowrap">{{ $e->brewerFirstName.' '.$e->brewerLastName }}</td>
            @unless ($proEdition)
                <td>{{ $e->brewerClubs }}</td>
            @endunless
            <td>{{ $e->brewUpdated }}</td>
            <td>{{ $e->brewPaid == 1 ? 'Y' : 'N' }}</td>
            <td>{{ $e->brewReceived == 1 ? 'Y' : 'N' }}</td>
            <td>{{ $e->brewAdminNotes }}</td>
            <td>{{ $e->brewStaffNotes }}</td>
            <td>{{ $e->brewBoxNum }}</td>
        </tr>
    @empty
        <tr><td colspan="12">No entries have been added to the database yet.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
