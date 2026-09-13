@extends('wizard.layout')

@section('title', 'Database connection')
@section('step', 'Step 3 of 6')
@section('heading', 'Connecting to your database')

@section('content')
    <p class="text-muted">
        These details come from your hosting control panel or the welcome email your
        host sent when the database was created.
    </p>

    @error('database')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('wizard.install.database.store') }}" id="db-form"
          data-test-endpoint="{{ route('wizard.install.test') }}"
          data-adopt-endpoint="{{ route('wizard.install.adopt') }}">
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
        <div id="privilege-warning" class="alert alert-warning mt-3 d-none" role="alert"></div>

        <div class="d-flex gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" id="test">Test Connection</button>
            <button type="submit" class="btn btn-primary" id="next" disabled>Next</button>
            <button type="button" class="btn btn-primary d-none" id="adopt">Use this existing site</button>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.getElementById('db-form');
        const button = document.getElementById('test');
        const next = document.getElementById('next');
        const adopt = document.getElementById('adopt');
        const result = document.getElementById('test-result');
        const warning = document.getElementById('privilege-warning');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        button.addEventListener('click', async () => {
            button.disabled = true;
            result.className = 'mt-3 text-muted';
            result.textContent = 'Checking…';
            next.disabled = true;
            adopt.classList.add('d-none');
            warning.classList.add('d-none');

            try {
                const response = await fetch(form.dataset.testEndpoint, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: new FormData(form),
                });
                const data = await response.json();

                result.textContent = data.message;
                next.disabled = !data.canInstall;
                adopt.classList.toggle('d-none', !data.canAdopt);
                warning.classList.toggle('d-none', !data.warning);
                warning.textContent = data.warning || '';

                // Green only when the check found a database this screen can act
                // on: either empty (install ahead) or a finished site (attach).
                result.className = 'mt-3 ' + (data.success && (data.canInstall || data.canAdopt) ? 'text-success' : 'text-danger');
            } catch (error) {
                result.textContent = 'We could not run the check. Please try again.';
                result.className = 'mt-3 text-danger';
            } finally {
                button.disabled = false;
            }
        });

        // Screen 3's other exit: same details, different destination.
        adopt.addEventListener('click', () => {
            form.action = form.dataset.adoptEndpoint;
            form.submit();
        });
    })();
</script>
@endpush
