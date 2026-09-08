<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>{{ $category->sbi_name }} — Custom Category Entries</h1>

        @if ($errors->any() || request('msg') === '24')
            <div class="alert alert-danger">One or more judging numbers could not be matched to a single entry. Those slots were skipped.</div>
        @endif

        <form method="post" action="{{ route('admin.specialbest.data.update', ['id' => $category->id]) }}">
            @csrf
            @method('PUT')

            @foreach ($slots as $index => $slot)
                {{-- Slot key: existing row id when editing, "new{index}" for blank slots
                     (legacy used random ids; only the key shape differs, storage is identical). --}}
                @php($key = $slot['exists'] ? (string) $slot['rowId'] : 'new'.$index)
                <input type="hidden" name="slot_id[]" value="{{ $key }}">
                <input type="hidden" name="sid{{ $key }}" value="{{ $category->id }}">
                <input type="hidden" name="entry_exists{{ $key }}" value="{{ $slot['exists'] ? 'Y' : 'N' }}">

                <div class="mb-4 row">
                    <label for="sbd_judging_no{{ $key }}" class="col-md-3 col-form-label">
                        Winning Entry {{ $index + 1 }}'s Judging Number
                    </label>
                    <div class="col-md-3">
                        <input class="form-control" id="sbd_judging_no{{ $key }}" name="sbd_judging_no{{ $key }}"
                               type="text" maxlength="255" value="{{ old('sbd_judging_no'.$key, $slot['judgingNumber']) }}">
                    </div>
                    <label for="sbd_place{{ $key }}" class="col-md-1 col-form-label">Place</label>
                    <div class="col-md-2">
                        <input class="form-control" id="sbd_place{{ $key }}" name="sbd_place{{ $key }}"
                               type="text" value="{{ old('sbd_place'.$key, $slot['place'] ?? '') }}">
                    </div>
                    @if ($slot['entryName'] !== null)
                        <div class="col-md-3 col-form-label">{{ $slot['entryName'] }}</div>
                    @endif
                </div>
            @endforeach

            <button type="submit" class="btn btn-primary">Save Entries</button>
        </form>
    </section>
</x-public-layout>
