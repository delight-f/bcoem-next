@extends('wizard.layout')

@section('title', 'Get started')
@section('step', 'Step 1 of 6')
@section('heading', 'Welcome to your new competition site')

@section('content')
    <p>
        This wizard sets up your site for the first time. Have the database details
        from your hosting control panel or welcome email nearby.
    </p>
    <p class="mb-4">
        Setup usually takes about five minutes. Nothing is changed until the final
        &ldquo;Install Now&rdquo; step, so you can look around safely.
    </p>

    <a href="{{ route('wizard.install.checks') }}" class="btn btn-primary btn-lg">Get Started</a>
@endsection
