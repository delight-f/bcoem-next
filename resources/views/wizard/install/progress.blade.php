{{-- Screen 6: polling progress. Hidden until "Install Now" is pressed; the
     request that runs install() is dispatched in the background while this
     polls the shared progress marker. --}}
<div id="install-progress" class="d-none">
    <div id="install-running" class="text-center py-3">
        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
        <p class="mt-3 fs-5" id="install-step" role="status" aria-live="polite">Starting&hellip;</p>
        <div class="progress wizard-progress mt-3">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary w-100"></div>
        </div>
    </div>

    <div id="install-done" class="d-none text-center py-3">
        <p class="display-6 text-success">&#10004;</p>
        <h2 class="h4">Your site is ready</h2>
        <p>
            Visit <a href="{{ $site['app_url'] }}">{{ $site['app_url'] }}</a> and log in with
            <strong>{{ $site['admin_email'] }}</strong>. Your password is the one you just chose.
        </p>
        <a class="btn btn-primary btn-lg mt-2" href="{{ $site['app_url'] }}">Go to your site</a>
    </div>

    <div id="install-failed" class="d-none py-3">
        <p class="fs-5 text-danger" id="install-failed-plain" role="alert"></p>
        <p>Nothing was changed, so it is safe to fix the problem and try again.</p>
        <details>
            <summary class="text-muted">Technical details</summary>
            <pre class="small text-wrap bg-body-secondary p-3 mt-2 mb-0" id="install-failed-technical"></pre>
        </details>
        <button type="button" class="btn btn-outline-primary mt-3" id="install-retry">Try again</button>
    </div>
</div>

<script>
    (() => {
        const button = document.getElementById('install-now');
        if (!button) { return; }

        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const start = document.getElementById('install-start');
        const panel = document.getElementById('install-progress');
        const running = document.getElementById('install-running');
        const done = document.getElementById('install-done');
        const failed = document.getElementById('install-failed');
        const step = document.getElementById('install-step');
        const failedPlain = document.getElementById('install-failed-plain');
        const failedTechnical = document.getElementById('install-failed-technical');

        const token = Array.from(crypto.getRandomValues(new Uint8Array(24)))
            .map((b) => b.toString(16).padStart(2, '0')).join('');

        const showFailure = (plain, technical) => {
            running.classList.add('d-none');
            failed.classList.remove('d-none');
            failedPlain.textContent = plain || 'Something went wrong while installing your site.';
            failedTechnical.textContent = technical || '';
        };

        const poll = async () => {
            let marker;
            try {
                const response = await fetch(button.dataset.progressEndpoint + '?token=' + token, {
                    headers: { Accept: 'application/json' },
                });
                if (response.status === 404) { setTimeout(poll, 1500); return; }
                marker = await response.json();
            } catch (error) {
                setTimeout(poll, 2500);
                return;
            }

            if (marker.current_step_label) { step.textContent = marker.current_step_label; }

            if (marker.status === 'complete') {
                running.classList.add('d-none');
                done.classList.remove('d-none');
                return;
            }
            if (marker.status === 'failed') {
                showFailure(marker.error && marker.error.plain, marker.error && marker.error.technical);
                return;
            }
            setTimeout(poll, 1500);
        };

        document.getElementById('install-retry').addEventListener('click', () => window.location.reload());

        button.addEventListener('click', () => {
            start.classList.add('d-none');
            panel.classList.remove('d-none');
            poll();

            fetch(button.dataset.runEndpoint, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: new URLSearchParams({ token }),
            }).then(async (response) => {
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    showFailure(data.error || 'We could not start the installation.', '');
                }
            }).catch(() => showFailure('We could not start the installation. Check your connection and try again.', ''));
        });
    })();
</script>
