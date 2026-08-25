<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-4 mb-3">
        <h1>Test Email</h1>
        <p>Sending a test email to {{ $email }}</p>

        <ul class="list-unstyled">
            <li><strong>Originating Email Address:</strong> {{ $settings['from'] }}</li>
            <li><strong>Host:</strong> {{ $settings['host'] }}</li>
            <li><strong>Username:</strong> {{ $settings['username'] }}</li>
            <li><strong>Encryption:</strong> {{ $settings['encryption'] }}</li>
            <li><strong>Port:</strong> {{ $settings['port'] }}</li>
        </ul>

        @if ($sent === true)
            <div class="alert alert-success">Test email sent. If it does not arrive, close this window and check your settings, especially your password.</div>
        @elseif ($sent === false)
            <div class="alert alert-danger">
                Message could not be sent. Mailer Error:
                <pre>{{ $error }}</pre>
            </div>
        @endif
    </section>
</x-public-layout>
