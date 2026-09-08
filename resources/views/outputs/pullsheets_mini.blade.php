{{-- go=mini_bos — flat Mini-BOS pull sheet (legacy output/pullsheets.output.php mini_bos branch). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Mini-BOS Pull Sheet</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: top; text-align: left; }
    th { background-color: #eee; }
    td.no { white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }
    p.info { margin: 0 0 3px; }
</style>
</head>
<body>
<h1>Mini-BOS</h1>
@if (count($rows) > 0)
    <table>
        <thead>@include('outputs.pullsheets_thead', ['miniBos' => true])</thead>
        <tbody>
            @foreach ($rows as $row)
                @include('outputs.pullsheets_row', ['row' => $row, 'miniBos' => true, 'styleSet' => $styleSet])
            @endforeach
        </tbody>
    </table>
@else
    <p>No Mini-BOS entries were found.</p>
@endif
</body>
</html>
