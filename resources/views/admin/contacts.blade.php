@php $row = $editing ?? null; @endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $row !== null ? 'Edit a Contact' : 'Contacts' }}</h1>
        <p class="mb-3">
            <a class="btn btn-sm btn-outline" href="{{ url('/admin/contacts') }}"><span class="fa fa-eye"></span> View All Contacts</a>
            <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#contactsHelpModal">Contact Help</button>
        </p>

        <dialog class="modal" id="contactsHelpModal">
            <div class="modal-box">
                <h4 class="font-bold">Contact Help</h4>
                <p>Define the contacts associated with the competition (e.g., the Competition Coordinator, Head Judge, Cellar Master, etc.). The names will be available via a drop-down list on the Contact page.</p>
                <div class="modal-action"><form method="dialog"><button class="btn">Close</button></form></div>
            </div>
        </dialog>

        @if ((int) request('msg') === 9)
            <div class="alert alert-success">Contacts updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @foreach ($contacts as $contact)
            <div class="border-bottom pb-2 mb-2 row items-center">
                <div class="col-md-8">
                    {{ $contact->contactLastName }}, {{ $contact->contactFirstName }}
                    <span class="text-muted">&middot; {{ $contact->contactPosition }}</span>
                </div>
                <div class="col-md-4 text-end">
                    <a class="btn btn-sm btn-outline btn-secondary" href="{{ url('/admin/contacts/'.$contact->id.'/edit') }}">Edit</a>
                    <form method="post" action="{{ url('/admin/contacts/'.$contact->id) }}" class="inline"
                        onsubmit="return confirm('Are you sure you want to delete this contact? This cannot be undone.');">
                        @csrf
                        @method('delete')
                        <button type="submit" class="btn btn-sm btn-outline btn-error">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
        @if ($contacts->isEmpty())
            <p>There are no contacts in the database.</p>
        @endif

        <p><a class="btn btn-primary" href="{{ url('/admin/contacts/create') }}">Add a Contact</a></p>

        @if ($row !== null || request()->routeIs('admin.contacts.create'))
            <h2>{{ $row !== null ? 'Edit Contact' : 'Add a Contact' }}</h2>
            <form method="post" action="{{ url($row !== null ? '/admin/contacts/'.$row->id : '/admin/contacts') }}">
                @csrf
                @method($row !== null ? 'put' : 'post')
                <div class="mb-4 row">
                    <label for="contactFirstName" class="col-sm-4 col-form-label">First Name</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contactFirstName" name="contactFirstName" type="text" value="{{ $row->contactFirstName ?? '' }}" required></div>
                </div>
                <div class="mb-4 row">
                    <label for="contactLastName" class="col-sm-4 col-form-label">Last Name</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contactLastName" name="contactLastName" type="text" value="{{ $row->contactLastName ?? '' }}" required></div>
                </div>
                <div class="mb-4 row">
                    <label for="contactPosition" class="col-sm-4 col-form-label">Position</label>
                    <div class="col-sm-9"><input class="input input-bordered" id="contactPosition" name="contactPosition" type="text" value="{{ $row->contactPosition ?? '' }}" required></div>
                </div>
                <div class="mb-4 row">
                    <label for="contactEmail" class="col-sm-4 col-form-label">Email</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="contactEmail" name="contactEmail" type="email" value="{{ $row->contactEmail ?? '' }}" required>
                        <span class="help-block">Email addresses are <strong>not</strong> displayed. Used only for contact purposes via the site's contact form.</span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ $row !== null ? 'Edit' : 'Add' }} Contact</button>
            </form>
        @endif
    </section>
</x-public-layout>
