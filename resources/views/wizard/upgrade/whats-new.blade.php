@extends('wizard.layout')

@section('title', "What's new")
@section('step', 'Step 1 of 4')
@section('heading', "What's new")

@section('content')
    <p class="lead">
        You are updating from version <strong>{{ $current !== '' ? $current : 'unknown' }}</strong>
        to version <strong>{{ $incoming }}</strong>.
    </p>

    @forelse ($sections as $section)
        <h2 class="h5 mt-4">Version {{ $section['version'] }}</h2>
        <div class="text-body">{!! nl2br(e($section['body'])) !!}</div>
    @empty
        <p>This update includes fixes and improvements to your site.</p>
    @endforelse

    <div class="alert alert-info mt-4" role="alert">
        A backup of your data is taken automatically before anything changes, so it can be restored
        if needed. While the update runs your site will be briefly unavailable &mdash; this is
        normal, and there is no need to refresh repeatedly.
    </div>

    <a class="btn btn-primary btn-lg" href="{{ route('wizard.upgrade.checks') }}">Continue</a>
@endsection
