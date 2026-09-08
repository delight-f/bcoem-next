{{-- Paper entry forms: one sheet per received entry. Fields mirror the
     deprecated legacy bcoem-entry templates (entry/judging numbers, style,
     brewer, special ingredients ^-joined, mead attributes, paid marker). --}}
@foreach ($entries as $entry)
<div style="page-break-after: always;">
    <h2>Paper Entry Form</h2>
    <table width="100%" cellpadding="6" cellspacing="0">
        <tr>
            <td width="33%"><strong>Entry #</strong><br>{{ sprintf('%06s', $entry->id) }}</td>
            <td width="33%"><strong>Judging #</strong><br>{{ sprintf('%06s', $entry->brewJudgingNumber) }}</td>
            <td width="34%"><strong>Status</strong><br>
                @if ($entry->brewPaid == 1)*** PAID ***@else UNPAID @endif
            </td>
        </tr>
    </table>

    <table width="100%" cellpadding="6" cellspacing="0">
        <tr>
            <td width="50%"><strong>Entry Name</strong><br>{{ $entry->brewName }}</td>
            <td width="50%"><strong>Style</strong><br>
                {{ $entry->brewCategory }}-{{ $entry->brewSubCategory }} {{ $entry->brewStyle }}
            </td>
        </tr>
        <tr>
            <td><strong>Brewer</strong><br>
                {{ $entry->brewerFirstName }} {{ $entry->brewerLastName }}
                @if ($entry->brewCoBrewer)<br>Co-brewer: {{ $entry->brewCoBrewer }}@endif
            </td>
            <td><strong>Club / Email</strong><br>
                {{ $entry->brewerClubs }}<br>{{ $entry->brewerEmail }}
            </td>
        </tr>
        <tr>
            <td colspan="2"><strong>Special Ingredient Information</strong><br>
                {!! str_replace('^', ' | ', e((string) $entry->brewInfo)) !!}
            </td>
        </tr>
        <tr>
            <td><strong>Sparkling</strong><br>{{ $entry->brewMead1 }}</td>
            <td><strong>Sweetness</strong><br>{{ $entry->brewMead2 }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Mead / Cider Type</strong><br>{{ $entry->brewMead3 }}</td>
        </tr>
    </table>
</div>
@endforeach
