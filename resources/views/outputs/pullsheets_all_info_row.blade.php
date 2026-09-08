@php
    $cat = $row['category'] !== '' ? '<em><br>'.$row['category'].'</em>' : '';
@endphp
<tr>
    <td class="no">{{ $row['numCol'] }}</td>
    <td>
        @if ($styleSet === 'BA')
            {{ $row['brewStyle'] }}
        @else
            {{ $row['styleNum'] }} {{ $row['brewStyle'] }}{!! $cat !!}
        @endif
    </td>
    <td>@if ($row['requiredInfo'] !== '')<p class="info">{{ str_replace('^', ' | ', $row['requiredInfo']) }}</p>@endif</td>
    <td>@if ($row['optionalInfo'] !== '')<p class="info">{{ $row['optionalInfo'] }}</p>@endif</td>
    <td>@if ($row['specifics'] !== '')<p class="info">{{ $row['specifics'] }}</p>@endif</td>
    <td>@if ($row['allergens'] !== '')<p class="info">{{ $row['allergens'] }}</p>@endif</td>
    <td>@if ($row['notes'] !== '')<p class="info">{{ $row['notes'] }}</p>@endif</td>
</tr>
