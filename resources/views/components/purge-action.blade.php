@props(['ctx', 'flow', 'title', 'description', 'threshold' => false])
<div class="col-md-6">
    <div class="card h-full border-error/30">
        <div class="card-body">
            <h2 class="h5 card-title">{{ $title }}</h2>
            <p class="card-text">{{ $description }}</p>
            <details>
                <summary class="font-bold text-error">Confirm — this cannot be undone</summary>
                <form method="post" action="{{ route('admin.purge.run', ['flow' => $flow]) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="confirm" value="yes">
                    @if ($threshold)
                        <div class="mb-2">
                            <label for="{{ $flow }}-dateThreshold" class="form-label">
                                Threshold date (optional; blank = purge everything)
                            </label>
                            <input type="date" class="input input-bordered" id="{{ $flow }}-dateThreshold"
                                name="dateThreshold" value="">
                        </div>
                    @endif
                    <button type="submit" class="btn btn-error btn-sm">Yes — run “{{ $title }}” now</button>
                </form>
            </details>
        </div>
    </div>
</div>
