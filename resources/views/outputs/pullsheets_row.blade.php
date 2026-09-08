@php $cat = $row['category'] !== '' ? '<em><br>'.$row['category'].'</em>' : ''; @endphp
<tr>
    <td><p>&nbsp;</p></td>
    <td class="no">{{ $row['numCol'] }}</td>
    <td>
        @if ($styleSet === 'BA')
            {{ $row['brewStyle'] }}
        @else
            {{ $row['styleNum'] }} {{ $row['brewStyle'] }}{!! $cat !!}
        @endif
    </td>
    <td>
        @foreach ($row['info'] as $info)
            <p class="info"><strong>{{ $info['label'] }}:</strong> {{ $info['value'] }}</p>
        @endforeach
    </td>
    <td>{{ $row['box'] }}</td>
    @if (! $miniBos)
        <td><p class="box_small">&nbsp;</p></td>
    @endif
    <td><p>&nbsp;</p></td>
    <td><p>&nbsp;</p></td>
</tr>
