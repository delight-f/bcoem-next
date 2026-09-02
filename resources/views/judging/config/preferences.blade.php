<x-public-layout :ctx="$ctx" :show-hero="false">
    @php($j = $judging)
    <section class="container mt-6 mb-4">
        <h1>{{ $ctx->contestStr('contestName') }}: Set Preferences</h1>
        {{-- Sibling preference-tab buttons (judging_preferences.admin.php:187-198).
             The Judging tab is the current page, so it is rendered disabled. --}}
        <div class="bcoem-admin-element hidden-print mb-3">
            <a class="btn btn-primary" style="margin: 5px 5px 5px 0" href="{{ route('admin.site_preferences.edit') }}"><span class="fa fa-cog"></span> General Preferences</a>
            <a class="btn btn-primary" style="margin: 5px 5px 5px 0" href="{{ route('admin.site_preferences.edit', ['go' => 'entries']) }}"><span class="fa fa-beer"></span> Entry Preferences</a>
            <a class="btn btn-primary" style="margin: 5px 5px 5px 0" href="{{ route('admin.site_preferences.edit', ['go' => 'email']) }}"><span class="fa fa-envelope"></span> Email Sending / Contact Display Preferences</a>
            <a class="btn btn-primary" style="margin: 5px 5px 5px 0" href="{{ route('admin.site_preferences.edit', ['go' => 'payment']) }}"><span class="fa fa-money"></span> Currency and Payment Preferences</a>
            <a class="btn btn-primary" style="margin: 5px 5px 5px 0" href="{{ route('admin.site_preferences.edit', ['go' => 'best']) }}"><span class="fa fa-trophy"></span> Best Brewer and/or Club Preferences</a>
            <a class="btn btn-primary disabled" style="margin: 5px 5px 5px 0" href="{{ route('admin.judging.preferences.show') }}"><span class="fa fa-cog"></span> Judging/Competition Organization Preferences</a>
        </div>
        <h3>Judging/Competition Organization</h3>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('admin.judging.preferences.store') }}">
            @csrf

            <div class="mb-4 row">
                <label for="jPrefsBottleNum" class="col-md-3 col-form-label">Number of Bottles Required per Entry</label>
                <div class="col-md-6">
                    <select class="form-select" id="jPrefsBottleNum" name="jPrefsBottleNum">
                        @for ($i = 1; $i <= 15; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsBottleNum', $j['jPrefsBottleNum'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                    <div class="form-text">Most competitions require at least two bottles.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-md-3 col-form-label">Use Queued Judging</span>
                <div class="col-md-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="jPrefsQueued" id="jPrefsQueued_Y" value="Y" required @checked(old('jPrefsQueued', $j['jPrefsQueued'] ?? 'Y') === 'Y')>
                        <label class="form-check-label" for="jPrefsQueued_Y">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="jPrefsQueued" id="jPrefsQueued_N" value="N" required @checked(old('jPrefsQueued', $j['jPrefsQueued'] ?? 'Y') === 'N')>
                        <label class="form-check-label" for="jPrefsQueued_N">No</label>
                    </div>
                    <div class="form-text">
                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#queuedModal">Queued Judging Info</button>
                    </div>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-md-3 col-form-label">Scoresheet Unique Identifier</span>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="prefsDisplaySpecial" id="prefsDisplaySpecial_J" value="J" required @checked(old('prefsDisplaySpecial', $prefsDisplaySpecial ?? 'J') === 'J')>
                        <label class="form-check-label" for="prefsDisplaySpecial_J">6-Character Judging Number</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="prefsDisplaySpecial" id="prefsDisplaySpecial_E" value="E" required @checked(old('prefsDisplaySpecial', $prefsDisplaySpecial ?? 'J') === 'E')>
                        <label class="form-check-label" for="prefsDisplaySpecial_E">6-Digit Entry Number</label>
                    </div>
                    <div class="form-text">
                        <p>How entries are identified to judges when evaluating. If uploading scoresheet PDF files, the PDFs for each entry should be named according to the exact 6-character number for use by the system. <span class="text-primary"><strong>Using the random, system-generated <u>Judging Numbers</u> ensures unique file names for live and archived entry data.</strong></span></p>
                    </div>
                </div>
            </div>

            <div class="mb-4 row">
                <span class="col-md-3 col-form-label">Electronic Scoresheets</span>
                <div class="col-md-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="prefsEval" id="prefsEval_1" value="1" required @checked((string) old('prefsEval', $prefsEval ?? '0') === '1')>
                        <label class="form-check-label" for="prefsEval_1">Enable</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="prefsEval" id="prefsEval_0" value="0" required @checked((string) old('prefsEval', $prefsEval ?? '0') === '0')>
                        <label class="form-check-label" for="prefsEval_0">Disable</label>
                    </div>
                    <div class="form-text">
                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#prefsEvalModal">Electronic Scoresheets Info</button>
                    </div>
                </div>
            </div>

            {{-- Electronic-scoresheet block: written only when prefsEval=1,
                 exactly like process_judging_preferences.inc.php. --}}
            @if ((string) old('prefsEval', $prefsEval ?? '0') === '1')
                <div class="mb-4 row">
                    <span class="col-md-3 col-form-label">Entry Evaluation Scoresheet</span>
                    <div class="col-md-6">
                        @foreach ([1 => 'BJCP Classic Scoresheet (All Style Types - Including Custom)', 2 => 'BJCP Checklist Scoresheet (Beer Only)', 3 => 'BJCP Structured Scoresheet (Beer, Mead, and Cider Only)', 4 => 'NW Cider Cup Structured Scoresheet (Cider Only)'] as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="jPrefsScoresheet" id="jPrefsScoresheet_{{ $value }}" value="{{ $value }}" required @checked((string) old('jPrefsScoresheet', $j['jPrefsScoresheet'] ?? '') === (string) $value)>
                                <label class="form-check-label" for="jPrefsScoresheet_{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="jPrefsMinWords" class="col-md-3 col-form-label">Minimum Words for Scoresheet Comment/Feedback Fields</label>
                    <div class="col-md-6">
                        <input class="form-control" id="jPrefsMinWords" name="jPrefsMinWords" type="number" min="0" value="{{ old('jPrefsMinWords', $j['jPrefsMinWords'] ?? '') }}">
                        <div class="form-text">Leave blank or enter zero for no enforced minimum.</div>
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="jPrefsScoreDispMax" class="col-md-3 col-form-label">Maximum Difference for Consensus Scores</label>
                    <div class="col-md-6">
                        <select class="form-select" id="jPrefsScoreDispMax" name="jPrefsScoreDispMax">
                            @for ($i = 1; $i <= 10; $i++)
                                <option value="{{ $i }}" @selected((string) old('jPrefsScoreDispMax', $j['jPrefsScoreDispMax'] ?? '1') === (string) $i)>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="jPrefsJudgingOpen" class="col-md-3 col-form-label">Judging Open Date and Time</label>
                    <div class="col-md-6">
                        <input class="form-control" id="jPrefsJudgingOpen" name="jPrefsJudgingOpen" type="text" placeholder="YYYY-MM-DD hh:mm AM" value="{{ old('jPrefsJudgingOpen', \App\Support\Tenant\DateFmt::dateTime($j['jPrefsJudgingOpen'] ?? null, $ctx->prefsStr('prefsTimeZone'), 999, 1, 'system', withZone: false) ?? '') }}">
                    </div>
                </div>

                <div class="mb-4 row">
                    <label for="jPrefsJudgingClosed" class="col-md-3 col-form-label">Judging Close Date and Time</label>
                    <div class="col-md-6">
                        <input class="form-control" id="jPrefsJudgingClosed" name="jPrefsJudgingClosed" type="text" placeholder="YYYY-MM-DD hh:mm AM" value="{{ old('jPrefsJudgingClosed', \App\Support\Tenant\DateFmt::dateTime($j['jPrefsJudgingClosed'] ?? null, $ctx->prefsStr('prefsTimeZone'), 999, 1, 'system', withZone: false) ?? '') }}">
                    </div>
                </div>
            @endif

            <div class="mb-4 row">
                <label for="jPrefsCapJudges" class="col-md-3 col-form-label">Judge Limit</label>
                <div class="col-md-6">
                    <input class="form-control" id="jPrefsCapJudges" name="jPrefsCapJudges" type="number" min="0" value="{{ old('jPrefsCapJudges', $j['jPrefsCapJudges'] ?? '') }}">
                    <div class="form-text">Limit to the number of judges that may sign up. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="jPrefsCapStewards" class="col-md-3 col-form-label">Steward Limit</label>
                <div class="col-md-6">
                    <input class="form-control" id="jPrefsCapStewards" name="jPrefsCapStewards" type="number" min="0" value="{{ old('jPrefsCapStewards', $j['jPrefsCapStewards'] ?? '') }}">
                    <div class="form-text">Limit to the number of stewards that may sign up. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="jPrefsFlightEntries" class="col-md-3 col-form-label">Maximum Entries per Flight</label>
                <div class="col-md-6">
                    <select class="form-select" id="jPrefsFlightEntries" name="jPrefsFlightEntries">
                        @for ($i = 1; $i <= 50; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsFlightEntries', $j['jPrefsFlightEntries'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="jPrefsRounds" class="col-md-3 col-form-label">Maximum Rounds per Session</label>
                <div class="col-md-6">
                    <select class="form-select" id="jPrefsRounds" name="jPrefsRounds">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsRounds', $j['jPrefsRounds'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="mb-4 row">
                <label for="jPrefsMaxBOS" class="col-md-3 col-form-label">Maximum Places in BOS Round</label>
                <div class="col-md-6">
                    <select class="form-select" id="jPrefsMaxBOS" name="jPrefsMaxBOS">
                        @for ($i = 1; $i <= 4; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsMaxBOS', $j['jPrefsMaxBOS'] ?? '3') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Set Preferences</button>
        </form>
        {{-- Legacy #queuedModal (judging_preferences.admin.php:228-245). --}}
        <div class="modal fade" id="queuedModal" tabindex="-1" role="dialog" aria-labelledby="queuedModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="queuedModalLabel">Queued Judging Info</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Indicate whether you would like to use the Queued Judging methodology (employed by the American Homebrewers Association for judging the National Hombrewers Competition).</p>
                        <p>If &ldquo;Yes,&rdquo; there is no need for competition organizers to define flights. More information can be downloaded on the <a href="https://www.bjcp.org/competitions/supplies-reference-materials/" target="_blank">BJCP's website</a>.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Legacy #prefsEvalModal (judging_preferences.admin.php:284-302). --}}
        <div class="modal fade" id="prefsEvalModal" tabindex="-1" role="dialog" aria-labelledby="prefsEvalModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title fw-bold" id="prefsEvalModalLabel">Electronic Scoresheets Info</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Enable or disable the Electronic Scoresheets function. If enabled, Admins have the option to accept judges' entry evaluations via fully electronic, web-based scoresheets built to emulate BJCP official and quasi-official paper-based forms.</p>
                        <p>If enabling Electronic Scoresheets and associated functions, Admins should also make sure to set up their installation to take full advantage of them by following the steps outlined in the <a href="https://brewingcompetitions.com/setup-electronic-scoresheets" target="_blank">Setup BCOE&amp;M Electronic Scoresheets</a> help article. Admins or competition officials should also direct all judges who will be using Electronic Scoresheets to review the <a href="https://brewingcompetitions.com/judging-with-electronic-scoresheets" target="_blank">Judging with BCOE&amp;M Electronic Scoresheets</a> primer.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
