<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Judging</h1>

        @if ($judgeDashboard)
            <div class="alert alert-info">
                <p><strong>You are assigned as a judge.</strong> Judging is open —
                head to the judging dashboard to evaluate your assigned entries.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('eval.dashboard') }}">
                Judging Dashboard
            </a>
        @else
            <p>No judging dashboard is available for your account right now.
            Judge dashboards appear once you are assigned to a table and the
            judging window is open.</p>
        @endif
    </section>
</x-public-layout>
