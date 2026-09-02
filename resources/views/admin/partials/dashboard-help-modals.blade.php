@php($__help = $helpTopics["comp-prep"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-comp-prep" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-comp-prep-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-comp-prep-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["entries-participants"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-entries-participants" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-entries-participants-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-entries-participants-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["sorting"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-sorting" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-sorting-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-sorting-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["organizing"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-organizing" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-organizing-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-organizing-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["scoring"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-scoring" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-scoring-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-scoring-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["preferences"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-preferences" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-preferences-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-preferences-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["reports"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-reports" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-reports-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-reports-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["data-exports"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-data-exports" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-data-exports-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-data-exports-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@php($__help = $helpTopics["data-mgmt"] ?? null)
@if ($__help)
<div class="modal fade" id="dashboard-help-modal-data-mgmt" tabindex="-1" role="dialog" aria-labelledby="dashboard-help-modal-data-mgmt-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="dashboard-help-modal-data-mgmt-title">{{ $__help["title"] }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="prose prose-sm">{!! $__help["body"] !!}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
