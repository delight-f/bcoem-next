@extends('wizard.layout')

@section('title', 'Your site is attached')
@section('step', 'Last step')
@section('heading', 'Your existing site is attached')

@section('content')
    @php($dbLabel = $databaseVersion !== '' ? $databaseVersion : 'an earlier version')

    <p>
        This installation is now using your existing database. Your competition,
        entries, members and results have not been changed.
    </p>

    @if ($hasUpdate)
        {{-- The update is offered here, not deferred: nobody can sign in yet, so
             the dashboard prompt is unreachable at this point. --}}
        <div id="upgrade-start">
            <p class="mb-4">
                One step is left, and it can be done right here. The database is at
                version <strong>{{ $dbLabel }}</strong> while these files are
                <strong>{{ $releaseVersion }}</strong>, so the data has to be
                brought forward.
            </p>

            <ul>
                <li>Your data is backed up automatically before anything is changed.</li>
                <li>The site will be briefly unavailable while the update runs.</li>
                <li>This page will show you each step and tell you when the site is back online.</li>
            </ul>

            <div class="form-check my-4">
                <input class="form-check-input" type="checkbox" id="update-ack">
                <label class="form-check-label" for="update-ack">
                    I understand this site will be briefly unavailable.
                </label>
            </div>

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-lg" id="upgrade-now" disabled
                        data-run-endpoint="{{ route('wizard.install.update') }}"
                        data-progress-endpoint="{{ route('wizard.install.update_progress') }}"
                        data-site-url="{{ url('/') }}"
                        data-current="{{ $databaseVersion }}"
                        data-incoming="{{ $releaseVersion }}"
                        data-support-email="{{ $supportEmail }}">Update Now</button>
                <a href="{{ route('login') }}" class="btn btn-outline-secondary btn-lg">Sign in instead</a>
            </div>
        </div>

        @include('wizard.upgrade.progress', ['current' => $databaseVersion, 'incoming' => $releaseVersion])
    @else
        <p class="mb-4">
            The database is already at version <strong>{{ $dbLabel }}</strong>, so
            there is nothing to update.
        </p>

        <a href="{{ $isTopLevelAdmin ? url('/') : route('login') }}" class="btn btn-primary btn-lg">
            {{ $isTopLevelAdmin ? 'Go to your site' : 'Sign in' }}
        </a>
    @endif

    <p class="mt-4 mb-0 text-muted small">
        Nothing is at risk either way &mdash; the update backs up the database
        before it changes anything.
    </p>
@endsection

@if ($hasUpdate)
    @push('scripts')
    <script>
        document.getElementById('update-ack').addEventListener('change', (event) => {
            document.getElementById('upgrade-now').disabled = !event.target.checked;
        });
    </script>
    @endpush
@endif
