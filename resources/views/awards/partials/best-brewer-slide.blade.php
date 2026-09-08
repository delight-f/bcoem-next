{{--
    Best Brewer / Best Club ranked-table slide (legacy awards.php
    table_head1/table_body1 and table_head2/table_body2 blocks).
    Row objects carry: name, club, points, places[0..4], position (rank,
    ties share), fh (fragment order = running index).
--}}
<section>
    <h1 class="r-fit-text tight">{{ $slide->title }}</h1>
    <p class="entry-count">{{ $slide->participantLine }} <span class="small entry-count">[<a href="#" data-scoring-method>Scoring Methodology</a>]</span></p>

    <table style="width: 100%; font-size: .55em;">
        <thead>
        <tr>
            <th nowrap>Place</th>
            <th>{{ $slide->showClub ? 'Club' : 'Brewer' }}</th>
            <th>1st</th>
            <th>2nd</th>
            <th>3rd</th>
            @if ($slide->show4th)<th>4th</th>@endif
            @if ($slide->showHm)<th>HM</th>@endif
            <th nowrap>Score</th>
            @if (! $slide->showClub && ! $slide->proEdition)<th>Club</th>@endif
        </tr>
        </thead>
        <tbody>
        @foreach ($slide->rows as $row)
            <tr class="fragment" data-fragment-index="{{ $row->fh }}">
                <td class="no-bottom-border" nowrap>{{ \App\Support\Outputs\OutputFormat::ordinal($row->position) }}</td>
                <td class="no-bottom-border">{{ $row->name }}</td>
                <td class="no-bottom-border" nowrap>{{ $row->places[0] }}</td>
                <td class="no-bottom-border" nowrap>{{ $row->places[1] }}</td>
                <td class="no-bottom-border" nowrap>{{ $row->places[2] }}</td>
                @if ($slide->show4th)<td class="no-bottom-border" nowrap>{{ $row->places[3] }}</td>@endif
                @if ($slide->showHm)<td class="no-bottom-border" nowrap>{{ $row->places[4] }}</td>@endif
                <td align="right" class="no-bottom-border" nowrap>{{ number_format($row->points, 7) }}</td>
                @if (! $slide->showClub && ! $slide->proEdition)<td class="no-bottom-border">{{ $row->club }}</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
</section>