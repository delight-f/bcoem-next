@php($__help = $helpTopics["comp-prep"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-comp-prep" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["entries-participants"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-entries-participants" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["sorting"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-sorting" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["organizing"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-organizing" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["scoring"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-scoring" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["preferences"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-preferences" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["reports"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-reports" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["data-exports"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-data-exports" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
@php($__help = $helpTopics["data-mgmt"] ?? null)
@if ($__help)
<dialog id="dashboard-help-modal-data-mgmt" class="modal">
    <div class="modal-box max-w-3xl">
        <h3 class="text-lg font-bold">{{ $__help["title"] }}</h3>
        <div class="prose prose-sm">{!! $__help["body"] !!}</div>
        <div class="modal-action">
            <form method="dialog"><button class="btn">Close</button></form>
        </div>
    </div>
</dialog>
@endif
