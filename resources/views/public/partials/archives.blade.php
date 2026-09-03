@if ($archives !== [])
    <div class="mt-6 reveal-element">
        <h2>{{ __('site.past_winners') }}</h2>
        <ul class="nav flex-column">
            @foreach ($archives as $archive)
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('past-winners', ['filter' => $archive->archiveSuffix]) }}">
                        {{ $archive->archiveSuffix }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
