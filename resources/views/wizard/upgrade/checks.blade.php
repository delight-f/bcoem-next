@extends('wizard.layout')

@section('title', 'System check')
@section('step', 'Step 2 of 4')
@section('heading', 'Checking your server')

@section('content')
    <p class="text-muted">This also checks there is enough free space for the automatic backup.</p>

    <ul class="list-unstyled mb-0" id="check-list" data-endpoint="{{ $mode === 'auto' ? route('wizard.upgrade.checks', ['mode' => 'auto']) : route('wizard.upgrade.checks') }}">
        @foreach ($result->checks as $check)
            <li class="mb-3">
                <span class="me-2 {{ $check->passed ? 'text-success' : 'text-danger' }}">
                    {{ $check->passed ? '✔' : '✘' }}
                </span>
                <span>{{ $check->message }}</span>
            </li>
        @endforeach
    </ul>

    <div class="d-flex gap-2 mt-4">
        <button type="button" class="btn btn-outline-secondary" id="recheck">Recheck</button>
        <a href="{{ $mode === 'auto' ? route('wizard.upgrade.confirm', ['mode' => 'auto']) : route('wizard.upgrade.confirm') }}"
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

        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const response = await fetch(list.dataset.endpoint, { headers: { Accept: 'application/json' } });
                const data = await response.json();

                list.replaceChildren(...data.checks.map((check) => {
                    const li = document.createElement('li');
                    li.className = 'mb-3';
                    li.innerHTML = '<span class="me-2 ' + (check.passed ? 'text-success' : 'text-danger') + '">'
                        + (check.passed ? '✔' : '✘') + '</span>';
                    const text = document.createElement('span');
                    text.textContent = check.message;
                    li.append(text);
                    return li;
                }));

                next.classList.toggle('disabled', !data.passed);
                next.setAttribute('aria-disabled', data.passed ? 'false' : 'true');
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
@endpush
