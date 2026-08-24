<x-public-layout
    :ctx="$ctx"
    :judging-started="$judgingStarted"
    :future-judging-sessions="$futureJudgingSessions"
    :sponsors-visible="$sponsorsVisible"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="password-verify" class="landing-page-section mt-4 mb-3">
        <h1>{{ __('site.security_question') }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="lead"><em>{{ $question }}</em></p>

        <form method="post" action="{{ route('password.verify') }}" class="needs-validation" novalidate>
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="form-floating mb-3">
                <input class="form-control form-control-lg" id="security-question-answer" name="answer" type="text"
                       placeholder="{{ __('reset.security_answer_label') }}" required autofocus>
                <label for="security-question-answer">{{ __('reset.security_answer_label') }}</label>
            </div>
            <div class="d-grid gap-2 mx-auto mb-4">
                <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.submit') }}</button>
            </div>
        </form>

        <p><a href="{{ route('login') }}">{{ __('reset.back_to_login') }}</a></p>
    </section>
</x-public-layout>
