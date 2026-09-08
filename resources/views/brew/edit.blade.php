<x-public-layout
    :ctx="$ctx"
    :salutation="__('site.edit_entry')"
    :show-hero="false"
>
    <section id="edit-entry" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.edit_entry') }}</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Legacy brew.pub.php posts both add and edit to the same URL;
             the clean port keeps that shape with GET+POST /brew/{id}/edit.
             The shared partial carries hidden brewConfirmed=1
             (brew.pub.php:1106); a save missing a required style field
             unconfirms the row server-side instead. --}}
        <form method="post" action="{{ url('/brew/'.$entry->id.'/edit') }}" class="needs-validation" novalidate>
            @csrf

            @include('brew._fields', ['entry' => $entry])

            <div class="row mb-4">
                <div class="col-sm-9 offset-sm-3">
                    <button type="submit" class="btn btn-lg btn-primary">{{ __('site.save') }}</button>
                </div>
            </div>
        </form>
    </section>
</x-public-layout>
