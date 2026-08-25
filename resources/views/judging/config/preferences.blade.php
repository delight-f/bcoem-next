<x-public-layout :ctx="$ctx" :show-hero="false">
    @php($j = $judging)
    <section class="container mt-4 mb-3">
        <h1>{{ $ctx->contestStr('contestName') }}: Judging/Competition Organization Preferences</h1>

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

            <div class="mb-3 row">
                <label for="jPrefsBottleNum" class="col-sm-3 col-form-label">Number of Bottles Required per Entry</label>
                <div class="col-sm-6">
                    <select class="form-select" id="jPrefsBottleNum" name="jPrefsBottleNum">
                        @for ($i = 1; $i <= 15; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsBottleNum', $j['jPrefsBottleNum'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                    <div class="form-text">Most competitions require at least two bottles.</div>
                </div>
            </div>

            <fieldset class="mb-3">
                <legend class="col-sm-3 col-form-label pt-0">Use Queued Judging</legend>
                <div class="col-sm-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="jPrefsQueued" id="jPrefsQueued_Y" value="Y" required @checked(old('jPrefsQueued', $j['jPrefsQueued'] ?? 'Y') === 'Y')>
                        <label class="form-check-label" for="jPrefsQueued_Y">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="jPrefsQueued" id="jPrefsQueued_N" value="N" required @checked(old('jPrefsQueued', $j['jPrefsQueued'] ?? 'Y') === 'N')>
                        <label class="form-check-label" for="jPrefsQueued_N">No</label>
                    </div>
                </div>
            </fieldset>

            <div class="mb-3 row">
                <span class="col-sm-3 col-form-label">Scoresheet Unique Identifier</span>
                <div class="col-sm-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="prefsDisplaySpecial" id="prefsDisplaySpecial_J" value="J" required @checked(old('prefsDisplaySpecial', $prefsDisplaySpecial ?? 'J') === 'J')>
                        <label class="form-check-label" for="prefsDisplaySpecial_J">6-Character Judging Number</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="prefsDisplaySpecial" id="prefsDisplaySpecial_E" value="E" required @checked(old('prefsDisplaySpecial', $prefsDisplaySpecial ?? 'J') === 'E')>
                        <label class="form-check-label" for="prefsDisplaySpecial_E">6-Digit Entry Number</label>
                    </div>
                </div>
            </div>

            <div class="mb-3 row">
                <span class="col-sm-3 col-form-label">Electronic Scoresheets</span>
                <div class="col-sm-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="prefsEval" id="prefsEval_1" value="1" required @checked((string) old('prefsEval', $prefsEval ?? '0') === '1')>
                        <label class="form-check-label" for="prefsEval_1">Enable</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="prefsEval" id="prefsEval_0" value="0" required @checked((string) old('prefsEval', $prefsEval ?? '0') === '0')>
                        <label class="form-check-label" for="prefsEval_0">Disable</label>
                    </div>
                </div>
            </div>

            {{-- Electronic-scoresheet block: written only when prefsEval=1,
                 exactly like process_judging_preferences.inc.php. --}}
            @if ((string) old('prefsEval', $prefsEval ?? '0') === '1')
                <div class="mb-3 row">
                    <span class="col-sm-3 col-form-label">Entry Evaluation Scoresheet</span>
                    <div class="col-sm-6">
                        @foreach ([1 => 'BJCP Classic Scoresheet (All Style Types - Including Custom)', 2 => 'BJCP Checklist Scoresheet (Beer Only)', 3 => 'BJCP Structured Scoresheet (Beer, Mead, and Cider Only)', 4 => 'NW Cider Cup Structured Scoresheet (Cider Only)'] as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="jPrefsScoresheet" id="jPrefsScoresheet_{{ $value }}" value="{{ $value }}" required @checked((string) old('jPrefsScoresheet', $j['jPrefsScoresheet'] ?? '') === (string) $value)>
                                <label class="form-check-label" for="jPrefsScoresheet_{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="jPrefsMinWords" class="col-sm-3 col-form-label">Minimum Words for Scoresheet Comment/Feedback Fields</label>
                    <div class="col-sm-6">
                        <input class="form-control" id="jPrefsMinWords" name="jPrefsMinWords" type="number" min="0" value="{{ old('jPrefsMinWords', $j['jPrefsMinWords'] ?? '') }}">
                        <div class="form-text">Leave blank or enter zero for no enforced minimum.</div>
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="jPrefsScoreDispMax" class="col-sm-3 col-form-label">Maximum Difference for Consensus Scores</label>
                    <div class="col-sm-6">
                        <select class="form-select" id="jPrefsScoreDispMax" name="jPrefsScoreDispMax">
                            @for ($i = 1; $i <= 10; $i++)
                                <option value="{{ $i }}" @selected((string) old('jPrefsScoreDispMax', $j['jPrefsScoreDispMax'] ?? '1') === (string) $i)>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="jPrefsJudgingOpen" class="col-sm-3 col-form-label">Judging Open Date and Time</label>
                    <div class="col-sm-6">
                        <input class="form-control" id="jPrefsJudgingOpen" name="jPrefsJudgingOpen" type="text" placeholder="YYYY-MM-DD hh:mm AM" value="{{ old('jPrefsJudgingOpen', \App\Support\Tenant\DateFmt::dateTime($j['jPrefsJudgingOpen'] ?? null, $ctx->prefsStr('prefsTimeZone'), 999, 1, 'system', withZone: false) ?? '') }}">
                    </div>
                </div>

                <div class="mb-3 row">
                    <label for="jPrefsJudgingClosed" class="col-sm-3 col-form-label">Judging Close Date and Time</label>
                    <div class="col-sm-6">
                        <input class="form-control" id="jPrefsJudgingClosed" name="jPrefsJudgingClosed" type="text" placeholder="YYYY-MM-DD hh:mm AM" value="{{ old('jPrefsJudgingClosed', \App\Support\Tenant\DateFmt::dateTime($j['jPrefsJudgingClosed'] ?? null, $ctx->prefsStr('prefsTimeZone'), 999, 1, 'system', withZone: false) ?? '') }}">
                    </div>
                </div>
            @endif

            <div class="mb-3 row">
                <label for="jPrefsCapJudges" class="col-sm-3 col-form-label">Judge Limit</label>
                <div class="col-sm-6">
                    <input class="form-control" id="jPrefsCapJudges" name="jPrefsCapJudges" type="number" min="0" value="{{ old('jPrefsCapJudges', $j['jPrefsCapJudges'] ?? '') }}">
                    <div class="form-text">Limit to the number of judges that may sign up. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-3 row">
                <label for="jPrefsCapStewards" class="col-sm-3 col-form-label">Steward Limit</label>
                <div class="col-sm-6">
                    <input class="form-control" id="jPrefsCapStewards" name="jPrefsCapStewards" type="number" min="0" value="{{ old('jPrefsCapStewards', $j['jPrefsCapStewards'] ?? '') }}">
                    <div class="form-text">Limit to the number of stewards that may sign up. Leave blank for no limit.</div>
                </div>
            </div>

            <div class="mb-3 row">
                <label for="jPrefsFlightEntries" class="col-sm-3 col-form-label">Maximum Entries per Flight</label>
                <div class="col-sm-6">
                    <select class="form-select" id="jPrefsFlightEntries" name="jPrefsFlightEntries">
                        @for ($i = 1; $i <= 50; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsFlightEntries', $j['jPrefsFlightEntries'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="mb-3 row">
                <label for="jPrefsRounds" class="col-sm-3 col-form-label">Maximum Rounds per Session</label>
                <div class="col-sm-6">
                    <select class="form-select" id="jPrefsRounds" name="jPrefsRounds">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsRounds', $j['jPrefsRounds'] ?? '1') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="mb-3 row">
                <label for="jPrefsMaxBOS" class="col-sm-3 col-form-label">Maximum Places in BOS Round</label>
                <div class="col-sm-6">
                    <select class="form-select" id="jPrefsMaxBOS" name="jPrefsMaxBOS">
                        @for ($i = 1; $i <= 4; $i++)
                            <option value="{{ $i }}" @selected((string) old('jPrefsMaxBOS', $j['jPrefsMaxBOS'] ?? '3') === (string) $i)>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Set Preferences</button>
        </form>
    </section>
</x-public-layout>
