@extends('wizard.layout')

@section('title', 'Confirm')
@section('step', 'Step 3 of 4')
@section('heading', 'Ready to update')

@section('content')
    <div id="upgrade-start">
        <p>
            Your site will be updated from version <strong>{{ $current !== '' ? $current : 'unknown' }}</strong>
            to version <strong>{{ $incoming }}</strong>.
        </p>

        <ul>
            <li>Your data is backed up automatically before anything is changed.</li>
            <li>The site will be briefly unavailable while the update runs.</li>
            <li>This page will show you each step and tell you when the site is back online.</li>
        </ul>

        <div class="form-check my-4">
            <input class="form-check-input" type="checkbox" id="upgrade-ack">
            <label class="form-check-label" for="upgrade-ack">
                I understand this site will be briefly unavailable.
            </label>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('wizard.upgrade.checks') }}" class="btn btn-outline-secondary">Back</a>
            <button type="button" class="btn btn-primary btn-lg" id="upgrade-now" disabled
                    data-run-endpoint="{{ route('wizard.upgrade.run') }}"
                    data-progress-endpoint="{{ route('wizard.upgrade.progress') }}"
                    data-site-url="{{ url('/') }}"
                    data-current="{{ $current }}"
                    data-incoming="{{ $incoming }}"
                    data-support-email="{{ $supportEmail }}">Upgrade Now</button>
        </div>
    </div>

    @include('wizard.upgrade.progress')
@endsection

@push('scripts')
<script>
    document.getElementById('upgrade-ack').addEventListener('change', (event) => {
        document.getElementById('upgrade-now').disabled = !event.target.checked;
    });
</script>
@endpush
