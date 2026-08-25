{{-- Post-judging entry inventory: entries without a final placement, with
     their required-info declarations. Legacy:
     output/post_judge_inventory.output.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Post-Judging Entry Inventory</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #000; }
    h1 { font-size: 18px; margin: 0 0 10px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
    th { background-color: #eee; }
</style>
</head>
<body>
<h1>{{ $contestName }} Post-Judging Entry Inventory</h1>

<table>
    <thead>
    <tr>
        <th>Entry</th>
        <th>Judging</th>
        <th>Name</th>
        <th>Style</th>
        <th>Required Info</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            <td>{{ $row['entry'] }}</td>
            <td>{{ $row['judging'] }}</td>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['style'] }}</td>
            <td>{{ $row['info'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
