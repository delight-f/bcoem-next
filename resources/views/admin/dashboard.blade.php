<x-public-layout :ctx="\App\Support\Tenant\TenantContext::load()" :show-hero="false">
    {{-- index.legacy.php:90-104 — admin dashboard renders as a 9/3 row:
        left column (page-header h1 + default.admin.php), right sidebar. --}}
    <div class="row g-4">
        <div class="col-lg-9">
            <div class="admin-page-title">
                <h1>Administration Dashboard</h1>
            </div>

            {{-- mods_top.inc.php — the admin-side (go=default) missing-module-
                 file alert. Legacy renders one danger list for enabled mods
                 whose mods/<mod_filename> file is absent and one warning list
                 for disabled ones; public pages render nothing for a missing
                 file (PARITY-027). --}}
            @php
                $modsRealDir = realpath(base_path('mods'));
                $missingEnabled = [];
                $missingDisabled = [];
                foreach (DB::table('mods')->get() as $mod) {
                    $real = realpath(base_path('mods/'.$mod->mod_filename));
                    if ($real === false || $modsRealDir === false
                        || ! str_starts_with($real, $modsRealDir.DIRECTORY_SEPARATOR)) {
                        if ((int) $mod->mod_enable === 1) {
                            $missingEnabled[] = $mod->mod_filename;
                        } else {
                            $missingDisabled[] = $mod->mod_filename;
                        }
                    }
                }
            @endphp
            @if ($missingEnabled !== [])
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong>The following <u>enabled</u> custom module files were not found in the mods directory.</strong> These cannot be included or rendered:
                    <ul class="mb-0 mt-1">
                        @foreach ($missingEnabled as $file)
                            <li>{{ $file }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($missingDisabled !== [])
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    <i class="fa fa-exclamation-circle"></i>
                    <strong>The following <u>disabled</u> custom module files were not found in the mods directory.</strong> These cannot be included or rendered if enabled:
                    <ul class="mb-0 mt-1">
                        @foreach ($missingDisabled as $file)
                            <li>{{ $file }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="lead">Hello, {{ $firstName }}. <span class="small">Select the headings or icons below to view the options available to you in each category. Help is available for each overall section by selecting the question mark icon.</span></p>

            {{-- default.admin.php:473-490 action row. Reset Competition Info is
                the non-hosted variant; Publish Results / Launch Awards
                Presentation have no port equivalent and are omitted. --}}
            <div class="row bcoem-admin-element mb-4">
                <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                    <a class="btn btn-info btn-sm d-block w-100" href="http://brewingcompetitions.com/reset-comp" target="_blank" rel="noopener">Reset Competition Info <span class="fa fa-lg fa-info-circle"></span></a>
                </div>
                @if (request('msg') === '36')
                    <div class="col-12">
                        <div class="alert alert-success"><strong>Results are published.</strong></div>
                    </div>
                @endif
                @if (! $status['winnersPublished'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <button type="button" class="btn btn-warning btn-sm d-block w-100" data-bs-toggle="modal" data-bs-target="#publish-results">Publish Results <span class="fa fa-lg fa-bullhorn"></span></button>
                    </div>
                @endif
                @if ($status['postCompTasks'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <a class="btn btn-info btn-sm d-block w-100" href="#" data-bs-toggle="modal" data-bs-target="#post-comp">Post-Competition Tasks <span class="fa fa-lg fa-clipboard-list"></span></a>
                    </div>
                @endif
                @if ($status['judgingStarted'] && $status['winnerMethodTable'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <button type="button" class="btn btn-info btn-sm d-block w-100" data-bs-toggle="modal" data-bs-target="#presentationLaunch">Launch Awards Presentation <span class="fa fa-lg fa-award"></span></button>
                    </div>
                @endif
                @if ($status['showBest'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <button type="button" class="btn btn-info btn-sm d-block w-100" data-bs-toggle="modal" data-bs-target="#preview-best">Best Brewer{{ (int) \App\Support\Tenant\TenantContext::load()->prefsStr('prefsProEdition') === 0 ? '/Best Club' : '' }} Results <span class="fa fa-lg fa-trophy"></span></button>
                    </div>
                @endif
            </div>

            <div class="bcoem-admin-dashboard-accordion">
                <div class="row">
                    @foreach (['left' => $left, 'right' => $right] as $side => $sections)
                        <div class="col-12 col-lg-6">
                            <div id="accordion-{{ $side }}">
                            @foreach ($sections as [$title, $icon, $help, $links])
                                <div id="dashboard-{{ Str::slug($title) }}" class="card mb-3">
                                    <div class="card-header">
                                        <h4>
                                            <a href="#" class="text-reset" data-bs-toggle="collapse" data-bs-target="#collapse-{{ $side }}-{{ $loop->index }}" aria-expanded="false" aria-controls="collapse-{{ $side }}-{{ $loop->index }}">{{ $title }}<span class="fa {{ $icon }} float-end"></span></a>
                                            <a href="#" role="button" data-bs-toggle="modal" data-bs-target="#help-{{ $side }}-{{ $loop->index }}"
                                                onclick="event.stopPropagation()"
                                                aria-label="About {{ $title }}"><span class="fa fa-sm fa-question-circle text-primary"></span></a>
                                        </h4>
                                    </div>
                                    <div id="collapse-{{ $side }}-{{ $loop->index }}" class="collapse" data-bs-parent="#accordion-{{ $side }}">
                                        <div class="card-body d-block p-3 fs-6">
                                            @foreach ($links as [$category, $rowLinks])
                                                @if ($category === 'tables-mode')
                                                    {{-- Organizing → Tables + the Planning/Competition mode switch
                                                         (default.admin.php:1273-1287). Row links on the left; the mode
                                                         indicator + both switch buttons under them. --}}
                                                    <div class="row">
                                                        <div class="col-12 col-md-4 small">
                                                            <strong>Tables</strong>
                                                        </div>
                                                        <div class="col-12 col-md-8 small">
                                                            <ul class="d-flex flex-wrap list-unstyled gap-2 mb-1">
                                                                @foreach ($rowLinks['links'] as $item)
                                                                    <li><a href="{{ url($item['href']) }}"@if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener"@endif>{{ $item['label'] }}</a></li>
                                                                @endforeach
                                                            </ul>
                                                            @php
                                                                $modePlanning = (bool) ($rowLinks['planning'] ?? false);
                                                                $modeId = 'tables-mode-'.$side.'-'.$loop->parent->index;
                                                            @endphp
                                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                                <strong><span id="tables-mode-indicator-{{ $loop->index }}" class="{{ $modePlanning ? 'text-success' : 'text-primary' }}">{{ $modePlanning ? '*** Tables Planning Mode ***' : '*** Tables Competition Mode ***' }}</span></strong>
                                                                @if ($modePlanning)
                                                                    <button type="button" id="tables-competition-button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#tables-competition-mode-modal">
                                                                        Switch to Tables <strong>Competition</strong> Mode
                                                                    </button>
                                                                    <span class="fa fa-question-circle text-secondary" style="cursor:help" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-custom-class="admin-mode-tooltip" title="When the Tables Competition Mode function is enabled by an admin, it indicates to the system that the planning stage is over and all applicable entries have been marked as received. Table configurations and assignments can still be changed as necessary while in Competition Mode. Pullsheets will be available."></span>
                                                                @else
                                                                    <button type="button" id="table-planning-button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tables-planning-mode-modal">
                                                                        Switch to Tables <strong>Planning</strong> Mode
                                                                    </button>
                                                                    <span class="fa fa-question-circle text-secondary" style="cursor:help" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-custom-class="admin-mode-tooltip" title="When the Tables Planning Mode function is enabled, Admins can define tables, flights, rounds, judge/steward assignments, and, if enabled in Entry Preferences, associated entry limits prior to entries being marked as paid and/or received. Any table configurations and associated assignments will not be official until an Admin returns to Tables Competition Mode after entries have been sorted and marked as received in the system. Pullsheets will not be available."></span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                @elseif (isset($rowLinks['dropdown']))
                                                    {{-- Scoring rows carrying a real "Add … to..." dropdown (default.admin.php:
                                                         Scores row → score_table_choose; Custom Categories row →
                                                         score_custom_winning_choose). Links render above the dropdown. --}}
                                                    <div class="row">
                                                        <div class="col-12 col-md-4 small">
                                                            <strong>{{ $category }}</strong>
                                                        </div>
                                                        <div class="col-12 col-md-8 small">
                                                            <ul class="d-flex flex-wrap list-unstyled gap-2 mb-1">
                                                                @foreach ($rowLinks['links'] as $item)
                                                                    <li><a href="{{ url($item['href']) }}"@if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener"@endif>{{ $item['label'] }}</a></li>
                                                                @endforeach
                                                            </ul>
                                                            <div class="btn-group bcoem-admin-dashboard-select">
                                                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{ $rowLinks['dropdown']['button'] }}</button>
                                                                <ul class="dropdown-menu small" aria-labelledby="{{ $rowLinks['dropdown']['id'] ?? '' }}">
                                                                    @forelse ($rowLinks['dropdown']['items'] as $ditem)
                                                                        <li class="small"><a class="dropdown-item" href="{{ url($ditem['href']) }}">{{ $ditem['label'] }}</a></li>
                                                                    @empty
                                                                        <li class="small text-muted"><span class="dropdown-item-text">{{ $rowLinks['dropdown']['empty'] }}</span></li>
                                                                    @endforelse
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @elseif (isset($rowLinks['matrix']))
                                                    {{-- Print Bottle/Box Labels matrix (default.admin.php:934-1241): each paper
                                                         (product link on the left) owns option rows, each with a real
                                                         "Number of Labels per Entry/Table/Judge" 1-12 dropdown. --}}
                                                    <div class="row" style="padding-top: 20px;">
                                                        <div class="col-12">
                                                            <strong>{{ $category }}</strong>
                                                        </div>
                                                    </div>
                                                    @foreach ($rowLinks['matrix'] as $paper)
                                                        <div class="row @if ($loop->last) mb-0 @else mb-2 @endif">
                                                            <div class="col-12 col-md-4 small">
                                                                @php
                                                                    // Legacy left-cell tooltip names the product (e.g. "Avery 5160",
                                                                    // "Online Lables OL32"); it disambiguates the two "Letter" papers.
                                                                    $base = (string) $paper['href'];
                                                                    $stem = preg_replace('~[?#].*$~', '', $base);
                                                                    $stem = preg_replace('~\.[A-Za-z0-9]+$~', '', $stem);
                                                                    if (preg_match('/(\d+|[A-Z]+\d+[A-Z]*)$/', $stem, $m)) {
                                                                        $pcode = $m[1];
                                                                    } else {
                                                                        $pcode = basename($stem);
                                                                    }
                                                                    $ptitle = str_contains($base, 'onlinelabels.com')
                                                                        ? 'Online Lables '.$pcode
                                                                        : 'Avery '.$pcode;
                                                                @endphp
                                                                <a href="{{ $paper['href'] }}" target="_blank" rel="noopener"
                                                                   data-bs-toggle="tooltip" data-bs-placement="right"
                                                                   title="{{ $ptitle }}">{{ $paper['paper'] }} <span class="fa fa-sm fa-external-link"></span></a>
                                                            </div>
                                                            <div class="col-12 col-md-8 small">
                                                                <ul class="d-flex flex-wrap list-unstyled gap-2 mb-0">
                                                                    @foreach ($paper['options'] as $opt)
                                                                        <li class="d-inline-flex align-items-center gap-2">
                                                                            @if (isset($opt['children']))
                                                                                <span class="text-muted">{{ $opt['label'] }}</span>
                                                                                <div class="btn-group">
                                                                                    <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"
                                                                                            aria-haspopup="true" aria-expanded="false">{{ $opt['button'] }}</button>
                                                                                    <ul class="dropdown-menu">
                                                                                        @foreach ($opt['children'] as $child)
                                                                                            <li><a class="dropdown-item" href="{{ url($child['href']) }}" target="_blank" rel="noopener">{{ $child['label'] }}</a></li>
                                                                                        @endforeach
                                                                                    </ul>
                                                                                </div>
                                                                            @else
                                                                                <a href="{{ url($opt['href']) }}" target="_blank" rel="noopener">{{ $opt['label'] }}</a>
                                                                            @endif
                                                                        </li>
                                                                    @endforeach
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                @else
                                                <div class="row">
                                                    <div class="col-12 col-md-4 small">
                                                        <strong>{{ $category }}</strong>
                                                    </div>
                                                    <div class="col-12 col-md-8 small">
                                                        {{-- Legacy default.admin.php keeps per-row option groups in separate
                                                             <ul> blocks (e.g. Participants: Manage alone, then the assign links).
                                                             A row whose first entry is a bare list of link items renders one
                                                             <ul> per nested list; otherwise all items share a single <ul>. --}}
                                                        @if ($rowLinks !== [] && is_array($rowLinks[0]) && array_is_list($rowLinks[0]))
                                                            @foreach ($rowLinks as $line)
                                                                <ul class="d-flex flex-wrap list-unstyled gap-2 @if ($loop->last) mb-0 @else mb-1 @endif">
                                                                    @foreach ($line as $item)
                                                                        @if (!empty($item['modal']))
                                                                            <li><a href="#" role="button" data-bs-toggle="modal" data-bs-target="#{{ $item['modal'] }}">{{ $item['label'] }}</a></li>
                                                                        @elseif (!empty($item['todo']))
                                                                            <li><span class="text-muted" title="{{ $item['todo'] }}">{{ $item['label'] }}</span><!-- TODO: legacy output --></li>
                                                                        @else
                                                                            <li><a href="{{ url($item['href']) }}"@if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener"@endif>{{ $item['label'] }}</a></li>
                                                                        @endif
                                                                    @endforeach
                                                                </ul>
                                                            @endforeach
                                                        @else
                                                            <ul class="d-flex flex-wrap list-unstyled gap-2 mb-0">
                                                                @foreach ($rowLinks as $item)
                                                                    @if (isset($item['children']))
                                                                        <li class="text-muted">
                                                                            <span class="text-muted">{{ $item['label'] }}</span>
                                                                            @if (($item['descriptor'] ?? 'labels per entry') !== '')
                                                                                <span class="text-muted">— {{ $item['descriptor'] }}:</span>
                                                                            @endif
                                                                            @foreach ($item['children'] as $child)
                                                                                @if (! empty($child['href']))
                                                                                    <a href="{{ url($child['href']) }}">{{ $child['label'] }}</a>
                                                                                @else
                                                                                    <span class="text-muted" title="{{ $child['todo'] ?? '' }}">{{ $child['label'] }}</span><!-- TODO: legacy output -->
                                                                                @endif
                                                                            @endforeach
                                                                        </li>
                                                                    @elseif (!empty($item['modal']))
                                                                        <li><a href="#" role="button" data-bs-toggle="modal" data-bs-target="#{{ $item['modal'] }}">{{ $item['label'] }}</a></li>
                                                                    @elseif (!empty($item['todo']))
                                                                        <li><span class="text-muted" title="{{ $item['todo'] }}">{{ $item['label'] }}</span><!-- TODO: legacy output --></li>
                                                                    @else
                                                                        <li><a href="{{ url($item['href']) }}"@if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener"@endif>{{ $item['label'] }}</a></li>
                                                                    @endif
                                                                @endforeach
                                                            </ul>
                                                        @endif
                                                    </div>
                                                </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Tables Planning/Competition mode confirm dialogs + submit
             (default.admin.php mode-switch JS → ajax/tables_mode.ajax.php;
             port TablesModeController at POST /admin/judging/tables-mode). --}}
        <div class="modal fade" id="tables-planning-mode-modal" tabindex="-1" role="dialog" aria-labelledby="tables-planning-mode-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title fw-bold" id="tables-planning-mode-modal-label">Please Confirm</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to switch to Tables Planning Mode? This should only be done <strong>before</strong> entries have been sorted and marked as received.</p>
                        <p>While in Tables Planning Mode, table entry counts and pullsheets reflect all entries, not only received ones, and any table configurations and associated assignments will <strong>not</strong> be official until you return to Tables Competition Mode.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="tables-planning-button-yes" class="btn btn-success" data-bs-dismiss="modal">Yes</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="tables-competition-mode-modal" tabindex="-1" role="dialog" aria-labelledby="tables-competition-mode-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title fw-bold" id="tables-competition-mode-modal-label">Please Confirm</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to switch to Tables Competition Mode? This should only be done after <strong>all</strong> entries have been sorted and those present are <strong>marked as received</strong> in the system.</p>
                        <p>Before you do, take note that, after switching to Tables Competition Mode:</p>
                        <ul class="small mb-0">
                            <li>Table entry counts will only reflect entries marked as received.</li>
                            <li>Non-received entries&rsquo; flight designations will be reset to 1 (default) should any be marked as received after switching to Competition Mode. Flight positions and associated rounds should be reviewed prior to judging.</li>
                            <li>If there are no entries marked as received for a particular sub-style, the sub-style will be removed from the table&rsquo;s styles list.</li>
                            <li>If there are no entries marked as received for all sub-styles defined for a table, that table will be deleted.</li>
                            <li>Judges and stewards that have entries at a table where they are assigned will be un-assigned from that table as a failsafe.</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="tables-competition-button-yes" class="btn btn-success" data-bs-dismiss="modal">Yes</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                    || document.querySelector('input[name="_token"]')?.value;
                const postMode = (section) => fetch('{{ url('/admin/judging/tables-mode') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf },
                    body: 'section=' + encodeURIComponent(section),
                }).then((r) => { if (r.ok) window.location.reload(); });
                document.getElementById('tables-planning-button')?.addEventListener('click', () =>
                    new bootstrap.Modal(document.getElementById('tables-planning-mode-modal')).show());
                document.getElementById('tables-planning-button-yes')?.addEventListener('click', () => postMode('enable-planning'));
                document.getElementById('tables-competition-button')?.addEventListener('click', () =>
                    new bootstrap.Modal(document.getElementById('tables-competition-mode-modal')).show());
                document.getElementById('tables-competition-button-yes')?.addEventListener('click', () => postMode('enable-competition'));
            });
        </script>

         {{-- sidebar.admin.php: Donate + Competition Status panel --}}
         <div class="sidebar col-lg-3">
             <div class="bcoem-admin-element mb-3">
                <button type="button" class="btn btn-dark btn-sm d-block w-100 mb-2">Take a Tour of the Admin Dashboard <i class="fa fa-directions fa-lg"></i></button>
                 <a class="btn btn-dark btn-sm d-block w-100" href="https://www.brewingcompetitions.com/donation" target="_blank" rel="noopener" title="Like the software? Buy the author a beer via PayPal!">Donate <span class="fa-brands fa-lg fa-paypal"></span></a>
             </div>

            <div class="card border-info mb-3">
                <div class="card-header bg-info-subtle">
                    <h4 style="margin: 0px; padding-bottom: 5px;">Competition Status<span class="fa fa-2x fa-bar-chart text-info float-end"></span></h4>
                    <p class="small m-0"><span class="small text-muted">Updated {{ $status['updated'] }}</span></p>
                </div>
                <div class="card-body d-block p-3 fs-6 small">
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Confirmed Entries</strong>
                        <span class="float-end"><a href="{{ url('/backoffice/entries') }}">{{ $status['confirmed'] }}</a>@if (filled($status['entryLimit'])) / {{ $status['entryLimit'] }}@endif</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Unconfirmed Entries</strong>
                        <span class="float-end">{{ $status['unconfirmed'] }}</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Paid Entries</strong>
                        <span class="float-end">{{ $status['paid'] }}@if (filled($status['paidLimit'])) / {{ $status['paidLimit'] }}@endif</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Paid/Rec'd Entries</strong>
                        <span class="float-end">{{ $status['paidReceived'] }}</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Entry Counts</strong>
                        <span class="float-end"><a href="{{ url('/backoffice/count-by-style') }}">Style</a> / <a href="{{ url('/backoffice/count-by-substyle') }}">Sub-Style</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Total Fees</strong>
                        <span class="float-end">{{ $status['currencySymbol'] }}{{ number_format($status['fees'], 2) }}</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Total Fees Paid</strong>
                        <span class="float-end"><a href="{{ url('/admin/payments/mark') }}">{{ $status['currencySymbol'] }}{{ number_format($status['feesPaid'], 2) }}</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Tables Planning Mode</strong>
                        <span class="float-end">{{ $status['tablesPlanning'] ? 'On' : 'Off' }}</span>
                    </div>
                    @if ($status['evalsOn'])
                        <div class="bcoem-stat-row">
                            <strong class="text-info">Evaluations</strong>
                            <span class="float-end">{{ $status['evalTotal'] }} / {{ $status['evalEntries'] }}</span>
                        </div>
                    @endif
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Participants</strong>
                        <span class="float-end"><a href="{{ url('/backoffice/participants') }}">{{ $status['participants'] }}</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Participants with Entries</strong>
                        <span class="float-end">{{ $status['participantsWithEntries'] }}</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Available Judges</strong>
                        <span class="float-end"><a href="{{ url('/backoffice/participants?filter=judges') }}">{{ $status['judges'] }}</a>@if (filled($status['judgeCap'])) / {{ $status['judgeCap'] }}@endif</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Assigned Judges</strong>
                        <span class="float-end"><a href="{{ url('/admin/judging/flights?filter=judges') }}">{{ $status['judgesAssigned'] }}</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Available Stewards</strong>
                        <span class="float-end"><a href="{{ url('/backoffice/participants?filter=stewards') }}">{{ $status['stewards'] }}</a>@if (filled($status['stewardCap'])) / {{ $status['stewardCap'] }}@endif</span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Assigned Stewards</strong>
                        <span class="float-end"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=stewards" data-bs-toggle="tooltip" title="View assigned stewards">{{ $status['stewardsAssigned'] }}</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Available Staff</strong>
                        <span class="float-end"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff&view=yes" data-bs-toggle="tooltip" title="View available staff">{{ $status['staff'] }}</a></span>
                    </div>
                    <div class="bcoem-stat-row">
                        <strong class="text-info">Assigned Staff</strong>
                        <span class="float-end"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff" data-bs-toggle="tooltip" title="View assigned staff">{{ $status['staffAssigned'] }}</a></span>
                    </div>
                    @if ($status['organizer'] !== null)
                        <div class="bcoem-stat-row">
                            <strong class="text-info">Organizer</strong>
                            <span class="float-end"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff" data-bs-toggle="tooltip" title="View assigned staff and organizer">{{ $status['organizer']->brewerFirstName }} {{ $status['organizer']->brewerLastName }}</a></span>
                        </div>
                    @endif
                    @foreach ([
                        'Entry Registration' => $status['windows']['entry'],
                        'Drop-Off Window' => $status['windows']['dropoff'],
                        'Shipping Window' => $status['windows']['shipping'],
                        'Registration' => $status['windows']['registration'],
                        'Judge/Steward Registration' => $status['windows']['judge'],
                    ] as $label => $open)
                        <div class="bcoem-stat-row">
                            <strong class="text-info">{{ $label }}</strong>
                            @if ($open)
                                <span class="float-end text-success"><span class="fa fa-lg fa-check"></span> Open</span>
                            @else
                                <span class="float-end text-danger"><span class="fa fa-lg fa-times"></span> Closed</span>
                            @endif
                        </div>
                    @endforeach
                    {{-- sidebar.admin.php tail: server environment line --}}
                    <div class="small" style="margin-top: 10px; margin-bottom: 0px;">
                        <em><span class="text-muted">
                            <ul class="d-flex flex-wrap list-unstyled gap-2 mb-0">
                                <li>Environment Info:</li>
                                <li>PHP Version &ndash; {{ $status['phpVersion'] }}</li>
                                <li>{{ str_contains($status['dbVersion'], 'MariaDB') ? 'MariaDB Version' : 'MySQL Version' }} &ndash; {{ $status['dbVersion'] }}</li>
                            </ul>
                        </span></em>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @foreach (['left' => $left, 'right' => $right] as $side => $sections)
        @foreach ($sections as $si => [$title, $icon, $help, $links])
            <div class="modal fade" id="help-{{ $side }}-{{ $si }}" tabindex="-1" role="dialog" aria-labelledby="help-{{ $side }}-{{ $si }}-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title" id="help-{{ $side }}-{{ $si }}-title">{{ $title }}</h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                    <p>{{ $help }}</p>
                    @if (! empty($helpHtml[$title] ?? null)) {!! $helpHtml[$title] !!} @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    @endforeach

    {{-- default.admin.php:489-505 Post-Competition Tasks checklist --}}
    @if ($status['postCompTasks'])
        <div class="modal fade" id="post-comp" tabindex="-1" role="dialog" aria-labelledby="post-comp-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="post-comp-title">Post-Competition Tasks</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                <p>Below is a list of common tasks that organizers typically complete after publishing competition results.</p>
                <p><strong>If this competition is BJCP sanctioned</strong>, send or complete the BJCP Organizer's Report within 21 days of the conclusion of judging. You have two options when submitting your competition support to the BJCP:</p>
                <ul>
                    <li><strong>Manual Data Entry</strong> &ndash; go to the BJCP's <a href="https://app.bjcp.org/competitions/report" target="_blank" rel="noopener">Reporting Portal</a> to submit your competition report via their website's form.</li>
                    <li><strong>XML Document Upload</strong> &ndash; download the BJCP XML Points Report and upload the file to the <a href="https://app.bjcp.org/competitions/report" target="_blank" rel="noopener">BJCP Reporting Portal</a>. You can generate the report by expanding the Reports header on the Administration Dashboard and selecting the BJCP Points &gt; XML link.</li>
                </ul>
                <p><strong>If this competition has entrants that are members of the Master Homebrewer Program</strong>, download the <a href="{{ url('/admin/output/export?go=csv&action=all&tb=circuit&filter=mhp') }}">MHP Member Results report</a> and send to the MHP Secretary at <a href="mailto:mhpsecretary@gmail.com">mhpsecretary@gmail.com</a>. You can find this report under the Data Exports header on the Administration Dashboard.</p>
                <p><strong>If this competition is part of a regional circuit</strong>, download the <a href="{{ url('/admin/output/export?go=csv&tb=circuit') }}">Winners: Circuit Data</a> report. You can find this report under the Data Exports header on the Administration Dashboard.</p>
                <p><strong>If this competition is mailing physical scoresheets or awards to entrants</strong>, generate the appropriate Award/Medal Labels, Address Labels, and Participant Summaries by expanding the Reports header on the Administration Dashboard in the After Judging section.</p>
                <p><strong>Send thank you emails to judges, stewards, and staff.</strong> You can export email addresses by expanding the Data Exports header and selecting the appropriate exports in the Email Addresses and Associated Contact Data (CSV) list.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- default.admin.php:594+ Best Brewer/Best Club modal (bestbrewer.sec.php) --}}
    @if ($status['showBest'])
        <div class="modal fade" id="preview-best" tabindex="-1" role="dialog" aria-labelledby="preview-best-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="preview-best-title">Best Brewer{{ (int) \App\Support\Tenant\TenantContext::load()->prefsStr('prefsProEdition') === 0 ? '/Best Club' : '' }} Results</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                @if ($status['bestBrewers'] === [])
                    <p>No results are available yet.</p>
                @else
                    <h4>Best Brewer</h4>
                    <table class="table table-sm">
                        <thead><tr><th>Brewer</th><th>Club</th><th>Points</th></tr></thead>
                        <tbody>
                            @foreach ($status['bestBrewers'] as $brewer)
                                <tr>
                                    <td>{{ $brewer->name }}</td>
                                    <td>{{ $brewer->club }}</td>
                                    <td>{{ rtrim(rtrim(number_format($brewer->points, 4, '.', ''), '0'), '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @php($clubs = collect($status['bestBrewers'])->whereNotNull('club')->filter(fn ($b) => (string) $b->club !== '')->groupBy('club')->map(fn ($rows) => $rows->sum(fn ($b) => $b->points))->sortDesc())
                    @if ($clubs->isNotEmpty())
                        <h4>Best Club</h4>
                        <table class="table table-sm">
                            <thead><tr><th>Club</th><th>Points</th></tr></thead>
                            <tbody>
                                @foreach ($clubs as $club => $points)
                                    <tr><td>{{ $club }}</td><td>{{ rtrim(rtrim(number_format($points, 4, '.', ''), '0'), '.') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Launch Awards Presentation modal (legacy #presentationLaunch,
        default.admin.php:519-560): 4 methods × 3 themes link table. --}}
    @if ($status['judgingStarted'] && $status['winnerMethodTable'])
        <div class="modal fade" id="presentationLaunch" tabindex="-1" role="dialog" aria-labelledby="presentationLaunch-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="presentationLaunch-title">Launch Awards Presentation</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                <p>PowerPoint-style presentation of placing entries and Best of Show winner(s). Intended to be projected or screen-shared during your awards ceremony.</p>
                <p><strong>Only Admin-level users can access the presentation before results are published.</strong></p>
                <table class="table table-striped table-bordered mt-4">
                    <thead><tr><th>Method</th><th>Available Themes</th></tr></thead>
                    <tbody>
                        @foreach ([
                            ['By Table Number', ''],
                            ['By Table Number – Table/Medal Group Name Only', 'go=table-name-only'],
                            ['By Table/Medal Group Entry Count – Ascending', 'go=table-entry-count-asc'],
                            ['By Table/Medal Group Entry Count – Descending', 'go=table-entry-count-desc'],
                        ] as [$method, $qs])
                            <tr>
                                <td>{{ $method }}</td>
                                <td>
                                    <a href="{{ url('/awards'.($qs !== '' ? '?'.$qs : '')) }}" target="_blank" rel="noopener">Light</a> |
                                    <a href="{{ url('/awards?'.($qs !== '' ? $qs.'&' : '').'view=black') }}" target="_blank" rel="noopener">Dark</a> |
                                    <a href="{{ url('/awards?'.($qs !== '' ? $qs.'&' : '').'view=blue') }}" target="_blank" rel="noopener">Blue-Green</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif
    @if (! $status['winnersPublished'])
        <div class="modal fade" id="publish-results" tabindex="-1" role="dialog" aria-labelledby="publish-results-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="publish-results-title">Publish Results</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                <p class="py-4">Publishes winners publicly and closes the competition. All future deadlines (registration, entry, judge, judging) are snapped to now. <strong>Cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="{{ route('admin.results.publish') }}">
                        @csrf
                        <button type="submit" class="btn btn-warning">Publish Now</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif
    {{-- JN regenerate confirms (legacy jn-random/jn-style/jn-entry modals). --}}
    @foreach ([
        ['jn-random-modal', 'Random Judging Numbers', 'default',
            'Assigns a new random six-digit judging number (digits 1–9) to every entry. Existing numbers and scoresheets are not re-matched — regenerate before judging starts.'],
        ['jn-style-modal', 'Style-Prefixed Judging Numbers', 'legacy',
            'Assigns per-category sequence numbers (e.g. 21-001) continuing each category’s current sequence.'],
        ['jn-entry-modal', 'Judging Numbers = Entry Numbers', 'identical',
            'Sets every judging number to the zero-padded entry id.'],
    ] as [$id, $title, $method, $blurb])
        <div class="modal fade" id="{{ $id }}" tabindex="-1" role="dialog" aria-labelledby="{{ $id }}-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="{{ $id }}-title">{{ $title }}</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                <p class="py-4">{{ $blurb }}</p>
                <p class="text-danger fs-6">This wipes and reassigns ALL judging numbers. Cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="{{ route('admin.judging.regenerate_numbers') }}">
                        @csrf
                        <input type="hidden" name="method" value="{{ $method }}">
                        <button type="submit" class="btn btn-danger">Regenerate Now</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endforeach
    @include('admin.partials.dashboard-help-modals')
</x-public-layout>
