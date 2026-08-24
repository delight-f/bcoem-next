<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brewer profile form 2 (P3.2c) — port of `pub/brewer_form_2.pub.php` +
 * the form-2 fields of `includes/process/process_brewer.inc.php` (edit
 * branch) and `process_brewer_info.inc.php`.
 *
 * Collects the volunteer-preference fields (judge/steward/staff flags, BJCP
 * rank + designations, mead/cider eligibility, likes/dislikes, experience,
 * notes to the organizer, waiver consent, session availability) and on save
 * completes the registration wizard: the brewer row is written final and the
 * user lands on the legacy post-registration page (`brewer_info.pub.php`,
 * rendered inside /list). Brewer rows carry no draft/confirmed flag in the
 * schema (verified against sql/bcoem_baseline_3.0.X.sql), so "wizard done"
 * is exactly this write + landing — nothing else to flip.
 *
 * Window gating follows brewer_form_2.pub.php:6-11,313: once the judge or
 * steward cap closes the window its block disappears UNLESS the user is
 * already opted in — an existing judge/steward must always be able to opt
 * back out.
 */
final class BrewerForm2Controller extends Controller
{
    /** Legacy rank radios (brewer_form_2.pub.php:157-204). */
    private const RANKS = [
        'Non-BJCP', 'Mead/Cider Only', 'Rank Pending', 'Recognized',
        'Certified', 'Distinguished Certified', 'National',
        'Distinguished National', 'Master', 'Honorary Master',
        'Grand Master', 'Honorary Grand Master',
    ];

    /** Legacy designation checkboxes (brewer_form_2.pub.php:216-251). */
    private const DESIGNATIONS = [
        'Judge with Sensory Training', 'Professional Brewer',
        'Professional Mead Maker', 'Professional Cider Maker',
        'Certified Cider Guide', 'Certified Pommelier', 'Certified Cicerone',
        'Advanced Cicerone', 'Master Cicerone',
    ];

    private const EXPERIENCE = ['0', '1-5', '6-10', '10+'];

