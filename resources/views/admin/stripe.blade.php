<x-public-layout :ctx="$ctx" :salutation="$salutation" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Stripe Connect</h1>

        <p>
            Connect the competition's own Stripe account. Payments are
            collected directly by you (Stripe "Standard" connected account);
            this platform never holds funds.
        </p>

        <ul>
            <li>Connected account:
                @if ($accountId)
                    <code>{{ $accountId }}</code>
                @else
                    <strong class="text-danger">not connected</strong>
                @endif
            </li>
            <li>Platform secret key: {{ $secretKeySet ? 'configured' : 'missing' }}</li>
            <li>OAuth client id: {{ $clientIdSet ? 'configured' : 'missing' }}</li>
            <li>Webhook signing secret: {{ $webhookSecretSet ? 'saved' : 'not set' }}</li>
        </ul>

        @if ($clientIdSet && $secretKeySet)
            <a class="btn btn-primary" href="{{ route('admin.stripe.connect') }}">Connect with Stripe</a>
        @else
            <p class="text-muted">Add the platform keys on the
                <a href="{{ route('admin.payments.setup') }}">Payment Setup</a> screen to enable connecting.</p>
        @endif

        @if ($accountId && ! $webhookSecretSet)
            <p class="alert alert-warning mt-3">Stripe is connected but no webhook signing secret is saved.
                Checkout stays disabled until it is set — without it the platform cannot mark entries
                paid after a payment, so an entrant could be charged with their entries left unpaid.</p>
        @endif

        <h2 class="mt-6">Webhook endpoint</h2>
        <p>
            Create a webhook endpoint in your Stripe dashboard pointing at
            <code>{{ url('/webhooks/stripe') }}</code>, subscribed to
            <code>checkout.session.completed</code>,
            <code>checkout.session.expired</code> and
            <code>charge.refunded</code>, then paste the signing secret here.
        </p>
        <form method="post" action="{{ route('admin.stripe.secret') }}" class="row g-2">
            @csrf
            <div class="col-auto">
                <input type="text" name="webhook_secret" class="form-control"
                       placeholder="whsec_..." value="" aria-label="Webhook signing secret">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary">Save signing secret</button>
            </div>
        </form>
    </section>
</x-public-layout>
