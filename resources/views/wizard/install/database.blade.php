@extends('wizard.layout')

@section('title', 'Database connection')
@section('step', 'Step 3 of 6')
@section('heading', 'Connecting to your database')

@section('content')
    <p class="text-muted">
        These details come from your hosting control panel or the welcome email your
        host sent when the database was created.
    </p>

    <form method="POST" action="{{ route('wizard.install.database.store') }}" id="db-form"
          data-test-endpoint="{{ route('wizard.install.test') }}">
        @csrf

        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label" for="host">Database host</label>
                <input class="form-control" id="host" name="host" value="{{ old('host', $values['host'] ?? 'localhost') }}" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="port">Port</label>
                <input class="form-control" id="port" name="port" value="{{ old('port', $values['port'] ?? '3306') }}" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="database">Database name</label>
            <input class="form-control" id="database" name="database" value="{{ old('database', $values['database'] ?? '') }}" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="username">Database username</label>
            <input class="form-control" id="username" name="username" value="{{ old('username', $values['username'] ?? '') }}" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Database password</label>
            <input class="form-control" type="password" id="password" name="password" value="{{ old('password', $values['password'] ?? '') }}">
        </div>

        <div id="test-result" class="mt-3" role="status" aria-live="polite"></div>

        <div class="d-flex gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" id="test">Test Connection</button>
            <button type="submit" class="btn btn-primary" id="next" disabled>Next</button>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.getElementById('db-form');
        const button = document.getElementById('test');
        const next = document.getElementById('next');
        const result = document.getElementById('test-result');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        button.addEventListener('click', async () => {
            button.disabled = true;
            result.className = 'mt-3 text-muted';
            result.textContent = 'Checking…';

            try {
                const response = await fetch(form.dataset.testEndpoint, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: new FormData(form),
                });
                const data = await response.json();

                result.textContent = data.message;
                result.className = 'mt-3 ' + (data.success ? 'text-success' : 'text-danger');
                next.disabled = !data.success;
            } catch (error) {
                result.textContent = 'We could not run the check. Please try again.';
                result.className = 'mt-3 text-danger';
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
@endpush
