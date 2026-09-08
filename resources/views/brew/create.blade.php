<x-public-layout
    :ctx="$ctx"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="add-entry" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.add_entry') }}</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ url('/brew') }}">
            @csrf

            @include('brew._fields', ['entry' => null])

            <button type="submit" class="btn btn-lg btn-primary">{{ __('site.add_entry') }}</button>
        </form>
    </section>
</x-public-layout>
