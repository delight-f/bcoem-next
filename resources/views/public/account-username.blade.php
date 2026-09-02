{{-- Legacy ?section=user&action=username (pub/user.pub.php:35-80): the
     distinct change-email page. New email + availability check (ajax
     /ajax/username) + confirmation checkbox. --}}
<x-public-layout :ctx="$ctx" :show-hero="false" :salutation="$salutation">
    <section class="landing-page-section pb-4">
        <header class="landing-page-section-header py-2"><h1>{{ __('site.change_email') }}</h1></header>

        <p class="lead">{!! $lead !!}</p>

        @if ($errors->any())
            <div class="alert alert-danger mb-4">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form role="form" method="post" action="{{ url('/user/username') }}" class="needs-validation" novalidate>
            @csrf
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="hidden" name="id" value="{{ $targetId }}">
            <input type="hidden" name="old_email" value="{{ $oldEmail }}">

            <div class="mb-4">
                <label for="user_name" class="form-label"><span class="text-danger">*</span> <strong>{{ __('site.new_email') }}</strong></label>
                <input class="form-control" id="user_name" name="user_name" type="email"
                       value="{{ old('user_name') }}" required autocomplete="email">
                <div class="help-block invalid-feedback text-danger">{{ __('site.email_invalid') }}</div>
                <div id="username-status" class="mt-2 small"></div>
            </div>

            <div class="mb-4">
                <label class="form-label"><span class="text-danger">*</span> <strong>{{ __('site.are_you_sure') }}</strong></label>
                <label class="form-check-label">
                    <input class="form-check-input" type="checkbox" name="sure" value="Y" required @checked(old('sure'))>
                    {{ __('site.yes') }}
                </label>
                <div class="help-block invalid-feedback text-danger">{{ __('site.sure_required') }}</div>
            </div>

            <div class="d-grid gap-2 mt-4">
                <button name="submit" type="submit" class="btn btn-lg btn-primary">{{ __('site.change_email') }}<i class="fas fa-envelope ms-2"></i></button>
            </div>
        </form>
    </section>
</x-public-layout>
