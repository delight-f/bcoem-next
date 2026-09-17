<x-public-layout :ctx="$ctx" :salutation="$salutation" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Payment Setup</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="lead">
            Choose how entrants pay their entry fees. You can offer card payments
            (Stripe), PayPal, or both. Only the options you set up appear to
            entrants; anything left unconfigured stays hidden.
        </p>

        {{-- ---------------- Stripe ---------------- --}}
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4">Card payments — Stripe</h2>
                <p class="mb-2">
                    Money goes straight into your own Stripe account. You need a
                    free Stripe account; this site never holds the funds.
                </p>
                <p class="mb-3">
                    Status:
                    @if ($stripe['accountId'] !== '')
                        <span class="text-success-emphasis">Connected to {{ $stripe['accountId'] }}.</span>
                    @else
                        <span class="text-danger-emphasis">Not connected.</span>
                    @endif
                </p>

                <ol>
                    <li>Click <strong>Connect with Stripe</strong> and sign in to Stripe.</li>
                    <li>In Stripe, add a webhook endpoint pointing at
                        <code>{{ $stripeWebhookUrl }}</code> and copy its signing secret.</li>
                    <li>Paste that signing secret on the Stripe settings page and save.</li>
                </ol>

                @if ($stripe['clientIdSet'] && $stripe['secretKeySet'])
                    <a class="btn btn-primary" href="{{ route('admin.stripe.connect') }}">Connect with Stripe</a>
                    <a class="btn btn-secondary" href="{{ route('admin.stripe') }}">Stripe settings and signing secret</a>
                @else
                    <p class="text-muted mb-0">
                        Stripe cannot be switched on here yet: this installation is
                        missing the platform keys (<code>STRIPE_CLIENT_ID</code>,
                        <code>STRIPE_SECRET</code>).
                    </p>
                @endif
            </div>
        </div>

        {{-- ---------------- PayPal ---------------- --}}
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4">PayPal</h2>
                <p class="mb-2">
                    Money goes straight into your own PayPal business account. You
                    need a free PayPal developer app.
                </p>
                <p class="mb-3">
                    Status:
                    @if ($paypal['configured'])
                        <span class="text-success-emphasis">Enabled ({{ $paypal['mode'] }}).</span>
                    @else
                        <span class="text-danger-emphasis">Not set up.</span>
                    @endif
                    @if ($paypal['fromEnv'])
                        <span class="text-muted ms-2">Currently using this server's environment settings.</span>
                    @endif
                </p>

                <ol>
                    <li>In PayPal, create an app and copy its <strong>Client ID</strong> and <strong>Secret</strong>.</li>
                    <li>Add a webhook pointing at <code>{{ $paypalWebhookUrl }}</code>, subscribed to
                        <code>PAYMENT.CAPTURE.COMPLETED</code> and <code>PAYMENT.CAPTURE.REFUNDED</code>,
                        then copy its <strong>Webhook ID</strong>.</li>
                    <li>Paste all three below and save.</li>
                </ol>

                <form method="post" action="{{ route('admin.payments.setup.paypal') }}" class="row g-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label" for="paypal_mode">Mode</label>
                        <select class="form-select" id="paypal_mode" name="mode">
                            <option value="sandbox" @selected($paypal['mode'] === 'sandbox')>Sandbox (testing)</option>
                            <option value="live" @selected($paypal['mode'] === 'live')>Live</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="paypal_client_id">Client ID</label>
                        <input class="form-control" id="paypal_client_id" name="client_id" value="{{ $paypal['clientId'] }}">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="paypal_client_secret">Client Secret</label>
                        <input class="form-control" id="paypal_client_secret" name="client_secret" type="password"
                               placeholder="{{ $paypal['secretSet'] ? 'Saved — leave blank to keep' : '' }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="paypal_webhook_id">Webhook ID</label>
                        <input class="form-control" id="paypal_webhook_id" name="webhook_id" value="{{ $paypal['webhookId'] }}">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Save PayPal settings</button>
                    </div>
                </form>

                @if ($paypal['configured'])
                    <form method="post" action="{{ route('admin.payments.setup.paypal.remove') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger btn-sm">Remove PayPal settings</button>
                    </form>
                @endif

                <p class="text-muted mt-3 mb-0">
                    The client secret is stored encrypted. Leaving the field blank
                    keeps the secret that is already saved.
                </p>
            </div>
        </div>
    </section>
</x-public-layout>
