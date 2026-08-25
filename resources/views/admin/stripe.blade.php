<x-public-layout :ctx="$ctx" :salutation="$salutation" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Stripe Connect</h1>

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') === 'connected' ? 'Stripe account connected.' : 'Webhook signing secret saved.' }}
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

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
                    <strong class="text-error">not connected</strong>
                @endif
            </li>
            <li>Platform secret key: {{ $secretKeySet ? 'configured' : 'MISSING (set STRIPE_SECRET)' }}</li>
            <li>OAuth client id: {{ $clientIdSet ? 'configured' : 'MISSING (set STRIPE_CLIENT_ID)' }}</li>
            <li>Webhook signing secret: {{ $webhookSecretSet ? 'saved' : 'not set' }}</li>
        </ul>

        @if ($clientIdSet && $secretKeySet)
            <a class="btn btn-primary" href="{{ route('admin.stripe.connect') }}">Connect with Stripe</a>
        @else
            <p class="text-muted">Set STRIPE_CLIENT_ID and STRIPE_SECRET to enable connecting.</p>
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
                <input type="text" name="webhook_secret" class="input input-bordered"
                       placeholder="whsec_..." value="" aria-label="Webhook signing secret">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary">Save signing secret</button>
            </div>
        </form>
    </section>
</x-public-layout>
