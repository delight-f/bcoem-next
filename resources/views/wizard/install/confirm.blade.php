@extends('wizard.layout')

@section('title', 'Confirm')
@section('step', 'Step 5 of 6')
@section('heading', 'Everything look right?')

@section('content')
    <div id="install-start">
        <dl class="row mb-4">
            <dt class="col-sm-4">Web address</dt>
            <dd class="col-sm-8">{{ $site['app_url'] }}</dd>

            <dt class="col-sm-4">Database</dt>
            <dd class="col-sm-8">{{ $db['database'] }} on {{ $db['host'] }}:{{ $db['port'] }}</dd>

            <dt class="col-sm-4">Database user</dt>
            <dd class="col-sm-8">{{ $db['username'] }}</dd>

            <dt class="col-sm-4">Database password</dt>
            <dd class="col-sm-8">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</dd>

            <dt class="col-sm-4">Your name</dt>
            <dd class="col-sm-8">{{ $site['admin_name'] }}</dd>

            <dt class="col-sm-4">Your email</dt>
            <dd class="col-sm-8">{{ $site['admin_email'] }}</dd>

            <dt class="col-sm-4">Your password</dt>
            <dd class="col-sm-8">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</dd>
        </dl>

        <div class="alert alert-info" role="alert">
            Clicking &ldquo;Install Now&rdquo; is the only step that changes anything. If something
            goes wrong, nothing is left half-finished in a way you cannot fix &mdash; you can correct
            the problem and try again.
        </div>

        <div class="d-flex gap-2 mt-4">
            <a href="{{ route('wizard.install.site') }}" class="btn btn-outline-secondary">Back</a>
            <button type="button" class="btn btn-primary btn-lg" id="install-now"
                    data-run-endpoint="{{ route('wizard.install.run') }}"
                    data-progress-endpoint="{{ route('wizard.install.progress') }}"
                    data-site-url="{{ $site['app_url'] }}"
                    data-admin-email="{{ $site['admin_email'] }}">Install Now</button>
        </div>
    </div>

    @include('wizard.install.progress')
@endsection
