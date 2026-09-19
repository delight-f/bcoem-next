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
            'judgeAssigned' => DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'J')->exists(),
            'stewardAssigned' => DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'S')->exists(),
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
            'confirmDeregisterJudgeAll' => ['nullable', 'in:Y'],
            'confirmDeregisterStewardAll' => ['nullable', 'in:Y'],
        ]);

        $storedJudge = (string) $brewer->brewerJudge;
        $storedSteward = (string) $brewer->brewerSteward;

        // Fields inside a gated block are ignored wholesale, and a field absent
        // from POST is never a state change — keep the stored value. Legacy
        // defaulted both flags to "N", which wiped assignments on every profile
        // save (issue #1752 defect B); the port must not.
        $judge = $this->resolveFlag($canEditJudge, $data['brewerJudge'] ?? null, $storedJudge);
        $steward = $this->resolveFlag($canEditSteward, $data['brewerSteward'] ?? null, $storedSteward);

        // Waiver consent is required whenever the user volunteers as a judge
        // or steward (the legacy checkbox is client-required; the port also
        // enforces it server-side).
        if (($judge === 'Y' || $steward === 'Y') && ($data['brewerJudgeWaiver'] ?? null) !== 'Y') {
            return back()->withErrors(['brewerJudgeWaiver' => __('site.waiver_required')])->withInput();
        }

        // Withdrawing a role removes that role's rows — but never silently.
        // When rows exist, require the explicit confirmation override
        // (issue #1752 check 3). An unchanged or absent "N" never reaches here.
        $judgeTurnsOff = $canEditJudge && $judge === 'N' && $storedJudge !== 'N';
        $stewardTurnsOff = $canEditSteward && $steward === 'N' && $storedSteward !== 'N';

        if ($judgeTurnsOff
            && ($data['confirmDeregisterJudgeAll'] ?? null) !== 'Y'
            && DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'J')->exists()) {
            return back()->withErrors(['brewerJudge' => __('site.deregister_required')])->withInput();
        }
        if ($stewardTurnsOff
            && ($data['confirmDeregisterStewardAll'] ?? null) !== 'Y'
            && DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'S')->exists()) {
            return back()->withErrors(['brewerSteward' => __('site.deregister_required')])->withInput();
        }

        $judgeLocations = $canEditJudge ? ($data['brewerJudgeLocation'] ?? null) : null;
        $stewardLocations = $canEditSteward ? ($data['brewerStewardLocation'] ?? null) : null;

        // Only a location the user was previously available at, and is now
        // turning off, may lose its rows — never a location that was never
        // marked available (issue #1752 defect B).
        $judgeWithdrawals = $judge === 'Y' && is_array($judgeLocations)
            ? $this->withdrawnLocationIds($brewer->brewerJudgeLocation, $judgeLocations)
            : [];
        $stewardWithdrawals = $steward === 'Y' && is_array($stewardLocations)
            ? $this->withdrawnLocationIds($brewer->brewerStewardLocation, $stewardLocations)
            : [];

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
            'brewerJudgeLocation' => $judgeTurnsOff
                ? null
                : (is_array($judgeLocations) ? $this->commaJoin($judgeLocations) : $brewer->brewerJudgeLocation),
            'brewerStewardLocation' => $stewardTurnsOff
                ? null
                : (is_array($stewardLocations) ? $this->commaJoin($stewardLocations) : $brewer->brewerStewardLocation),
            'brewerJudgeExp' => $data['brewerJudgeExp'] ?? null,
            'brewerJudgeNotes' => $data['brewerJudgeNotes'] ?? null,
            'brewerJudgeWaiver' => ($data['brewerJudgeWaiver'] ?? null) === 'Y' ? 'Y' : 'N',
            'brewerAssignment' => $this->assignmentJson($data),
        ]);

        // Opting out clears the role flag and, once confirmed, that role's
        // assignment rows. A profile save that does not withdraw a role never
        // deletes anything (issue #1752 defect B).
        if ($judgeTurnsOff) {
            DB::table('staff')->where('uid', $brewer->uid)->update(['staff_judge' => 0]);
            DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'J')->delete();
        } else {
            // Setting availability to "No" for a location the user was
            // available at removes only that location's rows (check 1 / 7).
            $this->deleteAssignmentsAtLocations($brewer->uid, 'J', $judgeWithdrawals);
        }

        if ($stewardTurnsOff) {
            DB::table('staff')->where('uid', $brewer->uid)->update(['staff_steward' => 0]);
            DB::table('judging_assignments')->where('bid', $brewer->uid)->where('assignment', 'S')->delete();
        } else {
            $this->deleteAssignmentsAtLocations($brewer->uid, 'S', $stewardWithdrawals);
        }

        // Marking yourself available as a judge/steward joins that pool
        // automatically. Registration and the judge-signup screen already did
        // this; the account-edit path did not, so an account that started as a
        // participant and later turned the role on never appeared in the pool
        // until an admin checked the box by hand.
        if ($judge === 'Y') {
            DB::table('staff')->updateOrInsert(['uid' => $brewer->uid], ['staff_judge' => 1]);
        }
        if ($steward === 'Y') {
            DB::table('staff')->updateOrInsert(['uid' => $brewer->uid], ['staff_steward' => 1]);
        }

        // Wizard completion → legacy post-registration landing
        // (?section=list&msg=2 account-save path).
        return redirect('/list?msg=2');
    }

    /**
     * The brewer_info block for /list (pub/brewer_info.pub.php): thank-you
     * lead, full account-info row set, judge/steward/staff availability.
     *
     * @return array<string, mixed>
     */
    public static function infoData(TenantContext $ctx): array
    {
        $brewerRow = DB::table('brewer')->where('uid', Auth::id())->first();
        // Seed/org users can lack a brewer row (they still get accounts);
        // their account info renders as an empty profile instead of 500ing
        // on ->brewerDropOff below (backlog P1). Empty profile fields match
        // the brewer schema columns so any future view-side ->read is safe.
        $brewer = $brewerRow ?? (object) array_fill_keys([
            'id', 'uid',
            'brewerFirstName', 'brewerLastName', 'brewerAddress', 'brewerCity',
            'brewerState', 'brewerZip', 'brewerCountry', 'brewerPhone1',
            'brewerPhone2', 'brewerClubs', 'brewerEmail', 'brewerStaff',
            'brewerSteward', 'brewerJudge', 'brewerJudgeID', 'brewerJudgeMead',
            'brewerJudgeCider', 'brewerJudgeRank', 'brewerJudgeLikes',
            'brewerJudgeDislikes', 'brewerJudgeLocation', 'brewerStewardLocation',
            'brewerJudgeExp', 'brewerJudgeNotes', 'brewerAssignment',
            'brewerJudgeWaiver', 'brewerAHA', 'brewerDiscount', 'brewerProAm',
            'brewerDropOff', 'brewerBreweryName', 'brewerBreweryInfo',
            'brewerMHP',
        ], '');
        $user = (array) (DB::table('users')->where('id', Auth::id())->first() ?? []);

        // pub/brewer_info.pub.php: availability CSV entries are "Y-1" —
        // flag char, dash, judging_locations id. Judge rows take types 0-1,
        // staff rows type 2 (both from brewerJudgeLocation); steward rows
        // come from brewerStewardLocation with no type filter.
        $availability = function (?string $csv, ?int $maxType) use ($ctx): array {
            $rows = [];
            foreach (array_filter(explode(',', (string) $csv)) as $item) {
                $loc = DB::table('judging_locations')->find((int) substr($item, 2));
                /** @var \stdClass|null $loc */
                if ($loc === null || ($maxType !== null && (int) $loc->judgingLocType >= $maxType)) {
                    continue;
                }
                $rows[] = [
                    'available' => substr($item, 0, 1) === 'Y',
                    'name' => (string) $loc->judgingLocName,
                    'date' => DateFmt::dateTime((int) $loc->judgingDate, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', $ctx->showTimezone())
                        .(($loc->judgingDateEnd ?? 0) ? ' - '.DateFmt::dateTime((int) $loc->judgingDateEnd, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'), 'short', $ctx->showTimezone()) : ''),
                    'location' => (string) $loc->judgingLocation,
                    'notes' => (string) $loc->judgingLocNotes,
                    'type' => (int) $loc->judgingLocType,
                ];
            }

            return $rows;
        };

        // pub/brewer_info.pub.php: rank CSV "Certified,Designation,…" renders
        // via bjcp_rank(…,2) + designations(), joined with ", ".
        $rankParts = array_values(array_filter(array_map('trim', explode(',', (string) ($brewer->brewerJudgeRank ?? ''))), fn ($p) => $p !== ''));
        $rankDisplay = match ($rankParts[0] ?? '') {
            'None', '', 'Novice', 'Non-BJCP', 'Experienced' => 'Non-BJCP Judge',
            'Professional Brewer', 'Beer Sommelier', 'Certified Cicerone', 'Master Cicerone', 'Judge with Sensory Training' => $rankParts[0],
            default => 'BJCP '.($rankParts[0] ?? '').' Judge',
        };
        $designations = $rankParts === [] ? 'N/A' : implode(', ', $rankParts);

        // style_convert(…,4): comma-separated brewStyleGroup ids -> labels.
        $styleLabels = function (?string $csv): string {
            if ($csv === null || trim($csv) === '') {
                return 'N/A';
            }
            $labels = [];
            foreach (array_filter(array_map('trim', explode(',', $csv))) as $group) {
                $style = DB::table('styles')->where('brewStyleGroup', $group)->first();
                $labels[] = $style === null
                    ? $group
                    : ltrim((string) $style->brewStyleGroup, '0').$style->brewStyleNum.': '.$style->brewStyle;
            }

            return $labels === [] ? 'N/A' : implode(', ', $labels);
        };

        $affiliations = [];
        $orgs = json_decode((string) ($brewer->brewerAssignment ?? ''), true);
        if (is_array($orgs)) {
            foreach (['affilliated', 'affilliatedOther'] as $key) {
                foreach ((array) ($orgs[$key] ?? []) as $value) {
                    if ($value !== '' && $value !== null) {
                        $affiliations[] = $value;
                    }
                }
            }
        }

        $dropoff = DB::table('drop_off')->find((int) $brewer->brewerDropOff);

        return [
            'brewer' => $brewer,
            'email' => (string) ($user['user_name'] ?? ''),
            // pub/brewer_info.pub.php lead: getTimeZoneDateTime(..., "long",
            // "date-time-no-gmt") — tz offset first, then date/time prefs,
            // then the long style ("Friday 14 August, 2026 00:47"). The zone
            // suffix follows the Show Time Zone switch rather than the legacy
            // hard-coded no-gmt variant, so every read-only date agrees.
            'updated' => DateFmt::dateTime(
                strtotime((string) ($user['userCreated'] ?? '')) ?: null,
                $ctx->prefsStr('prefsTimeZone'),
                $ctx->prefsStr('prefsDateFormat'),
                $ctx->prefsStr('prefsTimeFormat'),
                'long',
                $ctx->showTimezone(),
            ),
            'phone2' => (string) ($brewer->brewerPhone2 ?? ''),
            'address' => $brewer->brewerAddress !== '' && $brewer->brewerAddress !== null ? $brewer->brewerAddress : __('site.none_entered'),
            'city' => $brewer->brewerCity !== '' && $brewer->brewerCity !== null ? $brewer->brewerCity : __('site.none_entered'),
            'state' => $brewer->brewerState !== '' && $brewer->brewerState !== null ? $brewer->brewerState : __('site.none_entered'),
            'zip' => $brewer->brewerZip !== '' && $brewer->brewerZip !== null ? $brewer->brewerZip : __('site.none_entered'),
            'country' => $brewer->brewerCountry !== '' && $brewer->brewerCountry !== null ? $brewer->brewerCountry : __('site.none_entered'),
            'club' => $brewer->brewerClubs !== '' && $brewer->brewerClubs !== null ? $brewer->brewerClubs : __('site.none_entered'),
            'aha' => $brewer->brewerAHA !== '' && $brewer->brewerAHA !== null ? $brewer->brewerAHA : __('site.none_entered'),
            'mhp' => $brewer->brewerMHP !== '' && $brewer->brewerMHP !== null ? $brewer->brewerMHP : __('site.none_entered'),
            'mhpDisplay' => (int) $ctx->prefsStr('prefsMHPDisplay') === 1
                && (int) $ctx->prefsStr('prefsProEdition') !== 1,
            'proAm' => (string) ($brewer->brewerProAm ?? ''),
            'dropoffName' => $dropoff->dropoffLocation ?? null,
            'judgeId' => (string) ($brewer->brewerJudgeID ?? ''),
            'waiver' => (string) ($brewer->brewerJudgeWaiver ?? ''),
            'judgeNotes' => (string) ($brewer->brewerJudgeNotes ?? ''),
            'judgeExp' => (string) ($brewer->brewerJudgeExp ?? ''),
            'judgeMead' => (string) ($brewer->brewerJudgeMead ?? 'N'),
            'judgeCider' => (string) ($brewer->brewerJudgeCider ?? 'N'),
            'rankDisplay' => $rankParts === [] ? 'N/A' : $rankDisplay,
            'designations' => $designations,
            'judgeLikes' => $styleLabels($brewer->brewerJudgeLikes ?? null),
            'judgeDislikes' => $styleLabels($brewer->brewerJudgeDislikes ?? null),
            'judgeAvailability' => $availability($brewer->brewerJudgeLocation ?? null, 2),
            // staff sessions share brewerJudgeLocation (legacy quirk): type 2 only
            'staffAvailability' => array_values(array_filter(
                $availability($brewer->brewerJudgeLocation ?? null, null),
                fn ($r) => $r['type'] === 2,
            )),
            'stewardAvailability' => $availability($brewer->brewerStewardLocation ?? null, null),
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
     * A gated block ignores POSTed values outright, and an absent field is not
     * a state change: both fall back to the stored flag.
     */
    private function resolveFlag(bool $editable, ?string $posted, string $stored): string
    {
        if (! $editable || $posted === null || $posted === '') {
            return $stored;
        }

        return $posted;
    }

    /**
     * Location ids the user is withdrawing from: stored "Y-<id>", posted "N-<id>".
     * Only a real availability change may delete a row (issue #1752 defect B).
     *
     * @param  array<mixed, mixed>  $posted
     * @return list<int>
     */
    private function withdrawnLocationIds(?string $storedCsv, array $posted): array
    {
        $available = [];
        foreach (array_filter(explode(',', (string) $storedCsv)) as $item) {
            if (str_starts_with((string) $item, 'Y-')) {
                $available[(int) substr((string) $item, 2)] = true;
            }
        }

        $ids = [];
        foreach ($posted as $entry) {
            $entry = (string) $entry;
            if (str_starts_with($entry, 'N-') && isset($available[(int) substr($entry, 2)])) {
                $ids[] = (int) substr($entry, 2);
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $locationIds
     */
    private function deleteAssignmentsAtLocations(int $uid, string $assignment, array $locationIds): void
    {
        if ($locationIds === []) {
            return;
        }

        DB::table('judging_assignments')
            ->where('bid', $uid)
            ->where('assignment', $assignment)
            ->whereIn('assignLocation', $locationIds)
            ->delete();
    }

    /**
     * @param  array<mixed, mixed>|null  $values
     */
    private function commaJoin(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return implode(',', array_map('strval', $values));
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
