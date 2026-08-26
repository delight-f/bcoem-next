<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-6 mb-4">
        <h1>Upload Scoresheets</h1>

        @if (request('msg') === '2')
            <p class="alert alert-success">Scoresheet PDF(s) uploaded.</p>
        @endif

        {{-- upload_scoresheets.admin.php: instructions + single/multi upload --}}
        <div class="alert alert-info">
            <ul class="mb-0">
                <li>Upload scoresheet PDF files for entrants to access from their account pages.</li>
                <li>In accordance with your installation's Scoresheet Unique Identifier preference, name each file with the
                    <strong><u>entry</u> number in six (6) digit format</strong> with leading zeroes (e.g., 000198.pdf, 000567.pdf, etc.).</li>
            </ul>
        </div>

        <form method="post" action="{{ url('/admin/upload-scoresheets') }}" enctype="multipart/form-data" class="mb-5">
            @csrf
            <div class="mb-4 row">
                <label for="scoresheetFiles" class="col-sm-3 col-form-label"><strong>PDF Files *</strong></label>
                <div class="col-sm-9">
                    <input class="input input-bordered" type="file" name="files[]" id="scoresheetFiles" multiple accept=".pdf" required>
                    @error('files.*')<div class="text-error">{{ $message }}</div>@enderror
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Upload PDF File(s)</button>
        </form>

        @if ($files->isNotEmpty())
            <section>
                <h2>Uploaded Scoresheets ({{ $files->count() }})</h2>
                <table class="table table-bordered">
                    <thead>
                        <tr><th>File</th><th>Entry #</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr>
                                <td>{{ $file }}</td>
                                <td>{{ preg_match('/^(\d{6})/', $file, $m) ? ltrim($m[1], '0') : '&mdash;' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </section>
</x-public-layout>
