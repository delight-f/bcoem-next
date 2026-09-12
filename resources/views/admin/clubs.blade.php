<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Clubs List</h1>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <p>
            The central homebrew clubs list is mirrored into this site so entrants
            can pick their club. Nothing is ever removed automatically &mdash; a club
            that drops off the central list stays here so existing entries keep working.
        </p>

        <dl class="row">
            <dt class="col-sm-3">Clubs stored</dt>
            <dd class="col-sm-9">{{ $total }} ({{ $upstreamCount }} from the central list)</dd>

            <dt class="col-sm-3">Last synced</dt>
            <dd class="col-sm-9">{{ $syncedAt ?? 'never' }}</dd>

            <dt class="col-sm-3">List version</dt>
            <dd class="col-sm-9">{{ $version ?? '—' }}</dd>
        </dl>

        <form method="post" action="{{ url('/admin/clubs/sync') }}" class="mb-4">
            @csrf
            <button type="submit" class="btn btn-primary">Sync clubs list now</button>
        </form>

        <h2>Clubs no longer in the central list</h2>

        @if ($dropped === [])
            <p class="text-muted">Every club from the central list is still present.</p>
        @else
            <p>
                These clubs were synced from the central list but did not appear in the
                most recent sync. They have been kept. Remove any you no longer want by
                hand.
            </p>
            <ul>
                @foreach ($dropped as $club)
                    <li>
                        {{ $club['name'] }}
                        <span class="text-muted">(last seen {{ $club['last_seen_at'] ?? 'never' }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-public-layout>