    public function show(): View|RedirectResponse
    {
        $brewer = $this->brewerRow();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        [$canEditJudge, $canEditSteward] = $this->editability($ctx, $brewer);

        $styles = DB::table('styles')->where('brewStyleActive', 'Y')
            ->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get();
        $baStyleSet = $ctx->prefsStr('prefsStyleSet') === 'BA';

        return view('brewer.judging', [
            'brewer' => $brewer,
            'ctx' => $ctx,
            'canEditJudge' => $canEditJudge,
            'canEditSteward' => $canEditSteward,
            'styles' => $styles,
            'styleLabel' => fn (\stdClass $s): string => $baStyleSet
                ? (string) $s->brewStyle
                : ltrim((string) $s->brewStyleGroup, '0').$s->brewStyleNum.': '.$s->brewStyle,
            'judgeLikes' => $this->explodeIds($brewer->brewerJudgeLikes),
            'judgeDislikes' => $this->explodeIds($brewer->brewerJudgeDislikes),
            'ranks' => array_merge(self::RANKS, self::DESIGNATIONS),
            'selectedRanks' => array_filter(explode(',', (string) $brewer->brewerJudgeRank)),
            'experience' => self::EXPERIENCE,
            'locations' => DB::table('judging_locations')->orderBy('judgingLocName')->get(),
            'judgeLocations' => $this->explodeIds($brewer->brewerJudgeLocation),
            'stewardLocations' => $this->explodeIds($brewer->brewerStewardLocation),
            'salutation' => __('site.my_account'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $brewer = $this->brewerRow();
        if ($brewer === null) {
            return redirect('/list');
        }

        $ctx = TenantContext::load();
        [$canEditJudge, $canEditSteward] = $this->editability($ctx, $brewer);

        $data = $request->validate([
            'brewerJudge' => ['nullable', 'in:Y,N'],
            'brewerSteward' => ['nullable', 'in:Y,N'],
            'brewerStaff' => ['nullable', 'in:Y,N'],
            'brewerJudgeID' => ['nullable', 'string', 'max:25'],
            'brewerJudgeMead' => ['nullable', 'in:Y,N'],
            'brewerJudgeCider' => ['nullable', 'in:Y,N'],
            'brewerJudgeRank' => ['nullable', 'array'],
            'brewerJudgeRank.*' => ['string'],
            'brewerJudgeLikes' => ['nullable', 'array'],
            'brewerJudgeLikes.*' => ['integer'],
            'brewerJudgeDislikes' => ['nullable', 'array'],
            'brewerJudgeDislikes.*' => ['integer'],
            'brewerJudgeExp' => ['nullable', 'in:'.implode(',', self::EXPERIENCE)],
            'brewerJudgeNotes' => ['nullable', 'string', 'max:500'],
            'brewerJudgeLocation' => ['nullable', 'array'],
            'brewerJudgeLocation.*' => ['regex:/^[YN]-\d+$/'],
            'brewerStewardLocation' => ['nullable', 'array'],
            'brewerStewardLocation.*' => ['regex:/^[YN]-\d+$/'],
            'brewerAssignment' => ['nullable', 'array'],
            'brewerAssignment.*' => ['string', 'max:255'],
            'brewerAssignmentOther' => ['nullable', 'string', 'max:255'],
            'brewerJudgeWaiver' => ['nullable', 'in:Y'],
        ]);

        // Fields inside a gated block are ignored wholesale — the row keeps
        // its current values (legacy renders no inputs there at all).
        $judge = $canEditJudge ? ($data['brewerJudge'] ?? (string) $brewer->brewerJudge) : (string) $brewer->brewerJudge;
        $steward = $canEditSteward ? ($data['brewerSteward'] ?? (string) $brewer->brewerSteward) : (string) $brewer->brewerSteward;

        // Waiver consent is required whenever the user volunteers as a judge
        // or steward (the legacy checkbox is client-required; the port also
        // enforces it server-side).
        if (($judge === 'Y' || $steward === 'Y') && ($data['brewerJudgeWaiver'] ?? null) !== 'Y') {
            return back()->withErrors(['brewerJudgeWaiver' => __('site.waiver_required')])->withInput();
        }

        $rankValues = array_merge(self::RANKS, self::DESIGNATIONS);

        DB::table('brewer')->where('id', $brewer->id)->update([
            'brewerStaff' => $data['brewerStaff'] ?? 'N',
            'brewerSteward' => $steward,
            'brewerJudge' => $judge,
            'brewerJudgeID' => $canEditJudge && isset($data['brewerJudgeID'])
                ? strtoupper($data['brewerJudgeID']) ?: null
                : $brewer->brewerJudgeID,
            'brewerJudgeMead' => $data['brewerJudgeMead'] ?? 'N',
            'brewerJudgeCider' => $data['brewerJudgeCider'] ?? 'N',
            'brewerJudgeRank' => $canEditJudge
                ? $this->commaJoin(array_values(array_intersect($data['brewerJudgeRank'] ?? [], $rankValues)))
                : $brewer->brewerJudgeRank,
            'brewerJudgeLikes' => $canEditJudge ? $this->commaJoin($data['brewerJudgeLikes'] ?? null) : $brewer->brewerJudgeLikes,
            'brewerJudgeDislikes' => $canEditJudge ? $this->commaJoin($data['brewerJudgeDislikes'] ?? null) : $brewer->brewerJudgeDislikes,
            'brewerJudgeLocation' => $canEditJudge ? $this->commaJoin($data['brewerJudgeLocation'] ?? null) : $brewer->brewerJudgeLocation,
            'brewerStewardLocation' => $canEditSteward ? $this->commaJoin($data['brewerStewardLocation'] ?? null) : $brewer->brewerStewardLocation,
            'brewerJudgeExp' => $data['brewerJudgeExp'] ?? null,
            'brewerJudgeNotes' => $data['brewerJudgeNotes'] ?? null,
            'brewerJudgeWaiver' => ($data['brewerJudgeWaiver'] ?? null) === 'Y' ? 'Y' : 'N',
            'brewerAssignment' => $this->assignmentJson($data),
        ]);

        // Opting out clears any existing assignment state so a stale judge/
        // steward flag never survives into P4 scheduling. Legacy deletes the
        // whole staff row when that was the user's only staff flag; zeroing
        // the flag keeps the row registration created — equivalent state.
        if ($judge === 'N') {
            DB::table('staff')->where('uid', $brewer->uid)->update(['staff_judge' => 0]);
            DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'J')->delete();
        }
        if ($steward === 'N') {
            DB::table('staff')->where('uid', $brewer->uid)->update(['staff_steward' => 0]);
            DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'S')->delete();
        }

        // Wizard completion → legacy post-registration landing
        // (?section=list&msg=2 account-save path).
        return redirect('/list?msg=2');
    }

    /**
     * The brewer_info block for /list (ticket 08 includes it from the new
     * account view). Renders the legacy "thank you / next steps" lead plus
     * the contact + volunteer summary.
     *
     * @return array{brewer: \stdClass|null, email: string, updated: string|null}
     */
    public static function infoData(TenantContext $ctx): array
    {
        $brewer = DB::table('brewer')->where('uid', Auth::id())->first();
        $user = (array) (DB::table('users')->where('id', Auth::id())->first() ?? []);

        return [
            'brewer' => $brewer,
            'email' => (string) ($user['user_name'] ?? ''),
            'updated' => DateFmt::dateTime(
                strtotime((string) ($user['userCreated'] ?? '')) ?: null,
                $ctx->prefsStr('prefsTimeZone'),
                $ctx->prefsStr('prefsDateFormat'),
                $ctx->prefsStr('prefsTimeFormat'),
                'long',
                withZone: false,
            ),
        ];
    }

    /**
     * @return array{0: bool, 1: bool} [canEditJudge, canEditSteward]
     */
    private function editability(TenantContext $ctx, \stdClass $brewer): array
    {
        $windows = Windows::derive($ctx, time());
        $open = $windows->judge === WindowState::Open;

        return [
            $open || $brewer->brewerJudge === 'Y',
            $open || $brewer->brewerSteward === 'Y',
        ];
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
    private function explodeIds(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $stored)));
    }

    /**
     * brewerAssignment JSON: {"affilliated":[...]} / {"affilliatedOther":[...]}
     * (same shape as registration — note the legacy four-l spelling).
     *
     * @param  array<string, mixed>  $data
     */
    private function assignmentJson(array $data): ?string
    {
        $out = [];
        if (! empty($data['brewerAssignment'])) {
            $out['affilliated'] = array_values($data['brewerAssignment']);
        }
        if (! empty($data['brewerAssignmentOther'])) {
            $out['affilliatedOther'] = [ucwords($data['brewerAssignmentOther'])];
        }

        return $out === [] ? null : (string) json_encode($out);
    }
}
