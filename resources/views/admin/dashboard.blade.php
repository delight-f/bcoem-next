<x-public-layout :ctx="\App\Support\Tenant\TenantContext::load()" :show-hero="false">
    {{-- index.legacy.php:90-104 — admin dashboard renders as a 9/3 row:
        left column (page-header h1 + default.admin.php), right sidebar. --}}
    <div class="row g-4">
        <div class="col-lg-9">
            <div class="page-header">
                <h1>Administration Dashboard</h1>
            </div>

            <p class="lead">Hello, {{ $firstName }}. <span class="small">Select the headings or icons below to view the options available to you in each category. Help is available for each overall section by selecting the question mark icon.</span></p>

            {{-- default.admin.php:473-490 action row. Reset Competition Info is
                the non-hosted variant; Publish Results / Launch Awards
                Presentation have no port equivalent and are omitted. --}}
            <div class="row bcoem-admin-element mb-4">
                <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                    <a class="btn btn-info btn-sm btn-block" href="http://brewingcompetitions.com/reset-comp" target="_blank" rel="noopener">Reset Competition Info <span class="fa fa-lg fa-info-circle"></span></a>
                </div>
                @if (request('msg') === '36')
                    <div class="col-12">
                        <div class="alert alert-success"><strong>Results are published.</strong></div>
                    </div>
                @endif
                @if (! $status['winnersPublished'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <button type="button" class="btn btn-warning btn-sm btn-block" data-open-modal="publish-results">Publish Results <span class="fa fa-lg fa-bullhorn"></span></button>
                    </div>
                @endif
                @if ($status['postCompTasks'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <a class="btn btn-info btn-sm btn-block" href="#" data-open-modal="post-comp">Post-Competition Tasks <span class="fa fa-lg fa-clipboard-list"></span></a>
                    </div>
                @endif
                @if ($status['showBest'])
                    <div class="col-lg-3 col-md-12" style="padding-bottom: 5px;">
                        <button type="button" class="btn btn-info btn-sm btn-block" data-open-modal="preview-best">Best Brewer{{ (int) \App\Support\Tenant\TenantContext::load()->prefsStr('prefsProEdition') === 0 ? '/Best Club' : '' }} Results <span class="fa fa-lg fa-trophy"></span></button>
                    </div>
                @endif
            </div>

            <div class="bcoem-admin-dashboard-accordion">
                <div class="row">
                    @foreach (['left' => $left, 'right' => $right] as $side => $sections)
                        <div class="col col-lg-6 col-md-12 col-sm-12 col-xs-12">
                            <div class="panel-group" id="accordion-{{ $side }}">
                            @foreach ($sections as [$title, $icon, $help, $links])
                                <div id="dashboard-{{ Str::slug($title) }}" class="panel panel-default">
                                    <div class="panel-heading">
                                        <h4 class="panel-title">
                                            <a href="#" class="panel-collapse-toggle" data-target="collapse-{{ $side }}-{{ $loop->index }}">{{ $title }}
                                                <a href="#" role="button" data-open-modal="help-{{ $side }}-{{ $loop->index }}"
                                                    onclick="event.stopPropagation()"
                                                    aria-label="About {{ $title }}"><span class="fa fa-sm fa-question-circle text-primary"></span></a><span class="fa {{ $icon }} pull-right"></span>
                                            </a>
                                        </h4>
                                    </div>
                                    <div id="collapse-{{ $side }}-{{ $loop->index }}" class="panel-collapse">
                                        <div class="panel-body">
                                            @foreach ($links as [$category, $rowLinks])
                                                <div class="row">
                                                    <div class="col col-lg-4 col-md-4 col-sm-4 col-xs-12 small">
                                                        <strong>{{ $category }}</strong>
                                                    </div>
                                                    <div class="col col-lg-8 col-md-8 col-sm-8 col-xs-12 small">
                                                        <ul class="list-inline">
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
                                                                    <li><a href="#" role="button" data-open-modal="{{ $item['modal'] }}">{{ $item['label'] }}</a></li>
                                                                @elseif (!empty($item['todo']))
                                                                    <li><span class="text-muted" title="{{ $item['todo'] }}">{{ $item['label'] }}</span><!-- TODO: legacy output --></li>
                                                                @else
                                                                    <li><a href="{{ url($item['href']) }}"@if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener"@endif>{{ $item['label'] }}</a></li>
                                                                @endif
                                                        </ul>
                                                    </div>
                                                </div>
                                            @endforeach
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
         {{-- sidebar.admin.php: Donate + Competition Status panel --}}
         <div class="sidebar col-lg-3">
             <div class="bcoem-admin-element mb-3">
                <button type="button" class="btn btn-dark btn-sm btn-block mb-2">Take a Tour of the Admin Dashboard <i class="fa fa-directions fa-lg"></i></button>
                 <a class="btn btn-dark btn-sm btn-block" href="https://www.brewingcompetitions.com/donation" target="_blank" rel="noopener" title="Like the software? Buy the author a beer via PayPal!">Donate <span class="fa-brands fa-lg fa-paypal"></span></a>
             </div>

            <div class="panel panel-info">
                <div class="panel-heading">
                    <h4 style="margin: 0px; padding-bottom: 5px;">Competition Status<span class="fa fa-2x fa-bar-chart text-info pull-right"></span></h4>
                    <p class="small m-0"><span class="small text-muted">Updated {{ $status['updated'] }}</span></p>
                </div>
                <div class="panel-body small">
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Confirmed Entries</strong>
                        <span class="pull-right"><a href="{{ url('/backoffice/entries') }}">{{ $status['confirmed'] }}</a>@if (filled($status['entryLimit'])) / {{ $status['entryLimit'] }}@endif</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Unconfirmed Entries</strong>
                        <span class="pull-right">{{ $status['unconfirmed'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Paid Entries</strong>
                        <span class="pull-right">{{ $status['paid'] }}@if (filled($status['paidLimit'])) / {{ $status['paidLimit'] }}@endif</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Paid/Rec'd Entries</strong>
                        <span class="pull-right">{{ $status['paidReceived'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Entry Counts</strong>
                        <span class="pull-right"><a href="{{ url('/backoffice/count-by-style') }}">Style</a> / <a href="{{ url('/backoffice/count-by-substyle') }}">Sub-Style</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Total Fees</strong>
                        <span class="pull-right">{{ $status['currencySymbol'] }}{{ number_format($status['fees'], 2) }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Total Fees Paid</strong>
                        <span class="pull-right"><a href="{{ url('/admin/payments/mark') }}">{{ $status['currencySymbol'] }}{{ number_format($status['feesPaid'], 2) }}</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Tables Planning Mode</strong>
                        <span class="pull-right">{{ $status['tablesPlanning'] ? 'On' : 'Off' }}</span>
                    </div>
                    @if ($status['evalsOn'])
                        <div class="bcoem-sidebar-panel">
                            <strong class="text-info">Evaluations</strong>
                            <span class="pull-right">{{ $status['evalTotal'] }} / {{ $status['evalEntries'] }}</span>
                        </div>
                    @endif
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Participants</strong>
                        <span class="pull-right"><a href="{{ url('/backoffice/participants') }}">{{ $status['participants'] }}</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Participants with Entries</strong>
                        <span class="pull-right">{{ $status['participantsWithEntries'] }}</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Available Judges</strong>
                        <span class="pull-right"><a href="{{ url('/backoffice/participants?filter=judges') }}">{{ $status['judges'] }}</a>@if (filled($status['judgeCap'])) / {{ $status['judgeCap'] }}@endif</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Assigned Judges</strong>
                        <span class="pull-right"><a href="{{ url('/admin/judging/flights?filter=judges') }}">{{ $status['judgesAssigned'] }}</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Available Stewards</strong>
                        <span class="pull-right"><a href="{{ url('/backoffice/participants?filter=stewards') }}">{{ $status['stewards'] }}</a>@if (filled($status['stewardCap'])) / {{ $status['stewardCap'] }}@endif</span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Assigned Stewards</strong>
                        <span class="pull-right"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=stewards" data-toggle="tooltip" data-placement="top" title="View assigned stewards">{{ $status['stewardsAssigned'] }}</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Available Staff</strong>
                        <span class="pull-right"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff&view=yes" data-toggle="tooltip" data-placement="top" title="View available staff">{{ $status['staff'] }}</a></span>
                    </div>
                    <div class="bcoem-sidebar-panel">
                        <strong class="text-info">Assigned Staff</strong>
                        <span class="pull-right"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff" data-toggle="tooltip" data-placement="top" title="View assigned staff">{{ $status['staffAssigned'] }}</a></span>
                    </div>
                    @if ($status['organizer'] !== null)
                        <div class="bcoem-sidebar-panel">
                            <strong class="text-info">Organizer</strong>
                            <span class="pull-right"><a href="{{ url('/admin/judging/tables') }}?action=assign&filter=staff" data-toggle="tooltip" data-placement="top" title="View assigned staff and organizer">{{ $status['organizer']->brewerFirstName }} {{ $status['organizer']->brewerLastName }}</a></span>
                        </div>
                    @endif
                    @foreach ([
                        'Entry Registration' => $status['windows']['entry'],
                        'Drop-Off Window' => $status['windows']['dropoff'],
                        'Shipping Window' => $status['windows']['shipping'],
                        'Registration' => $status['windows']['registration'],
                        'Judge/Steward Registration' => $status['windows']['judge'],
                    ] as $label => $open)
                        <div class="bcoem-sidebar-panel">
                            <strong class="text-info">{{ $label }}</strong>
                            @if ($open)
                                <span class="pull-right text-success"><span class="fa fa-lg fa-check"></span> Open</span>
                            @else
                                <span class="pull-right text-danger"><span class="fa fa-lg fa-times"></span> Closed</span>
                            @endif
                        </div>
                    @endforeach
                    {{-- sidebar.admin.php tail: server environment line --}}
                    <div class="small" style="margin-top: 10px; margin-bottom: 0px;">
                        <em><span class="text-muted">
                            <ul class="list-inline">
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
            <dialog id="help-{{ $side }}-{{ $si }}" class="modal">
                <div class="modal-box">
                    <h3 class="text-lg font-bold">{{ $title }}</h3>
                    <p>{{ $help }}</p>
                    @if (! empty($helpHtml[$title] ?? null)) {!! $helpHtml[$title] !!} @endif
                    <div class="modal-action">
                        <form method="dialog"><button class="btn">Close</button></form>
                    </div>
                </div>
            </dialog>
        @endforeach
    @endforeach

    {{-- default.admin.php:489-505 Post-Competition Tasks checklist --}}
    @if ($status['postCompTasks'])
        <dialog id="post-comp" class="modal">
            <div class="modal-box max-w-3xl">
                <h3 class="text-lg font-bold">Post-Competition Tasks</h3>
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
                <div class="modal-action">
                    <form method="dialog"><button class="btn btn-error">Close</button></form>
                </div>
            </div>
        </dialog>
    @endif

    {{-- default.admin.php:594+ Best Brewer/Best Club modal (bestbrewer.sec.php) --}}
    @if ($status['showBest'])
        <dialog id="preview-best" class="modal">
            <div class="modal-box max-w-3xl">
                <h3 class="text-lg font-bold">Best Brewer{{ (int) \App\Support\Tenant\TenantContext::load()->prefsStr('prefsProEdition') === 0 ? '/Best Club' : '' }} Results</h3>
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
                <div class="modal-action">
                    <form method="dialog"><button class="btn btn-error">Close</button></form>
                </div>
            </div>
        </dialog>
    @endif

    {{-- Publish Results confirm (legacy process.inc.php?action=publish). --}}
    @if (! $status['winnersPublished'])
        <dialog id="publish-results" class="modal">
            <div class="modal-box">
                <h3 class="text-lg font-bold">Publish Results</h3>
                <p class="py-4">Publishes winners publicly and closes the competition. All future deadlines (registration, entry, judge, judging) are snapped to now. <strong>Cannot be undone.</strong></p>
                <div class="modal-action">
                    <form method="dialog"><button class="btn">Cancel</button></form>
                    <form method="POST" action="{{ route('admin.results.publish') }}">
                        @csrf
                        <button type="submit" class="btn btn-warning">Publish Now</button>
                    </form>
                </div>
            </div>
        </dialog>
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
        <dialog id="{{ $id }}" class="modal">
            <div class="modal-box">
                <h3 class="text-lg font-bold">{{ $title }}</h3>
                <p class="py-4">{{ $blurb }}</p>
                <p class="text-error text-sm">This wipes and reassigns ALL judging numbers. Cannot be undone.</p>
                <div class="modal-action">
                    <form method="dialog"><button class="btn">Cancel</button></form>
                    <form method="POST" action="{{ route('admin.judging.regenerate_numbers') }}">
                        @csrf
                        <input type="hidden" name="method" value="{{ $method }}">
                        <button type="submit" class="btn btn-error">Regenerate Now</button>
                    </form>
                </div>
            </div>
        </dialog>
    @endforeach
    @include('admin.partials.dashboard-help-modals')
</x-public-layout>
