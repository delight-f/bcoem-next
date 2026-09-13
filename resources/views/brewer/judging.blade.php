<x-public-layout
    :ctx="$ctx"
    :salutation="$salutation"
    :show-hero="false"
>
    <section id="edit-judging" class="landing-page-section mt-6 mb-4">
        <h1>{{ __('site.judging_preferences') }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ url('/list/edit-judging') }}">
            @csrf

            {{-- Judge block (brewer_form_2.pub.php #judge-preferences).
                 Hidden once the judge cap closes the window unless the user
                 is already a judge — they must be able to opt back out. --}}
            @if ($canEditJudge)
                <section id="judge-preferences" class="mb-6">
                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>{{ __('site.judge') }}</strong></label>
                        <div class="col-md-9">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudge" value="Y" id="brewerJudgeY"
                                       @checked($brewer->brewerJudge === 'Y')>
                                <label class="form-check-label" for="brewerJudgeY">{{ __('site.yes') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudge" value="N" id="brewerJudgeN"
                                       @checked($brewer->brewerJudge !== 'Y')>
                                <label class="form-check-label" for="brewerJudgeN">{{ __('site.no') }}</label>
                            </div>
                            <div class="form-text">{{ __('site.judge_willing_text') }}</div>
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label for="brewerJudgeID" class="col-md-3 col-form-label"><strong>{{ __('site.bjcp_id') }}</strong></label>
                        <div class="col-md-9">
                            <input class="form-control" id="brewerJudgeID" name="brewerJudgeID" type="text"
                                   value="{{ old('brewerJudgeID', $brewer->brewerJudgeID) }}">
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>BJCP {{ __('site.bjcp_mead') }}</strong></label>
                        <div class="col-md-9">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudgeMead" value="Y" id="meadY"
                                       @checked($brewer->brewerJudgeMead === 'Y')>
                                <label class="form-check-label" for="meadY">{{ __('site.yes') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudgeMead" value="N" id="meadN"
                                       @checked($brewer->brewerJudgeMead !== 'Y')>
                                <label class="form-check-label" for="meadN">{{ __('site.no') }}</label>
                            </div>
                            <div class="form-text">{{ __('site.bjcp_mead_text') }}</div>
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>BJCP {{ __('site.bjcp_cider') }}</strong></label>
                        <div class="col-md-9">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudgeCider" value="Y" id="ciderY"
                                       @checked($brewer->brewerJudgeCider === 'Y')>
                                <label class="form-check-label" for="ciderY">{{ __('site.yes') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerJudgeCider" value="N" id="ciderN"
                                       @checked($brewer->brewerJudgeCider !== 'Y')>
                                <label class="form-check-label" for="ciderN">{{ __('site.no') }}</label>
                            </div>
                            <div class="form-text">{{ __('site.bjcp_cider_text') }}</div>
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>{{ __('site.bjcp_rank') }}</strong></label>
                        <div class="col-md-9">
                            @foreach ($ranks as $i => $rank)
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="brewerJudgeRank[]"
                                           value="{{ $rank }}" id="rank_{{ $i }}"
                                           @checked(in_array($rank, $selectedRanks, true)
                                               || ($selectedRanks === [] && $rank === 'Non-BJCP'))>
                                    <label class="form-check-label" for="rank_{{ $i }}">{{ $rank }}</label>
                                </div>
                            @endforeach
                            <div class="form-text">{{ __('site.rank_note') }}</div>
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>{{ __('site.designations') }}</strong></label>
                        <div class="col-md-9">
                            @foreach (array_slice($ranks, 12) as $i => $designation)
                                <div class="form-check form-check-inline">
                                    <input class="checkbox" type="checkbox" name="brewerJudgeRank[]"
                                           value="{{ $designation }}" id="desig_{{ $i }}"
                                           @checked(in_array($designation, $selectedRanks, true))>
                                    <label class="form-check-label" for="desig_{{ $i }}">{{ $designation }}</label>
                                </div>
                            @endforeach
                            <div class="form-text">{{ __('site.designations_note') }}</div>
                        </div>
                    </div>

                    <div class="mb-4 row">
                        <label for="brewerJudgeExp" class="col-md-3 col-form-label"><strong>{{ __('site.competitions_judged') }}</strong></label>
                        <div class="col-md-9">
                            <select class="form-select" name="brewerJudgeExp" id="brewerJudgeExp" required>
                                @foreach ($experience as $exp)
                                    <option value="{{ $exp }}" @selected(old('brewerJudgeExp', $brewer->brewerJudgeExp ?? '') === $exp)>{{ $exp }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('site.competitions_judged_text') }}</div>
                        </div>
                    </div>

                    @if ($styles->isNotEmpty())
                        <fieldset class="mb-4">
                            <legend class="col-form-label pt-0"><strong>{{ __('site.preferred_styles') }}</strong></legend>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="fs-6 text-danger"><strong>{{ __('site.likes_note') }}</strong></p>
                                    @foreach ($styles as $style)
                                        <div class="form-check">
                                            <input class="checkbox" type="checkbox" name="brewerJudgeLikes[]"
                                                   value="{{ $style->id }}" id="like_{{ $style->id }}"
                                                   @checked(in_array((string) $style->id, $judgeLikes, true))>
                                            <label class="form-check-label" for="like_{{ $style->id }}">{{ $styleLabel($style) }}</label>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="col-md-6">
                                    <p class="fs-6 text-danger"><strong>{{ __('site.dislikes_note') }}</strong></p>
                                    @foreach ($styles as $style)
                                        <div class="form-check">
                                            <input class="checkbox" type="checkbox" name="brewerJudgeDislikes[]"
                                                   value="{{ $style->id }}" id="dislike_{{ $style->id }}"
                                                   @checked(in_array((string) $style->id, $judgeDislikes, true))>
                                            <label class="form-check-label" for="dislike_{{ $style->id }}">{{ $styleLabel($style) }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </fieldset>
                    @endif

                    @if ($locations->isNotEmpty())
                        <fieldset class="mb-4">
                            <legend class="col-form-label pt-0"><strong>{{ __('site.judging_availability') }}</strong></legend>
                            @foreach ($locations as $loc)
                                <div class="mb-2 row">
                                    <label class="col-md-3 col-form-label">{{ $loc->judgingLocName }}</label>
                                    <div class="col-md-9">
                                        <select class="form-select" name="brewerJudgeLocation[]" aria-label="{{ $loc->judgingLocName }}">
                                            <option value="Y-{{ $loc->id }}" @selected(in_array('Y-'.$loc->id, $judgeLocations, true))>{{ __('site.yes') }}</option>
                                            <option value="N-{{ $loc->id }}" @selected(! in_array('Y-'.$loc->id, $judgeLocations, true))>{{ __('site.no') }}</option>
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </fieldset>
                    @endif
                    @if ($judgeAssigned)
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="confirmDeregisterJudgeAll" value="Y"
                                   id="confirmDeregisterJudgeAll" @checked(old('confirmDeregisterJudgeAll') === 'Y')>
                            <label class="form-check-label" for="confirmDeregisterJudgeAll">{{ __('site.deregister_confirm_judge') }}</label>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Steward block (brewer_form_2.pub.php #steward-preferences). --}}
            @if ($canEditSteward)
                <section id="steward-preferences" class="mb-6">
                    <div class="mb-4 row">
                        <label class="col-md-3 col-form-label"><strong>{{ __('site.stewarding') }}</strong></label>
                        <div class="col-md-9">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerSteward" value="Y" id="stewardY"
                                       @checked($brewer->brewerSteward === 'Y')>
                                <label class="form-check-label" for="stewardY">{{ __('site.yes') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="brewerSteward" value="N" id="stewardN"
                                       @checked($brewer->brewerSteward !== 'Y')>
                                <label class="form-check-label" for="stewardN">{{ __('site.no') }}</label>
                            </div>
                            <div class="form-text">{{ __('site.steward_willing_text') }}</div>
                        </div>
                    </div>

                    @if ($locations->isNotEmpty())
                        <fieldset class="mb-4">
                            <legend class="col-form-label pt-0"><strong>{{ __('site.stewarding_availability') }}</strong></legend>
                            @foreach ($locations as $loc)
                                <div class="mb-2 row">
                                    <label class="col-md-3 col-form-label">{{ $loc->judgingLocName }}</label>
                                    <div class="col-md-9">
                                        <select class="form-select" name="brewerStewardLocation[]" aria-label="{{ $loc->judgingLocName }}">
                                            <option value="Y-{{ $loc->id }}" @selected(in_array('Y-'.$loc->id, $stewardLocations, true))>{{ __('site.yes') }}</option>
                                            <option value="N-{{ $loc->id }}" @selected(! in_array('Y-'.$loc->id, $stewardLocations, true))>{{ __('site.no') }}</option>
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </fieldset>
                    @endif
                    @if ($stewardAssigned)
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="confirmDeregisterStewardAll" value="Y"
                                   id="confirmDeregisterStewardAll" @checked(old('confirmDeregisterStewardAll') === 'Y')>
                            <label class="form-check-label" for="confirmDeregisterStewardAll">{{ __('site.deregister_confirm_steward') }}</label>
                        </div>
                    @endif
                </section>
            @endif

            <section id="staff-preferences" class="mb-6">
                <div class="mb-4 row">
                    <label class="col-md-3 col-form-label"><strong>{{ __('site.staffing') }}</strong></label>
                    <div class="col-md-9">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="brewerStaff" value="Y" id="staffY"
                                   @checked($brewer->brewerStaff === 'Y')>
                            <label class="form-check-label" for="staffY">{{ __('site.yes') }}</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="brewerStaff" value="N" id="staffN"
                                   @checked($brewer->brewerStaff !== 'Y')>
                            <label class="form-check-label" for="staffN">{{ __('site.no') }}</label>
                        </div>
                    </div>
                </div>
            </section>

            <section id="judge-steward-waiver" class="mb-6">
                <div class="mb-4 row">
                    <label class="col-md-3 col-form-label"><strong>{{ __('site.waiver') }}</strong></label>
                    <div class="col-md-9">
                        <p>{{ __('site.waiver_voluntary_text') }}</p>
                        <div class="form-check">
                            <input class="checkbox" type="checkbox" name="brewerJudgeWaiver" value="Y"
                                   id="brewerJudgeWaiver" @checked(true)>
                            <label class="form-check-label" for="brewerJudgeWaiver">{{ __('site.waiver_accept') }}</label>
                        </div>
                        @error('brewerJudgeWaiver')
                            <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </section>

            <section id="organizer-notes" class="mb-6">
                <div class="mb-4 row">
                    <label for="brewerJudgeNotes" class="col-md-3 col-form-label"><strong>{{ __('site.organizer_notes') }}</strong></label>
                    <div class="col-md-9">
                        <input class="form-control" id="brewerJudgeNotes" name="brewerJudgeNotes" type="text"
                               value="{{ old('brewerJudgeNotes', $brewer->brewerJudgeNotes) }}">
                        <div class="form-text">{{ __('site.organizer_notes_text') }}</div>
                    </div>
                </div>
            </section>

            <button type="submit" class="btn btn-lg btn-primary">{{ __('site.save_preferences') }}</button>
        </form>
    </section>
</x-public-layout>
