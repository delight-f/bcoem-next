<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Upload Scoresheets</h1>

        @if (request('msg') === '2')
            <p class="alert alert-success">Scoresheet PDF(s) uploaded.</p>
        @elseif (request('msg') === '31')
            <p class="alert alert-success">Scoresheet PDF(s) deleted.</p>
        @endif

        {{-- upload_scoresheets.admin.php: instructions + upload. The port's
             form is already a plain multi-file input with no JS, so legacy's
             "single file upload function" fallback (action=html) would point
             at the same screen — the link was removed (C1-04). --}}

        <p>For entrants to be able to view their scoresheets, each PDF should:</p>
        <ul style="margin-bottom: 30px;" class="list-disc">
            <li>Contain all judges' scoresheets and other documentation (cover sheet, etc.) in <strong>a single file</strong>.</li>
            @if (($ctx->prefsStr('prefsDisplaySpecial') ?? 'J') === 'J')
                <li>In accordance with your installation's Scoresheet Unique Identifier <a href="{{ route('admin.judging.preferences.show') }}">preference</a>, be named with a <strong>six (6) character <u>judging</u> number</strong> (e.g., 000012.pdf, 987654.pdf, 01-234.pdf, abc123.pdf. 123abc.pdf, etc.) that corresponds <strong>EXACTLY to the entry's judging number as stored in the system's database</strong>.</li>
            @else
                <li>In accordance with your installation's Scoresheet Unique Identifier <a href="{{ route('admin.judging.preferences.show') }}">preference</a>, be named with the <strong><u>entry</u> number in six (6) digit format</strong> with leading zeroes (e.g., 000198.pdf, 000567.pdf, etc.).</li>
            @endif
            <li>Have a .pdf or .PDF extension. Please note that file names and extensions uploaded with this browser-based function will be converted to lower-case.</li>
            <li>Be <strong>15 MB or less</strong> in size.</li>
        </ul>

        <form method="post" action="{{ url('/admin/upload-scoresheets') }}" enctype="multipart/form-data" class="mb-5">
            @csrf
            <div class="mb-4 row">
                <label for="scoresheetFiles" class="col-md-3 col-form-label"><strong>PDF Files *</strong></label>
                <div class="col-md-9">
                    <input class="form-control" type="file" name="files[]" id="scoresheetFiles" multiple accept=".pdf" required>
                    @error('files.*')<div class="text-danger">{{ $message }}</div>@enderror
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Upload PDF File(s)</button>
        </form>

        {{-- Files in the Directory (upload_scoresheets.admin.php:131+).
             Delete All / per-file delete are the legacy process.inc.php
             action=delete_scoresheets and process_delete go=doc flows. --}}
        <h2>Files in the Directory</h2>
        @if ($files !== [])
            <form method="POST" action="{{ route('admin.upload_scoresheets.destroy_all') }}" class="mb-3"
                  onsubmit="return confirm('Are you sure? This will delete all scoresheets listed below. This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm"><span class="fa fa-trash"></span> Delete All Scoresheets</button>
            </form>
            <p>It is advised that you delete or archive scoresheets as you prepare for another competition iteration. Otherwise, your entrants may download incorrect PDFs from previous competition iterations, causing confusion.</p>
            <table class="table table-bordered table-responsive table-striped">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Size</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $file)
                        <tr>
                            <td>{{ $file }}</td>
                            <td>{{ number_format(filesize(\App\Support\Entries\UserDocs::path($file)) / 1000000, 4) }} MB</td>
                            <td>{{ \App\Support\Tenant\DateFmt::dateTime(filemtime(\App\Support\Entries\UserDocs::path($file)), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'long', $ctx->showTimezone()) }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.upload_scoresheets.destroy', ['file' => $file]) }}"
                                      onsubmit="return confirm('Are you sure? This will remove the file named {{ $file }} from the server.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link p-0" title="Delete"><span class="fa fa-lg fa-trash text-danger"></span></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>The directory does not contain any PDF files.</p>
        @endif
    </section>
</x-public-layout>
