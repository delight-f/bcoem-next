{{-- Style reference sheet for the active style set.
     Legacy: output/styles.output.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $titleSet }} Styles</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; }
    h1 { font-size: 18px; margin: 0 0 10px 0; }
    h2 { font-size: 14px; margin: 14px 0 2px 0; }
    .cat { margin: 2px 0; }
    .spec { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .spec th, .spec td { border: 1px solid #999; padding: 2px 5px; text-align: left; }
    .spec th { background-color: #eee; }
    .swatch { border-radius: 2px; font-weight: bold; }
</style>
</head>
<body>
<h1>Accepted {{ $titleSet }} Styles</h1>

@if (count($styles) === 0)
    <p>Styles in this category are not accepted in this competition.</p>
@else
    @foreach ($styles as $style)
        <a name="{{ $style['number'] }}"></a>
        <h2>{{ $style['name'] }}</h2>

        <p class="cat"><strong>Category:</strong> {{ $style['category'] }}</p>
        <p class="cat"><strong>Number:</strong> {{ $style['number'] }}</p>

        {!! '<p>'.$style['info'].'</p>' !!}
        @if ($style['comEx'] !== '')
            <p><strong style="color:#0a6;">Commercial Examples:</strong> {{ $style['comEx'] }}</p>
        @endif
        @if ($style['entry'] !== '')
            <p><strong style="color:#c00;">Entry Instructions:</strong> {{ $style['entry'] }}</p>
        @endif

        <table class="spec">
            <tr>
                <th>OG</th><th>FG</th><th>ABV</th><th>Bitterness</th><th>Color</th>
            </tr>
            <tr>
                <td>{{ $style['og'] }}</td>
                <td>{{ $style['fg'] }}</td>
                <td>{{ $style['abv'] }}</td>
                <td>{{ $style['ibu'] }}</td>
                <td>{!! $style['srm'] !!}</td>
            </tr>
        </table>
    @endforeach
@endif
</body>
</html>
