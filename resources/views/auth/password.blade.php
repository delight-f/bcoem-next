{{-- Legacy sections/user.sec.php action=password: Old Password + New
     Password fields, "Change Password" submit. Wrong old password
     redirects back with ?msg=3. --}}
<x-public-layout
    :ctx="$ctx"
    :show-hero="false"
>
    <section id="change-password" class="landing-page-section mt-6 mb-4">
        <header class="landing-page-section-header py-2">
            <h1>{{ __('site.change_password') }}</h1>
        </header>

        @if ((int) request('msg') === 3)
            <p class="alert alert-error print:hidden">{{ __('site.password_incorrect') }}</p>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ url('/user/password') }}" class="needs-validation" novalidate>
            @csrf

            <div class="mb-4 row">
                <label for="passwordOld" class="col-sm-3 col-form-label">{{ __('site.old_password') }} *</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="passwordOld" name="passwordOld" type="password" required>
                </div>
            </div>
            <div class="mb-4 row">
                <label for="newPassword" class="col-sm-3 col-form-label">{{ __('site.new_password') }} *</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="newPassword" name="password" type="password" required>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-sm-9 offset-sm-3">
                    <button type="submit" class="btn btn-lg btn-primary">{{ __('site.change_password') }}</button>
                </div>
            </div>
        </form>
    </section>
</x-public-layout>
