<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-4 mb-3">
        <h1>Import Judges' Score Data</h1>

        <div class="alert alert-warning">
            Importing scores and/or places listed on evaluations will
            <strong>NOT</strong> overwrite any scores already recorded on the
            official scores database — finalize evaluations before importing.
            Only entries evaluated by two or more judges are imported; the
            highest evaluation score becomes the official score.
        </div>

        <form method="post" action="{{ route('eval.import.run') }}">
            @csrf
            <button type="submit" class="btn btn-success">I Understand — Continue Import</button>
            <a class="btn btn-secondary" href="{{ route('eval.dashboard') }}">Cancel</a>
        </form>
    </section>
</x-public-layout>
