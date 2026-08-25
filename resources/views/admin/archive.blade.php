@php($suffix = old('archiveSuffix', ''))
<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="container mt-4 mb-3">
        <h1>Archive Current Data</h1>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="lead">Archiving preserves the current competition data in sibling
            <code>&lt;table&gt;_&lt;suffix&gt;</code> tables and resets the live tables for the next competition.</p>

        @if ($archives->isNotEmpty())
            <h2 class="h4 mt-3">Existing Archives</h2>
            <table class="table table-striped table-sm w-auto">
                <thead>
                    <tr><th>Suffix</th><th>Style Set</th><th>Scoresheet Naming</th></tr>
                </thead>
                <tbody>
                    @foreach ($archives as $archive)
                        <tr>
                            <td>{{ $archive->archiveSuffix }}</td>
                            <td>{{ $archive->archiveStyleSet ?? '—' }}</td>
                            <td>{{ $archive->archiveScoresheet === 'E' ? 'Entry number' : ($archive->archiveScoresheet === 'J' ? 'Judging number' : '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form method="post" action="{{ route('admin.archive.store') }}" class="mt-4">
            @csrf
            <input type="hidden" name="confirm" value="yes">

            <div class="mb-3 row">
                <label for="archiveSuffix" class="col-sm-3 col-form-label">Archive Name (suffix) <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input class="form-control" id="archiveSuffix" name="archiveSuffix" type="text"
                        placeholder="{{ date('Y') }} or Q2{{ date('y') }}, etc."
                        pattern="^[a-zA-Z0-9]+$" required value="{{ $suffix }}">
                    <div class="form-text">Letters and numbers only. This becomes the suffix on every archived table
                        (e.g. <code>brewing_{{ $suffix ?: '<suffix>' }}</code>) and must be unique.</div>
                </div>
            </div>

            <fieldset class="mb-3">
                <legend class="h5">Data to Retain (not archived away)</legend>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepSpecialBest" id="keepSpecialBest" value="1">
                    <label class="form-check-label" for="keepSpecialBest">Custom special-best categories</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepStyleTypes" id="keepStyleTypes" value="1">
                    <label class="form-check-label" for="keepStyleTypes">Custom style types</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepDropoff" id="keepDropoff" value="1">
                    <label class="form-check-label" for="keepDropoff">Drop-off locations</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepLocations" id="keepLocations" value="1">
                    <label class="form-check-label" for="keepLocations">Judging locations</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepParticipants" id="keepParticipants" value="1">
                    <label class="form-check-label" for="keepParticipants">Participants</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepSponsors" id="keepSponsors" value="1">
                    <label class="form-check-label" for="keepSponsors">Sponsors</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="keepEvaluations" id="keepEvaluations" value="1">
                    <label class="form-check-label" for="keepEvaluations">Evaluations</label>
                </div>
                <div class="form-text">Unchecked items are destroyed from the live tables. Absence of a checkbox
                    means destroy — check everything you want to carry into the next competition.</div>
            </fieldset>

            <details class="border rounded p-3 mb-3 bg-light">
                <summary class="fw-bold text-danger">⚠ Irreversible — read before confirming</summary>
                <div class="mt-2">
                    <p class="mb-2">With the default options, archiving irreversibly destroys:</p>
                    <ol class="mb-2">
                        <li>All uploaded scoresheet PDFs under <code>user_docs/</code>.</li>
                        <li>Custom style-type rows (id ≥ 16).</li>
                        <li>Drop-off, sponsor, and judging-location configuration.</li>
                        <li>Every entrant account except yours.</li>
                    </ol>
                    <p class="mb-0"><strong>Nothing here is recoverable from within the application.
                        Hosting-layer backups are the only safety net.</strong></p>
                </div>
                <button type="submit" class="btn btn-danger mt-3">Yes — archive current data now</button>
            </details>
        </form>
    </section>
</x-public-layout>
