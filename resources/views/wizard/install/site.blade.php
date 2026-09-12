@extends('wizard.layout')

@section('title', 'Site details')
@section('step', 'Step 4 of 6')
@section('heading', 'Your site and your account')

@section('content')
    <form method="POST" action="{{ route('wizard.install.site.store') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="app_url">Web address of your site</label>
            <input class="form-control" type="url" id="app_url" name="app_url"
                   value="{{ old('app_url', $values['app_url'] ?? $defaultUrl) }}" required>
            <div class="form-text">We filled this in from the address you are using now. Change it only if your site will live somewhere else.</div>
        </div>

        <hr class="my-4">

        <p class="text-muted">This account is the one that manages the whole site, so keep its password safe.</p>

        <div class="mb-3">
            <label class="form-label" for="admin_name">Your name</label>
            <input class="form-control" id="admin_name" name="admin_name" value="{{ old('admin_name', $values['admin_name'] ?? '') }}" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="admin_email">Your email address</label>
            <input class="form-control" type="email" id="admin_email" name="admin_email" value="{{ old('admin_email', $values['admin_email'] ?? '') }}" required>
            <div class="form-text">This is also the username you will log in with.</div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="admin_password">Password</label>
                <input class="form-control" type="password" id="admin_password" name="admin_password" minlength="8" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="admin_password_confirmation">Confirm password</label>
                <input class="form-control" type="password" id="admin_password_confirmation" name="admin_password_confirmation" minlength="8" required>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <a href="{{ route('wizard.install.database') }}" class="btn btn-outline-secondary">Back</a>
            <button type="submit" class="btn btn-primary">Next</button>
        </div>
    </form>
@endsection
