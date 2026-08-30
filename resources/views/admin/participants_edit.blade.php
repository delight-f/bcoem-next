<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Edit Participant</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('backoffice.participants.update', ['uid' => $participant->uid]) }}">
            @csrf
            @method('PUT')

            <div class="mb-4 row">
                <label for="brewerFirstName" class="col-sm-4 col-form-label"><strong>First Name *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerFirstName" name="brewerFirstName" required
                           value="{{ old('brewerFirstName', $participant->brewerFirstName) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerLastName" class="col-sm-4 col-form-label"><strong>Last Name *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerLastName" name="brewerLastName" required
                           value="{{ old('brewerLastName', $participant->brewerLastName) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerEmail" class="col-sm-4 col-form-label"><strong>Email *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerEmail" name="brewerEmail" type="email" required
                           value="{{ old('brewerEmail', $participant->brewerEmail) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerPhone1" class="col-sm-4 col-form-label">Phone</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerPhone1" name="brewerPhone1"
                           value="{{ old('brewerPhone1', $participant->brewerPhone1) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerAddress" class="col-sm-4 col-form-label">Address</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerAddress" name="brewerAddress"
                           value="{{ old('brewerAddress', $participant->brewerAddress) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerCity" class="col-sm-4 col-form-label">City</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerCity" name="brewerCity"
                           value="{{ old('brewerCity', $participant->brewerCity) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerState" class="col-sm-4 col-form-label">State/Region</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerState" name="brewerState"
                           value="{{ old('brewerState', $participant->brewerState) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerZip" class="col-sm-4 col-form-label">Postal Code</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerZip" name="brewerZip"
                           value="{{ old('brewerZip', $participant->brewerZip) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewerClubs" class="col-sm-4 col-form-label">Club(s)</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewerClubs" name="brewerClubs"
                           value="{{ old('brewerClubs', $participant->brewerClubs) }}">
                </div>
            </div>

            {{-- Account security section (pub/brewer_form_0.pub.php:154-187:
                 Change Security Question/Answer? + Q/A fields; the admin
                 password reset is process_users.inc.php change_user_password). --}}
            <hr>
            <div class="mb-4 row">
                <label class="col-sm-4 col-form-label"><strong>Change Security Question/Answer?</strong></label>
                <div class="col-sm-9">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="change-security-1" name="changeSecurity" value="Y">
                        <label class="form-check-label" for="change-security-1">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="change-security-0" name="changeSecurity" value="N" checked>
                        <label class="form-check-label" for="change-security-0">No</label>
                    </div>
                </div>
            </div>

            <div id="security-question-change">
                <div class="mb-4 row">
                    <label for="security" class="col-sm-4 col-form-label">Security Question</label>
                    <div class="col-sm-9">
                        <select class="select select-bordered" id="security" name="userQuestion">
                            <option value="">-- Select --</option>
                            <option value="What is your favorite all-time beer to drink?" {{ old('userQuestion', $user->userQuestion ?? '') === 'What is your favorite all-time beer to drink?' ? 'selected' : '' }}>What is your favorite all-time beer to drink?</option>
                            <option value="What is the name of your first pet?" {{ old('userQuestion', $user->userQuestion ?? '') === 'What is the name of your first pet?' ? 'selected' : '' }}>What is the name of your first pet?</option>
                            <option value="In what city were you born?" {{ old('userQuestion', $user->userQuestion ?? '') === 'In what city were you born?' ? 'selected' : '' }}>In what city were you born?</option>
                            <option value="What is your mother's maiden name?" {{ old('userQuestion', $user->userQuestion ?? '') === "What is your mother's maiden name?" ? 'selected' : '' }}>What is your mother's maiden name?</option>
                            <option value="What was the name of your elementary school?" {{ old('userQuestion', $user->userQuestion ?? '') === 'What was the name of your elementary school?' ? 'selected' : '' }}>What was the name of your elementary school?</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4 row">
                    <label for="userQuestionAnswer" class="col-sm-4 col-form-label">Security Answer</label>
                    <div class="col-sm-9">
                        <input class="input input-bordered" id="userQuestionAnswer" name="userQuestionAnswer" type="text"
                               value="{{ old('userQuestionAnswer') }}">
                    </div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="password" class="col-sm-4 col-form-label">Reset Password</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="password" name="password" type="password" placeholder="Leave blank to keep current password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Participant</button>

            <button type="submit" class="btn btn-primary">Save Participant</button>
            <a class="btn btn-link" href="{{ url('/backoffice/participants') }}">Cancel</a>
        </form>
    </section>
</x-public-layout>
