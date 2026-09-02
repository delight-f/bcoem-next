<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Change User Level</h1>

        @if ((int) request('msg') === 2)
            <div class="alert alert-success">User level updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="post" action="{{ url('/admin/users/'.$user->id.'/level') }}">
            @csrf
            @method('put')
            <input type="hidden" name="user_name" value="{{ $user->user_name }}">

            <p><strong>Top-level admins</strong> have full access to add, change, and delete all information in the
                database, including preferences, competition information, and archival data — provide this level
                <span class="text-error"><strong>with caution</strong></span>!</p>
            <p><strong>Admin users</strong> are able to add, change, and delete most information in the database,
                including participants, entries, tables, scores, etc.</p>

            <fieldset>
                <legend class="text-sm">User Level for {{ $user->user_name }}</legend>
                @foreach ([2 => 'Participant', 1 => 'Admin', 0 => 'Top-Level Admin'] as $level => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="userLevel" value="{{ $level }}" id="userLevel{{ $level }}"
                            @checked((string) $user->userLevel === (string) $level)>
                        <label class="form-check-label" for="userLevel{{ $level }}">{{ $label }}</label>
                    </div>
                @endforeach
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="userAdminObfuscate" value="1" id="obfuscate"
                        @checked(((int) $user->userAdminObfuscate) === 1)>
                    <label class="form-check-label" for="obfuscate">Obfuscate Judging Numbers?</label>
                    <span class="form-text">If you wish to hide judging numbers from this Admin user, check the box.</span>
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary mt-4">Change User Level</button>
        </form>
    </section>
</x-public-layout>
