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

    <p class="mb-4">
        One step is left, and it needs an administrator: the database is still at
        version <strong>{{ $dbLabel }}</strong> while these files are
        <strong>{{ $releaseVersion }}</strong>, so the data has to be brought
        forward.
    </p>

    @if ($isTopLevelAdmin)
        <p>You are signed in as an administrator, so the update is waiting for you.</p>
        <a href="{{ route('wizard.upgrade.whats_new') }}" class="btn btn-primary btn-lg">Continue to the update</a>
    @else
        <ol class="mb-4">
            <li>Sign in as an administrator.</li>
            <li>Take the <strong>Update your site</strong> prompt that then appears at the top of every page.</li>
        </ol>

        <a href="{{ route('login') }}" class="btn btn-primary btn-lg">Sign in</a>
    @endif

    <p class="mt-4 mb-0 text-muted small">
        Nothing is at risk either way &mdash; the update backs up the database
        before it changes anything.
    </p>
@endsection
