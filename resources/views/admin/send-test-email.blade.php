<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Test Email</h1>
        <p>Sending a test email to {{ $email }}</p>

        <ul class="list-unstyled">
            <li><strong>Transport:</strong> {{ $settings['transport'] }}</li>
            @if ($settings['program'] !== '')
                <li><strong>Mail Program:</strong> <code>{{ $settings['program'] }}</code></li>
            @endif
            <li><strong>Originating Email Address:</strong> {{ $settings['from'] }}</li>
            <li><strong>Host:</strong> {{ $settings['host'] }}</li>
            <li><strong>Username:</strong> {{ $settings['username'] }}</li>
            <li><strong>Encryption:</strong> {{ $settings['encryption'] }}</li>
            <li><strong>Port:</strong> {{ $settings['port'] }}</li>
        </ul>

        @if ($sent === true)
            <div class="alert alert-success">Test email sent. If it does not arrive, close this window and check your settings, especially your password.</div>
        @elseif ($error !== null)
            <div class="alert alert-danger">
                Message could not be sent. Mailer Error:
                <pre>{{ $error }}</pre>
            </div>
        @elseif ($sent === false)
            {{-- The mailer accepted the message but only logged it: saying
                 "sent" here would be a lie the admin cannot detect. --}}
            <div class="alert alert-warning">
                No error was raised, but the current settings do not deliver mail —
                the test message was written to the application log instead. Choose a
                transport under Email Sending in Site Preferences.
            </div>
        @endif
    </section>
</x-public-layout>
