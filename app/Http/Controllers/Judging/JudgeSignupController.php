<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Public judge signup (spec §6 P4.3). Legacy: pub/judge.pub.php +
 * pub/judge_info.pub.php + pub/judge_closed.sec.php, saved through the
 * brewer edit branch of process.inc.php.
 *
 * Preference capture is identical to legacy judge_info: BJCP ID, mead
 * exam radios, rank radios (BJCP designations) + designation checkboxes,
 * preferred/not-preferred style checkbox grids. Submitting volunteers the
 * participant as a judge (brewerJudge='Y'); every other profile column is
 * left untouched — legacy re-posted them as hidden passthrough fields.
 *
 * Window gating follows Slice A conventions (WindowStates via
 * Windows::derive): the form renders and saves only while the judge
 * window is Open; otherwise the judge_closed page is shown and POSTs are
 * refused without a write.
 */
final class JudgeSignupController extends Controller
{
    /** Rank RADIOS exactly as pub/judge_info.pub.php renders them. */
    private const RANKS = [
        'Novice', 'Rank Pending', 'Apprentice', 'Provisional', 'Recognized',
        'Certified', 'National', 'Master', 'Grand Master', 'Honorary Master',
        'Honorary Grand Master',
    ];

    /** Designation checkboxes exactly as pub/judge_info.pub.php renders them. */
    private const DESIGNATIONS = [
        'Professional Brewer', 'Beer Sommelier', 'Certified Cicerone',
        'Master Cicerone', 'Certified Cider Guide', 'Certified Pommelier',
        'Judge with Sensory Training',
    ];

    public function show(): View|RedirectResponse
    {
        $brewer = $this->brewerRow();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();

        if ($this->judgeState($ctx) !== WindowState::Open) {
            return view('judging.judge-closed', ['ctx' => $ctx]);
        }

        $styles = DB::table('styles')->where('brewStyleActive', 'Y')
            ->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get();

        return view('judging.judge-signup', [
            'ctx' => $ctx,
            'brewer' => $brewer,
            'styles' => $styles,
            'ranks' => self::RANKS,
            'designations' => self::DESIGNATIONS,
            'selectedRanks' => array_filter(explode(',', (string) $brewer->brewerJudgeRank)),
            'likes' => $this->explodeIds($brewer->brewerJudgeLikes),
            'dislikes' => $this->explodeIds($brewer->brewerJudgeDislikes),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $brewer = $this->brewerRow();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        if ($this->judgeState($ctx) !== WindowState::Open) {
            return redirect('/judge');
        }

        $data = $request->validate([
            'brewerJudgeID' => ['nullable', 'string', 'max:25'],
            'brewerJudgeMead' => ['nullable', 'in:Y,N'],
            'brewerJudgeRank' => ['nullable', 'array'],
            'brewerJudgeRank.*' => ['string'],
            'brewerJudgeLikes' => ['nullable', 'array'],
            'brewerJudgeLikes.*' => ['integer'],
            'brewerJudgeDislikes' => ['nullable', 'array'],
            'brewerJudgeDislikes.*' => ['integer'],
        ]);

        // Legacy stores the full submitted rank/designation selection as a
        // CSV; the "first two" rule is a scoresheet-label rendering concern.
        $rankValues = array_intersect(
            array_merge(self::RANKS, self::DESIGNATIONS),
            (array) ($data['brewerJudgeRank'] ?? []),
        );

        DB::table('brewer')->where('id', $brewer->id)->update([
            'brewerJudge' => 'Y',
            'brewerJudgeID' => isset($data['brewerJudgeID']) ? strtoupper($data['brewerJudgeID']) ?: null : $brewer->brewerJudgeID,
            'brewerJudgeMead' => $data['brewerJudgeMead'] ?? 'N',
            'brewerJudgeRank' => $rankValues === [] ? null : implode(',', $rankValues),
            'brewerJudgeLikes' => $this->commaJoin($data['brewerJudgeLikes'] ?? null),
            'brewerJudgeDislikes' => $this->commaJoin($data['brewerJudgeDislikes'] ?? null),
        ]);

        DB::table('staff')->updateOrInsert(
            ['uid' => $brewer->uid],
            ['staff_judge' => 1],
        );

        return redirect('/judge')->with('status', 'saved');
    }

    private function judgeState(TenantContext $ctx): WindowState
    {
        return Windows::derive($ctx, time())->judge;
    }

    private function brewerRow(): ?\stdClass
    {
        $id = Auth::id();
        if ($id === null) {
            return null;
        }

        return DB::table('brewer')->where('uid', $id)->first();
    }

    /**
     * @param  list<int>|null  $values
     */
    private function commaJoin(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return implode(',', $values);
    }

    /** @return list<string> */
    private function explodeIds(mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        return array_values(array_filter(explode(',', (string) $stored)));
    }
}
