<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Participants (brewer accounts) back office — spec §7 P5.5, ticket P5.5.
 * Legacy: admin/participants.admin.php + process_brewer.inc.php (edit) +
 * includes/process/process_delete.inc.php go=participants (delete).
 *
 * Delete cascade mirrors legacy exactly; it destroys:
 *   - users row (id = uid) and brewer profile (uid);
 *   - every brewing entry of the participant AND, per entry, its
 *     judging_scores (eid) and judging_scores_bos (eid) rows;
 *   - judging_assignments rows (bid = uid);
 *   - staff rows (uid).
 * NOT destroyed (same as legacy): `payments` ledger rows — they survive
 * as the financial record of an account that no longer exists.
 */
final class ParticipantsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $rawFilter = $request->query('filter');
        $filter = is_string($rawFilter) ? $rawFilter : 'default';
        $rawQ = $request->query('q');
        $q = is_string($rawQ) ? trim($rawQ) : '';

        $query = DB::table('brewer')
            ->leftJoin('users', 'users.id', '=', 'brewer.uid');

        $query = match ($filter) {
            'judges' => $query->where('brewer.brewerJudge', 'Y'),
            'stewards' => $query->where('brewer.brewerSteward', 'Y'),
            // "Participants with Entries": only brewers owning >= 1 entry.
            'with_entries' => $query->whereExists(fn ($e) => $e
                ->selectRaw(1)
                ->from('brewing')
                ->whereColumn('brewing.brewBrewerID', 'brewer.uid')),
            default => $query,
        };

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like): void {
                $w->where('brewer.brewerFirstName', 'like', $like)
                    ->orWhere('brewer.brewerLastName', 'like', $like)
                    ->orWhere('brewer.brewerEmail', 'like', $like)
                    ->orWhere('brewer.brewerClubs', 'like', $like);
            });
        }

        $participants = $query
            ->orderBy('brewer.brewerLastName')->orderBy('brewer.brewerFirstName')
            ->get([
                'brewer.uid', 'brewer.brewerFirstName', 'brewer.brewerLastName',
                'brewer.brewerEmail', 'brewer.brewerClubs', 'brewer.brewerJudge',
                'brewer.brewerSteward', 'brewer.brewerAssignment',
                'brewer.brewerJudgeLocation', 'brewer.brewerJudgeID',
                'brewer.brewerJudgeRank', 'brewer.brewerStewardLocation',
                'brewer.brewerCity', 'brewer.brewerState', 'brewer.brewerPhone1',
                'brewer.brewerBreweryName',
                'users.userLevel', 'users.userCreated',
            ]);

        // Location ids for the judges/stewards filter columns: stored as
        // Y-<id> CSV (availability flags), names resolved for display.
        $locationNames = DB::table('judging_locations')->pluck('judgingLocName', 'id');
        $locationDisplay = function (?string $csv) use ($locationNames): string {
            if ($csv === null || $csv === '') {
                return '';
            }

            return collect(explode(',', $csv))
                ->map(fn (string $flag): string => $locationNames[(int) substr($flag, 2)] ?? '')
                ->filter()
                ->implode(', ');
        };

        // Entry counts in one grouped pass, merged client-side (keeps the
        // main query prefix-agnostic instead of a raw subquery).
        $entryCounts = DB::table('brewing')
            ->selectRaw('brewBrewerID, COUNT(*) AS n')
            ->groupBy('brewBrewerID')
            ->pluck('n', 'brewBrewerID');

        // "Entry Numbers" / "Judging Numbers" 6-digit CSV per participant
        // (legacy with_entries row: sprintf("%06s", entry) lists).
        $entryNumbers = DB::table('brewing')
            ->select('brewBrewerID', 'id')
            ->orderBy('id')
            ->get()
            ->groupBy('brewBrewerID')
            ->map(fn ($rows) => $rows->map(fn ($r) => sprintf('%06s', (string) $r->id))->implode(', '));
        $judgingNumbers = DB::table('brewing')
            ->select('brewBrewerID', 'brewJudgingNumber')
            ->whereNotNull('brewJudgingNumber')
            ->where('brewJudgingNumber', '!=', '')
            ->orderBy('brewJudgingNumber')
            ->get()
            ->groupBy('brewBrewerID')
            ->map(fn ($rows) => $rows->map(fn ($r) => sprintf('%06s', (string) $r->brewJudgingNumber))->implode(', '));

        $uids = $participants->pluck('uid')->all();

        // Judge scoresheet-label gate (legacy brewer_assignment(): staff.staff_judge).
        $staffJudge = $uids === [] ? [] : DB::table('staff')
            ->where('staff_judge', 1)
            ->whereIn('uid', $uids)
            ->pluck('uid')
            ->mapWithKeys(fn ($uid) => [$uid => true])
            ->all();

        // "Assigned to Table(s)" (legacy table_assignments method 2=1):
        // judging_assignments ⋈ judging_tables per uid/role, "N - Name".
        $tableAssignments = $uids === [] ? collect() : DB::table('judging_assignments as ja')
            ->leftJoin('judging_tables as jt', 'jt.id', '=', 'ja.assignTable')
            ->whereIn('ja.bid', $uids)
            ->whereIn('ja.assignment', ['J', 'S'])
            ->orderBy('ja.assignTable')
            ->get(['ja.bid', 'ja.assignment', 'jt.id as tableId', 'jt.tableNumber', 'jt.tableName'])
            ->groupBy(fn ($r) => $r->bid.'|'.$r->assignment)
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'id' => (string) $r->tableId,
                'label' => trim((string) $r->tableNumber).' - '.$r->tableName,
            ]));

        // "Has Entries In..." (legacy judge_entries): distinct category+
        // subcategory of the participant's entries, linked to the entries
        // admin filtered by brewCategorySort.
        $judgeEntries = $uids === [] ? collect() : DB::table('brewing')
            ->whereIn('brewBrewerID', $uids)
            ->orderBy('brewCategorySort')
            ->get(['brewBrewerID', 'brewCategorySort', 'brewCategory', 'brewSubCategory'])
            ->groupBy('brewBrewerID')
            ->map(fn ($rows) => $rows
                ->unique(fn ($r) => $r->brewCategory.$r->brewSubCategory)
                ->map(fn ($r) => [
                    'label' => ltrim((string) $r->brewCategory, '0').$r->brewSubCategory,
                    'filter' => $r->brewCategorySort,
                ]));

        // Participant Status modal counts (legacy get_participant_count +
        // the with-entries count).
        $statusCounts = [
            'participants' => DB::table('brewer')->count(),
            'withEntries' => DB::table('brewing')->distinct()->count('brewBrewerID'),
            'judges' => DB::table('brewer')->where('brewerJudge', 'Y')->count(),
            'stewards' => DB::table('brewer')->where('brewerSteward', 'Y')->count(),
        ];

        // Legacy ?action=print (participants.admin.php:52-160): the same
        // filtered list with a print-oriented column set, rendered for
        // the browser print dialog (fancybox iframe target). psort drives
        // the ordering (participants.admin.php:95-99).
        if ($request->query('action') === 'print') {
            $psort = (string) ($request->query('psort') ?? 'brewer_name');
            $sorted = match ($psort) {
                'club' => $participants->sortBy('brewerClubs')->values(),
                'organization' => $participants->sortBy('brewerBreweryName')->values(),
                default => $participants->sortBy('brewerLastName')->values(),
            };

            return view('admin.participants-print', [
                'ctx' => TenantContext::load(),
                'participants' => $sorted,
                'filter' => $filter,
                'q' => $q,
                'psort' => $psort,
                'locationDisplay' => $locationDisplay,
                'tableAssignments' => $tableAssignments,
                'staffJudge' => $staffJudge,
                'judgeEntries' => $judgeEntries,
            ]);
        }

        return view('admin.participants', [
            'ctx' => TenantContext::load(),
            'viewerLevel' => (int) ($request->user()->userLevel ?? 2),
            'participants' => $participants,
            'entryCounts' => $entryCounts,
            'entryNumbers' => $entryNumbers,
            'judgingNumbers' => $judgingNumbers,
            'filter' => $filter,
            'q' => $q,
            'locationDisplay' => $locationDisplay,
            'tableAssignments' => $tableAssignments,
            'staffJudge' => $staffJudge,
            'judgeEntries' => $judgeEntries,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function edit(Request $request, int $uid): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $participant = DB::table('brewer')->where('uid', $uid)->first();
        if ($participant === null) {
            return redirect('/backoffice/participants?msg=not-found');
        }

        // Account row for the security-question/password section.
        $user = DB::table('users')->where('id', $uid)->first();

        return view('admin.participants_edit', [
            'ctx' => TenantContext::load(),
            'participant' => $participant,
            'user' => $user,
        ]);
    }

    public function update(Request $request, int $uid): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'brewerFirstName' => ['required', 'string', 'max:255'],
            'brewerLastName' => ['required', 'string', 'max:255'],
            'brewerEmail' => ['required', 'email', 'max:255'],
            'brewerPhone1' => ['nullable', 'string', 'max:255'],
            'brewerAddress' => ['nullable', 'string', 'max:255'],
            'brewerCity' => ['nullable', 'string', 'max:255'],
            'brewerState' => ['nullable', 'string', 'max:255'],
            'brewerZip' => ['nullable', 'string', 'max:255'],
            'brewerClubs' => ['nullable', 'string', 'max:255'],
            'brewerBreweryName' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var array<string, string|null> $update */
        $update = array_combine(
            array_keys($data),
            array_map(self::blankToNull(...), array_values($data)),
        );
        DB::table('brewer')->where('uid', $uid)->update($update);

        // Account security section (brewer_form_0.pub.php:154-187 +
        // process_brewer.inc.php:709-732: changeSecurity=Y updates the
        // security Q/A; process_users.inc.php change_user_password resets
        // the password). Both are admin-only.
        $userUpdates = [];
        if ($request->input('changeSecurity') === 'Y') {
            $question = $request->validate(['userQuestion' => ['required', 'string']])['userQuestion'];
            $userUpdates['userQuestion'] = $question;
            if ($request->filled('userQuestionAnswer')) {
                $userUpdates['userQuestionAnswer'] = app('hash')->make((string) $request->input('userQuestionAnswer'));
            }
        }
        $newPassword = (string) $request->input('password', '');
        if ($newPassword !== '') {
            $userUpdates['password'] = app('hash')->make($newPassword);
            $userUpdates['userCreated'] = now()->format('Y-m-d H:i:s');
        }
        if ($userUpdates !== []) {
            DB::table('users')->where('id', $uid)->update($userUpdates);
        }

        return redirect('/backoffice/participants?msg=updated');
    }

    public function destroy(Request $request, int $uid): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Legacy guard: you cannot delete yourself.
        if ($uid === (int) Auth::id()) {
            return redirect('/backoffice/participants?msg=self');
        }

        DB::transaction(function () use ($uid): void {
            // Entries + their scores/BOS rows (legacy deletes them one by
            // one in id order — a set-wise delete is equivalent).
            $entryIds = DB::table('brewing')->where('brewBrewerID', $uid)->pluck('id');
            if ($entryIds !== []) {
                DB::table('judging_scores')->whereIn('eid', $entryIds)->delete();
                DB::table('judging_scores_bos')->whereIn('eid', $entryIds)->delete();
            }
            DB::table('brewing')->where('brewBrewerID', $uid)->delete();

            // Judging assignments and staff roles.
            DB::table('judging_assignments')->where('bid', $uid)->delete();
            DB::table('staff')->where('uid', $uid)->delete();

            // Account + profile last.
            DB::table('brewer')->where('uid', $uid)->delete();
            DB::table('users')->where('id', $uid)->delete();
        });

        return redirect('/backoffice/participants?msg=deleted');
    }

    /** Legacy blank_to_null: empty strings are stored NULL. */
    private static function blankToNull(string $v): ?string
    {
        return $v === '' ? null : $v;
    }
}
