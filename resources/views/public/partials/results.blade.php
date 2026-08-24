@php($repo = $suffix === null ? \App\Support\Results\ResultsRepository::current() : \App\Support\Results\ResultsRepository::forArchive($suffix))
@php($bosRows = $repo->bos())
@php($winners = $repo->winners())

<section id="results" class="landing-page-section pb-3">
    <header class="landing-page-section-header py-2"><h1>{{ __('site.results') }}</h1></header>

    <div class="mt-4 reveal-element">
        <h2>{{ __('site.bos_winners') }}</h2>
        @if ($bosRows === [])
            <p>{{ __('site.no_bos_yet') }}</p>
        @else
            <ul>
                @foreach ($bosRows as $row)
                    <li>
                        {{ \App\Support\Results\Place::label($row->scorePlace) }}:
                        {{ $row->brewName }} — {{ $row->brewerFirstName }} {{ $row->brewerLastName }}
                        ({{ $row->brewStyle }})
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-4 reveal-element">
        <h2>{{ __('site.winning_entries') }}</h2>
        @if ($winners === [])
            <p>{{ __('site.no_winners_yet') }}</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('site.place') }}</th>
                        <th>{{ __('site.entry') }}</th>
                        <th>{{ __('site.style') }}</th>
                        <th>{{ __('site.brewer') }}</th>
                        <th>{{ __('site.club') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($winners as $row)
                        <tr>
                            <td>{{ \App\Support\Results\Place::label($row->scorePlace) }}</td>
                            <td>{{ $row->brewName }}</td>
                            <td>{{ $row->brewCategorySort }}{{ $row->brewSubCategory }} {{ $row->brewStyle }}</td>
                            <td>{{ $row->brewerFirstName }} {{ $row->brewerLastName }}</td>
                            <td>{{ $row->brewerClubs }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @includeWhen($archives ?? [], 'public.partials.archives', ['archives' => $archives])
</section>
