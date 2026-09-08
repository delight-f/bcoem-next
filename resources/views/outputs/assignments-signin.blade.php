{{-- Sign-in sheets (legacy assignments.output.php view=sign-in). --}}
<h1>{{ $roleLabel }} Sign-In<br><small>{{ $contestName }}</small></h1>

@foreach ($sheets as $sheet)
    <div style="page-break-after: always;">&nbsp;</div>
    <h2>{{ $roleLabel }} Sign-In: {{ $sheet['session'] }}</h2>
    <table width="100%" border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th width="30%">Name</th>
            @if ($roleLabel === 'Judge')<th width="20%">BJCP ID</th>@endif
            <th width="10%">Waiver</th>
            <th>Signature</th>
        </tr>
        @foreach ($sheet['members'] as $member)
            <tr>
                <td>{{ $member['name'] }}</td>
                @if ($roleLabel === 'Judge')<td>{{ $member['bjcpId'] }}</td>@endif
                <td>{{ $member['waiver'] }}</td>
                <td>&nbsp;</td>
            </tr>
        @endforeach
    </table>
@endforeach

<div style="page-break-after: always;">&nbsp;</div>
<h2>{{ $roleLabel }} Sign-In</h2>
<table width="100%" border="1" cellpadding="4" cellspacing="0">
    <tr>
        <th width="30%">Name</th>
        @if ($roleLabel === 'Judge')<th width="20%">BJCP ID</th>@endif
        <th width="10%">Waiver</th>
        <th>Signature</th>
    </tr>
    @for ($i = 0; $i < $blankRows; $i++)
        <tr>
            <td>&nbsp;</td>
            @if ($roleLabel === 'Judge')<td>&nbsp;</td>@endif
            <td>Yes / No</td>
            <td>&nbsp;</td>
        </tr>
    @endfor
</table>
