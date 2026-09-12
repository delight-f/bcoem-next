@extends('wizard.layout')

@section('title', 'System check')
@section('step', 'Step 2 of 6')
@section('heading', 'Checking your server')

@section('content')
    <p class="text-muted">We need a few basic things to be in place before your site can run.</p>

    <ul class="list-unstyled mb-0" id="check-list" data-endpoint="{{ route('wizard.install.checks') }}">
        @foreach ($result->checks as $check)
            <li class="mb-3" data-check>
                <span class="me-2 {{ $check->passed ? 'text-success' : 'text-danger' }}">
                    {{ $check->passed ? '✔' : '✘' }}
                </span>
                <span>{{ $check->message }}</span>
            </li>
        @endforeach
    </ul>

    <div class="d-flex gap-2 mt-4">
        <button type="button" class="btn btn-outline-secondary" id="recheck">Recheck</button>
        <a href="{{ route('wizard.install.database') }}"
           id="next"
           class="btn btn-primary {{ $result->passed() ? '' : 'disabled' }}"
           aria-disabled="{{ $result->passed() ? 'false' : 'true' }}">Next</a>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const list = document.getElementById('check-list');
        const next = document.getElementById('next');
        const button = document.getElementById('recheck');

        const render = (checks) => {
            list.replaceChildren(...checks.map((check) => {
                const li = document.createElement('li');
                li.className = 'mb-3';
                li.innerHTML = '<span class="me-2 ' + (check.passed ? 'text-success' : 'text-danger') + '">'
                    + (check.passed ? '✔' : '✘') + '</span>';
                const text = document.createElement('span');
                text.textContent = check.message;
                li.append(text);
                return li;
            }));

            const passed = checks.every((check) => check.passed);
            next.classList.toggle('disabled', !passed);
            next.setAttribute('aria-disabled', passed ? 'false' : 'true');
        };

        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const response = await fetch(list.dataset.endpoint, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                render(data.checks);
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
@endpush
