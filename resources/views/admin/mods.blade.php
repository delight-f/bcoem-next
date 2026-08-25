@php $row = $editing ?? null; @endphp

<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-4 mb-3">
        <h1>{{ $ctx->contestStr('contestName') }}: {{ $row !== null ? 'Edit a Custom Module' : 'Custom Modules' }}</h1>

        @if ((int) request('msg') === 9)
            <div class="alert alert-success">Custom modules updated.</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @foreach ($mods as $mod)
            <div class="border-bottom pb-2 mb-2 row align-items-center">
                <div class="col-md-8">
                    <strong>{{ $mod->mod_name }}</strong>
                    <span class="text-muted d-block small">{{ $mod->mod_filename }}</span>
                </div>
                <div class="col-auto form-check">
                    <input class="form-check-input" type="checkbox" id="mod_enable{{ $mod->id }}" form="mods-bulk" name="mod_enable{{ $mod->id }}" value="1" @checked(((int) $mod->mod_enable) === 1)>
                    <label class="form-check-label small" for="mod_enable{{ $mod->id }}">Enabled</label>
                </div>
                <div class="col-md-3 text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ url('/admin/mods/'.$mod->id.'/edit') }}">Edit</a>
                    <form method="post" action="{{ url('/admin/mods/'.$mod->id) }}" class="d-inline"
                        onsubmit="return confirm('Are you sure you want to delete this module? This cannot be undone.');">
                        @csrf
                        @method('delete')
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
        @if ($mods->isEmpty())
            <p>No custom modules were found in the database.</p>
        @endif

        {{-- Enable toggles submit together, mirroring the legacy bulk update. --}}
        <form method="post" action="{{ url('/admin/mods') }}" id="mods-bulk">
            @csrf
            @method('put')
            @foreach ($mods as $mod)
                <input type="hidden" name="id[]" value="{{ $mod->id }}">
            @endforeach
            @if ($mods->isNotEmpty())
                <button type="submit" class="btn btn-primary">Update Custom Modules</button>
            @endif
        </form>

        <p class="mt-2"><a class="btn btn-primary" href="{{ url('/admin/mods/create') }}">Add a Custom Module</a></p>

        @if ($row !== null || request()->routeIs('admin.mods.create'))
            <h2>{{ $row !== null ? 'Edit Custom Module' : 'Add a Custom Module' }}</h2>
            <form method="post" action="{{ url($row !== null ? '/admin/mods/'.$row->id : '/admin/mods') }}" class="form-horizontal">
                @csrf
                @method($row !== null ? 'put' : 'post')
                <div class="mb-3 row">
                    <label for="mod_name" class="col-sm-3 col-form-label">Name</label>
                    <div class="col-sm-9"><input class="form-control" id="mod_name" name="mod_name" type="text" value="{{ $row->mod_name ?? '' }}" required></div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_filename" class="col-sm-3 col-form-label">File Name</label>
                    <div class="col-sm-9">
                        <input class="form-control" id="mod_filename" name="mod_filename" type="text" pattern="^[A-Za-z0-9_\-]+\.php$" value="{{ $row->mod_filename ?? '' }}" placeholder="your_file_name.php" required>
                        <span class="help-block">Letters, numbers, underscores, or hyphens; must end in .php.</span>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_description" class="col-sm-3 col-form-label">Description</label>
                    <div class="col-sm-9"><textarea class="form-control" id="mod_description" name="mod_description" rows="8">{{ $row->mod_description ?? '' }}</textarea></div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_type" class="col-sm-3 col-form-label">Type</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_type" name="mod_type" style="width:auto;">
                            <option value="0" @selected(($row->mod_type ?? '0') === '0')>Informational (Static HTML)</option>
                            <option value="1" @selected(($row->mod_type ?? '') === '1')>Report</option>
                            <option value="2" @selected(($row->mod_type ?? '') === '2')>Export</option>
                            <option value="3" @selected(($row->mod_type ?? '') === '3')>PHP Code or Function</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_permission" class="col-sm-3 col-form-label">Permission</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_permission" name="mod_permission" style="width:auto;">
                            <option value="0" @selected(($row->mod_permission ?? '0') === '0')>Top Level Admins</option>
                            <option value="1" @selected(($row->mod_permission ?? '') === '1')>Admins</option>
                            <option value="2" @selected(($row->mod_permission ?? '') === '2')>All Users</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_extend_function" class="col-sm-3 col-form-label">Extends Core Function</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_extend_function" name="mod_extend_function" style="width:auto;">
                            <option value="0" @selected(($row->mod_extend_function ?? '0') === '0')>All Public Pages</option>
                            <option value="1" @selected(($row->mod_extend_function ?? '') === '1')>Public Home Page Only</option>
                            <option value="6" @selected(($row->mod_extend_function ?? '') === '6')>Public Registration Page Only</option>
                            <option value="8" @selected(($row->mod_extend_function ?? '') === '8')>Public User's Account Page Only</option>
                            <option value="9" @selected(($row->mod_extend_function ?? '') === '9')>Administration</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_extend_function_admin" class="col-sm-3 col-form-label">Extends Admin Function</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_extend_function_admin" name="mod_extend_function_admin" style="width:auto;">
                            <option value=""></option>
                            <option value="default" @selected(($row->mod_extend_function_admin ?? '') === 'default')>Administration Dashboard</option>
                            <option value="archives" @selected(($row->mod_extend_function_admin ?? '') === 'archives')>Archives</option>
                            <option value="entries" @selected(($row->mod_extend_function_admin ?? '') === 'entries')>Entry Administration</option>
                            <option value="judging_scores" @selected(($row->mod_extend_function_admin ?? '') === 'judging_scores')>Scoring</option>
                            <option value="judging_scores_bos" @selected(($row->mod_extend_function_admin ?? '') === 'judging_scores_bos')>Scoring - Best of Show</option>
                            <option value="special_best" @selected(($row->mod_extend_function_admin ?? '') === 'special_best')>Scoring - Special Best of Show Categories</option>
                            <option value="styles" @selected(($row->mod_extend_function_admin ?? '') === 'styles')>Styles</option>
                            <option value="style_types" @selected(($row->mod_extend_function_admin ?? '') === 'style_types')>Style Types</option>
                            <option value="judging_tables" @selected(($row->mod_extend_function_admin ?? '') === 'judging_tables')>Table Administration</option>
                            <option value="participants" @selected(($row->mod_extend_function_admin ?? '') === 'participants')>Users (Participants)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_rank" class="col-sm-3 col-form-label">Rank</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_rank" name="mod_rank" style="width:auto;">
                            @for ($i = 1; $i <= 25; $i++)
                                <option value="{{ $i }}" @selected((string) ($row->mod_rank ?? '1') === (string) $i)>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label for="mod_display_rank" class="col-sm-3 col-form-label">Display Order</label>
                    <div class="col-sm-9">
                        <select class="form-select" id="mod_display_rank" name="mod_display_rank" style="width:auto;">
                            <option value="0" @selected(($row->mod_display_rank ?? '0') === '0')>N/A (Stand Alone)</option>
                            <option value="1" @selected(($row->mod_display_rank ?? '') === '1')>Before Core Content</option>
                            <option value="2" @selected(($row->mod_display_rank ?? '') === '2')>After Core Content</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 row">
                    <label class="col-sm-3 col-form-label">Enable?</label>
                    <div class="col-sm-9">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="mod_enable" value="1" id="mod_enableYes" @checked($row === null || ((int) $row->mod_enable) === 1)>
                            <label class="form-check-label" for="mod_enableYes">Yes</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="mod_enable" value="0" id="mod_enableNo" @checked($row !== null && ((int) $row->mod_enable) === 0)>
                            <label class="form-check-label" for="mod_enableNo">No</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ $row !== null ? 'Edit' : 'Add' }} Custom Module</button>
            </form>
        @endif
    </section>
</x-public-layout>
