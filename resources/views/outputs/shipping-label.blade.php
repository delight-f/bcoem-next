{{-- Shipping labels — two identical half-sheet blocks per participant
     (legacy prints the same label twice on one sheet). --}}
@foreach ($brewers as $brewer)
<div style="height: 400px;">
    <table width="100%" cellspacing="0" cellpadding="0"><tr>
        <td width="45%">
            <p style="font-size: 14pt;">
                @if ($brewer->brewerBreweryName){{ $brewer->brewerBreweryName }}<br>@endif
                {{ $brewer->brewerFirstName }} {{ $brewer->brewerLastName }}
            </p>
            <p>
                {{ $brewer->brewerAddress }}<br>
                {{ $brewer->brewerCity }}, {{ $brewer->brewerState }} {{ $brewer->brewerZip }}
                @if ((string) $brewer->brewerCountry !== 'United States')<br>{{ $brewer->brewerCountry }}@endif
            </p>
        </td>
        <td width="55%">&nbsp;</td>
    </tr></table>
    <div style="height: 75px;">&nbsp;</div>
    <table width="100%" cellspacing="0" cellpadding="0"><tr>
        <td width="25%">&nbsp;</td>
        <td width="75%">
            <h3>{{ $shippingName }}</h3>
            <p style="font-size: 14pt;">{{ $shippingAddress }}</p>
        </td>
    </tr></table>
</div>
<hr>
@endforeach
