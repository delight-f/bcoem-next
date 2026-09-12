{{-- Screen 4: polling progress. On failure the primary action is a pre-filled
     "Contact support" link, never a retry — re-running upgrade() against a
     half-migrated schema is the risk this screen exists to avoid. --}}
<div id="upgrade-progress" class="d-none">
    <div id="upgrade-running" class="text-center py-3">
        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
        <p class="mt-3 fs-5" id="upgrade-step" role="status" aria-live="polite">Starting&hellip;</p>
        <div class="progress wizard-progress mt-3">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary w-100"></div>
        </div>
    </div>

    <div id="upgrade-done" class="d-none text-center py-3">
        <p class="display-6 text-success">&#10004;</p>
        <h2 class="h4">Your site is back online</h2>
        <p>
            Updated from version <strong>{{ $current !== '' ? $current : 'unknown' }}</strong>
            to version <strong>{{ $incoming }}</strong>.
        </p>
        <a class="btn btn-primary btn-lg mt-2" href="{{ url('/') }}">Go to your site</a>
    </div>

    <div id="upgrade-failed" class="d-none py-3">
        <p class="fs-5" role="alert">
            The update did not finish. <strong>Your data is backed up</strong> &mdash; it is safe.
        </p>
        <p id="upgrade-failed-plain"></p>
        <p>
            Your site may be showing a maintenance message until this is fixed. Please do not start
            the update again; contact support with the details below and they will sort it out.
        </p>

        <div class="alert alert-warning">
            <p class="mb-1"><strong>Backup file location</strong> (copy this exactly):</p>
            <code id="upgrade-backup-path" class="d-block text-break">Ask support &mdash; the path is in the details below.</code>
        </div>

        <a class="btn btn-primary btn-lg" id="upgrade-support" href="#" role="button">Contact support</a>

        <details class="mt-3">
            <summary class="text-muted">Technical details</summary>
            <pre class="small text-wrap bg-body-secondary p-3 mt-2 mb-0" id="upgrade-failed-technical"></pre>
        </details>
    </div>
</div>

<script>
    (() => {
        const button = document.getElementById('upgrade-now');
        if (!button) { return; }

        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const start = document.getElementById('upgrade-start');
        const panel = document.getElementById('upgrade-progress');
        const running = document.getElementById('upgrade-running');
        const done = document.getElementById('upgrade-done');
        const failed = document.getElementById('upgrade-failed');
        const step = document.getElementById('upgrade-step');
        const failedPlain = document.getElementById('upgrade-failed-plain');
        const failedTechnical = document.getElementById('upgrade-failed-technical');
        const backupPath = document.getElementById('upgrade-backup-path');
        const support = document.getElementById('upgrade-support');

        const token = Array.from(crypto.getRandomValues(new Uint8Array(24)))
            .map((b) => b.toString(16).padStart(2, '0')).join('');

        const showFailure = (error) => {
            error = error || {};
            running.classList.add('d-none');
            failed.classList.remove('d-none');

            const plain = error.plain || 'Something went wrong while applying the update.';
            const technical = error.technical || '';
            failedPlain.textContent = plain;
            failedTechnical.textContent = technical;
            if (error.backup_path) { backupPath.textContent = error.backup_path; }

            const body = [
                'The update from version ' + button.dataset.current + ' to ' + button.dataset.incoming + ' did not finish.',
                '',
                plain,
                error.backup_path ? 'Backup file: ' + error.backup_path : '',
                '',
                'Technical details:',
                technical,
            ].filter((line) => line !== '').join('\n');

            support.href = 'mailto:' + button.dataset.supportEmail
                + '?subject=' + encodeURIComponent('Update problem on my competition site')
                + '&body=' + encodeURIComponent(body);
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
                showFailure(marker.error);
                return;
            }
            setTimeout(poll, 1500);
        };

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
                    showFailure({ plain: data.error || 'We could not start the update.' });
                }
            }).catch(() => showFailure({ plain: 'We could not start the update. Check your connection and try again.' }));
        });
    })();
</script>
