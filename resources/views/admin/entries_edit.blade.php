<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Edit Entry</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('backoffice.entries.update', ['id' => $entry->id]) }}">
            @csrf
            @method('PUT')

            <div class="mb-4 row">
                <label for="brewName" class="col-sm-4 col-form-label"><strong>Entry Name *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewName" name="brewName" required
                           value="{{ old('brewName', $entry->brewName) }}">
                </div>
            </div>

            {{-- Style re-assignment: same dropdown source as the public entry form --}}
            <div class="mb-4 row">
                <label for="brewStyle" class="col-sm-4 col-form-label"><strong>Style *</strong></label>
                <div class="col-sm-9">
                    @php($current = old('brewStyle', ltrim((string) $entry->brewCategorySort, '0').'-'.$entry->brewSubCategory))
                    <select class="select select-bordered" name="brewStyle" id="brewStyle" required>
                        <option value="">Select style…</option>
                        @foreach ($styles as $style)
                            <option value="{{ \App\Http\Controllers\BrewController::styleValue($style) }}"
                                    @selected(\App\Http\Controllers\BrewController::styleValue($style) === $current)>
                                {{ \App\Http\Controllers\BrewController::styleLabel($style) }}
                            </option>
                        @endforeach
                    </select>
                    @error('brewStyle')<div class="text-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4 row">
                <div class="offset-sm-4 col-sm-9">
                    <div class="form-check">
                        <input class="checkbox" type="checkbox" id="brewPaid" name="brewPaid" value="1"
                               @checked(old('brewPaid', (int) $entry->brewPaid === 1))>
                        <label class="form-check-label" for="brewPaid">Paid</label>
                    </div>
                    <div class="form-check">
                        <input class="checkbox" type="checkbox" id="brewReceived" name="brewReceived" value="1"
                               @checked(old('brewReceived', (int) $entry->brewReceived === 1))>
                        <label class="form-check-label" for="brewReceived">Received</label>
                    </div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewBoxNum" class="col-sm-4 col-form-label">Loc/Box</label>
                <div class="col-sm-9">
                    <input class="input input-bordered" id="brewBoxNum" name="brewBoxNum"
                           value="{{ old('brewBoxNum', $entry->brewBoxNum) }}">
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewAdminNotes" class="col-sm-4 col-form-label">Admin Notes</label>
                <div class="col-sm-9">
                    <textarea class="textarea textarea-bordered" id="brewAdminNotes" name="brewAdminNotes" rows="2"
                              maxlength="255">{{ old('brewAdminNotes', $entry->brewAdminNotes) }}</textarea>
                    <div class="form-text">255-character limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="brewStaffNotes" class="col-sm-4 col-form-label">Staff Notes</label>
                <div class="col-sm-9">
                    <textarea class="textarea textarea-bordered" id="brewStaffNotes" name="brewStaffNotes" rows="2"
                              maxlength="255">{{ old('brewStaffNotes', $entry->brewStaffNotes) }}</textarea>
                    <div class="form-text">Printed on pullsheets. 255-character limit.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Entry</button>
            <a class="btn btn-link" href="{{ url('/backoffice/entries') }}">Cancel</a>
        </form>
    </section>
</x-public-layout>
