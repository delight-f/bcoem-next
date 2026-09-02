<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Change Password for {{ $user->user_name }}</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">Password updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="post" action="{{ url('/admin/users/'.$user->id.'/password') }}">
            @csrf
            @method('put')
            <input type="hidden" name="userEdit" value="1">

            <div class="mb-4 row">
                <label for="password1" class="col-md-4 col-form-label">New Password</label>
                <div class="col-md-9">
                    <input class="form-control" id="password1" name="password1" type="password" required minlength="8">
                </div>
            </div>
            <div class="mb-4 row">
                <label for="password2" class="col-md-4 col-form-label">Confirm Password</label>
                <div class="col-md-9">
                    <input class="form-control" id="password2" name="password" type="password" required minlength="8">
                    <span class="form-text">The passwords must match.</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Change User Password</button>
        </form>
    </section>
</x-public-layout>
