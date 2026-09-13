<x-public-layout :ctx="$ctx" :show-hero="false">
    <section class="landing-page-section mt-6 mb-4">
        <h1>Judge Information</h1>

        @if (session('status') === 'saved')
            <div class="alert alert-success">Your judge information has been saved.</div>
        @endif

        <form method="post" action="{{ route('judge.signup.store') }}">
            @csrf
            <table class="table">
                <tr>
                    <td class="dataLabel">BJCP Judge ID:</td>
                    <td><input name="brewerJudgeID" type="text" size="10" value="{{ $brewer->brewerJudgeID }}"></td>
                </tr>
                <tr>
                    <td class="dataLabel">Mead Judge Endorsement:</td>
                    <td>
                        Have you taken <strong>and passed</strong> the BJCP Mead Exam?
                        <label class="d-block"><input type="radio" name="brewerJudgeMead" value="Y" @checked($brewer->brewerJudgeMead === 'Y')> Yes</label>
                        <label class="d-block"><input type="radio" name="brewerJudgeMead" value="N" @checked($brewer->brewerJudgeMead !== 'Y')> No</label>
                    </td>
                </tr>
                <tr>
                    <td class="dataLabel">Judge Rank:</td>
                    <td>
                        <fieldset class="mb-2">
                            <legend class="text-base">BJCP Designations</legend>
                            @foreach ($ranks as $rank)
                                <label class="d-block">
                                    <input type="radio" name="brewerJudgeRank[]" value="{{ $rank }}" @checked(in_array($rank, $selectedRanks, true) || ($selectedRanks === [] && $rank === 'Novice'))>
                                    {{ $rank === 'Novice' ? 'Non-BJCP' : $rank }}
                                </label>
                            @endforeach
                        </fieldset>
                        <fieldset>
                            <legend class="text-base">Other Designations</legend>
                            @foreach ($designations as $designation)
                                <label class="d-block">
                                    <input type="checkbox" name="brewerJudgeRank[]" value="{{ $designation }}" @checked(in_array($designation, $selectedRanks, true))>
                                    {{ $designation }}
                                </label>
                            @endforeach
                            <em>Only the first two checked will appear on your Judge Scoresheet Labels.</em>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <td class="dataLabel">Preferred:</td>
                    <td>
                        <p>Check all styles that you <em>prefer</em> to judge. Leaving a style unchecked indicates that you are OK to judge it.</p>
                        @foreach ($styles as $style)
                            <label class="d-inline-block me-4">
                                <input type="checkbox" name="brewerJudgeLikes[]" value="{{ $style->id }}" @checked(in_array((string) $style->id, $likes, true))>
                                {{ ltrim($style->brewStyleGroup, '0') }}{{ $style->brewStyleNum }}: {{ $style->brewStyle }}
                            </label>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td class="dataLabel">Not Preferred:</td>
                    <td>
                        <p>Check all styles that you <em>do not wish</em> to judge. There is no need to mark styles for which you have entries; the system will not assign you to any table where you have entries.</p>
                        @foreach ($styles as $style)
                            <label class="d-inline-block me-4">
                                <input type="checkbox" name="brewerJudgeDislikes[]" value="{{ $style->id }}" @checked(in_array((string) $style->id, $dislikes, true))>
                                {{ ltrim($style->brewStyleGroup, '0') }}{{ $style->brewStyleNum }}: {{ $style->brewStyle }}
                            </label>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td></td>
                    <td><button type="submit" class="btn btn-primary">Submit Judge Information</button></td>
                </tr>
            </table>
        </form>
    </section>
</x-public-layout>
