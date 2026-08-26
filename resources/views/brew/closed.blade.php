{{-- Legacy brew.sec.php closed-window state (brew.sec.php:112-120 +
     :215-231): once entry registration closes, entrants get ONLY the
     "Adding and editing of entries is not available." lead — no form.
     Admins bypass the gate entirely (BrewController::showCreate). --}}
<x-public-layout
    :ctx="$ctx"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="add-entry" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.add_entry') }}</h1>

        <p class="lead">{{ __('site.add_edit_unavailable') }}</p>
    </section>
</x-public-layout>
