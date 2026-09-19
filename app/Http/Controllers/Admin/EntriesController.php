<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrewController;
use App\Http\Controllers\Controller;
use App\Support\Entries\EntryPurge;
use App\Support\Payments\FeeCalculator;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entries back office — spec §7 P5.5, ticket P5.5.
 * Legacy: admin/entries.admin.php + process_brewing.inc.php (update
 * branch, admin capabilities) + process_delete.inc.php go=entries.
 *
 * Style re-assignment normalization (ledger/styles.md pins 1–3):
 *   #2 brewCategory = ltrim(cat,'0'); brewCategorySort = '0X' for single-
 *      digit numeric categories;
 *   #3 alpha categories are never padded;
 *   #4 DIVERGENCE (deliberate): legacy feeds the raw posted category into
 *      its padding branch, so zero-padded input ('002-B') stores an
 *      inconsistent '0002'-width brewCategorySort (pinned by
 *      StylesCategoryNormalizationTest as unreachable via legacy's own
 *      UI). The port normalizes first — ltrim before pad — so '002-B'
 *      stores cat '2', sort '02'. The admin dropdown can only emit
 *      canonical values anyway; keeping storage canonical beats mirroring
 *      a dead-end quirk.
 *   #1 subcategory cannot contain '-' — enforced by validating the posted
 *      code against the active styles list (same values the dropdown
 *      offers).
 */
final class EntriesController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $rawView = $request->query('view');
        $view = is_string($rawView) ? $rawView : 'default';
        $rawFilter = $request->query('filter');
        $filter = is_string($rawFilter) ? $rawFilter : 'default';
        $rawBid = $request->query('bid');
        $bid = is_string($rawBid) ? $rawBid : 'default';

        $query = DB::table('brewing')
            ->leftJoin('brewer', 'brewer.uid', '=', 'brewing.brewBrewerID')
            ->orderBy('brewing.brewCategorySort')
            ->orderBy('brewing.brewSubCategory')
            ->orderBy('brewing.id');

        // view=paid / unpaid; filter=<brewCategorySort>; bid=<participant uid>
        if ($view === 'paid') {
            $query->where('brewing.brewPaid', '1');
        } elseif ($view === 'unpaid') {
            $query->where(fn ($w) => $w->where('brewing.brewPaid', '!=', 1)->orWhereNull('brewing.brewPaid'));
        }
        if ($filter !== 'default' && $filter !== '') {
            $query->where('brewing.brewCategorySort', $filter);
        }
        if ($bid !== 'default' && $bid !== '') {
            $query->where('brewing.brewBrewerID', (int) $bid);
        }

        $entries = $query->get([
            'brewing.id', 'brewName', 'brewStyle', 'brewCategory',
            'brewCategorySort', 'brewSubCategory', 'brewJudgingNumber',
            'brewBrewerID', 'brewBrewerFirstName', 'brewBrewerLastName',
            'brewPaid', 'brewReceived', 'brewConfirmed', 'brewUpdated',
            'brewAdminNotes', 'brewStaffNotes', 'brewBoxNum',
            'brewPossAllergens', 'brewCoBrewer', 'brewInfo',
            'brewInfoOptional', 'brewMead1', 'brewMead2', 'brewMead3',
            'brewABV', 'brewer.brewerEmail AS brewBrewerEmail',
            'brewer.brewerClubs',
        ]);

        $base = DB::table('brewing');
        if ($view === 'paid') {
            $base->where('brewPaid', '1');
        } elseif ($view === 'unpaid') {
            $base->where(fn ($w) => $w->where('brewPaid', '!=', 1)->orWhereNull('brewPaid'));
        }
        if ($filter !== 'default' && $filter !== '') {
            $base->where('brewCategorySort', $filter);
        }
        if ($bid !== 'default' && $bid !== '') {
            $base->where('brewBrewerID', (int) $bid);
        }

        // Entry Status modal (entries.admin.php:936): counts scoped to the
        // current view. Fee totals go through FeeCalculator (the single money
        // model) — a flat count × base fee ignored the volume discount, the
        // member rate and the fee cap that every other surface applies.
        $clone = fn () => clone $base;
        $feeParams = FeeCalculator::params($ctx);
        $specialUids = DB::table('brewer')->where('brewerDiscount', 'Y')->pluck('uid')->all();
        $feesFor = function (Builder $q) use ($feeParams, $specialUids): float {
            $total = 0.0;
            $rows = (clone $q)->selectRaw('brewBrewerID, COUNT(*) AS n')->groupBy('brewBrewerID')->get();
            foreach ($rows as $row) {
                $total += (float) FeeCalculator::total(
                    (int) $row->n,
                    in_array((int) $row->brewBrewerID, $specialUids, true),
                    $feeParams,
                );
            }

            return $total;
        };
        // NULL brewPaid is unpaid too (legacy/imported rows default to NULL).
        $unpaid = static fn (Builder $q): Builder => $q->where(fn ($w) => $w->where('brewPaid', '!=', 1)->orWhereNull('brewPaid'));

        $entryStatus = [
            'confirmed' => (clone $base)->where('brewConfirmed', '1')->count(),
            'unconfirmed' => (clone $base)->where('brewConfirmed', '!=', 1)->count(),
            'received' => (clone $base)->where('brewReceived', '1')->count(),
        ];
        $entryStatus['paidCount'] = DB::table('brewing')->where('brewPaid', '1')->count();
        $entryStatus['totalCount'] = DB::table('brewing')->count();
        if ($view === 'default' && $filter === 'default' && $bid === 'default') {
            $entryStatus['paidConfirmed'] = DB::table('brewing')->where('brewConfirmed', '1')->where('brewPaid', '1')->count();
            $entryStatus['unpaidConfirmed'] = $unpaid(DB::table('brewing')->where('brewConfirmed', '1'))->count();
            $entryStatus['totalFees'] = $feesFor(DB::table('brewing')->where('brewConfirmed', '1'));
        }
        if ($view !== 'unpaid') {
            $entryStatus['totalFeesPaid'] = $feesFor($clone()->where('brewConfirmed', '1')->where('brewPaid', '1'));
        }
        if ($view !== 'paid') {
            $entryStatus['totalFeesUnpaid'] = $feesFor($unpaid($clone()->where('brewConfirmed', '1')));
        }

        // Copy/paste email modals (entries.admin.php:853-933): unique
        // brewer emails behind entries / paid entries / unpaid entries.
        $emailsFor = function (?string $paid): string {
            $q = DB::table('brewing as b')
                ->join('brewer as br', 'br.uid', '=', 'b.brewBrewerID');
            if ($paid === '1') {
                $q->where('b.brewPaid', '1');
            } elseif ($paid === '0') {
                $q->where(fn ($w) => $w->where('b.brewPaid', '!=', 1)->orWhereNull('b.brewPaid'));
            }

            return $q->select('br.brewerEmail', 'br.brewerLastName')
                ->distinct()
                ->orderBy('br.brewerLastName')
                ->get()
                ->map(static fn ($row): string => (string) $row->brewerEmail)
                ->filter()->unique()->values()
                ->implode(', ');
        };
        $emailLists = [
            'all' => $emailsFor(null),
            'paid' => $emailsFor('1'),
            'unpaid' => $emailsFor('0'),
        ];

        // Participant jump select (participant_choose, admin.lib.php:511).
        $participants = DB::table('brewer')
            ->orderBy('brewerLastName')
            ->get(['uid', 'brewerFirstName', 'brewerLastName']);

        return view('admin.entries', [
            'ctx' => $ctx,
            'entries' => $entries,
            'view' => $view,
            'filter' => $filter,
            'bid' => $bid,
            'entryStatus' => $entryStatus,
            'emailLists' => $emailLists,
            'participants' => $participants,
        ]);
    }

    /**
     * Legacy process_brewing.inc.php:991 mark-everything actions (the
     * "Admin Actions" dropdown). Legacy updates the WHOLE brewing table
     * regardless of the current filter — mirrored. Redirects carry the
     * legacy msg codes (headers.inc.php 642-656): 20 paid, 34 unpaid,
     * 21 received, 35 not-received, 22 confirmed.
     */
    public function markAll(Request $request): RedirectResponse
    {
        $actions = [
            'paid' => ['column' => 'brewPaid', 'value' => '1', 'msg' => 20],
            'unpaid' => ['column' => 'brewPaid', 'value' => '0', 'msg' => 34],
            'received' => ['column' => 'brewReceived', 'value' => '1', 'msg' => 21],
            'not-received' => ['column' => 'brewReceived', 'value' => '0', 'msg' => 35],
            'confirmed' => ['column' => 'brewConfirmed', 'value' => '1', 'msg' => 22],
        ];

        $action = (string) $request->input('action');
        if (! isset($actions[$action])) {
            return redirect('/backoffice/entries');
        }

        DB::table('brewing')->update([$actions[$action]['column'] => $actions[$action]['value']]);

        return redirect('/backoffice/entries?msg='.$actions[$action]['msg']);
    }

    /**
     * Legacy data_cleanup.inc.php purge flows behind the entries Admin
     * Actions menu (entries.admin.php:830-831): go=unconfirmed and
     * go=unpaid. Mirrors purge_entries() (common.lib.php:392) exactly —
     * no date threshold (interval 0):
     *
     *  - unpaid:       (brewPaid='0' OR brewPaid IS NULL) rows
     *  - unconfirmed:  brewConfirmed='0' rows, PLUS entries whose style
     *                  requires special-ingredient info
     *                  (styles.brewStyleReqSpec=1, matched on
     *                  brewCategorySort/brewSubCategory against the active
     *                  style-set version) but whose brewInfo is empty.
     *
     * Level-0 only, like the data_cleanup.inc.php guard; lands back on the
     * entries list (legacy also carries no success banner).
     */
    public function purge(Request $request): RedirectResponse
    {
        $actor = $request->user();
        if ($actor === null || (int) $actor->userLevel !== 0) {
            return redirect('/?msg=99');
        }

        $go = (string) $request->input('go');
        $ids = match ($go) {
            'unpaid' => DB::table('brewing')
                ->where(fn ($q): Builder => $q->where('brewPaid', '0')->orWhereNull('brewPaid'))
                ->pluck('id'),
            'unconfirmed' => DB::table('brewing')->where('brewConfirmed', '0')
                ->pluck('id')
                ->merge($this->missingSpecialInfoIds()),
            // Preferences "Purge stale entries": unconfirmed/special rows
            // untouched for 24h (legacy purge_entries(type, 1)).
            'stale' => collect(EntryPurge::stale(TenantContext::load(), time())),
            default => null,
        };

        if ($ids === null) {
            return redirect('/backoffice/entries');
        }

        if ($ids->isNotEmpty()) {
            DB::table('brewing')->whereIn('id', $ids->all())->delete();
        }

        return redirect('/backoffice/entries');
    }

    /**
     * Legacy purge_entries('special'): brewing rows whose style demands
     * special-ingredient info but have no brewInfo. The predicate lives in
     * EntryPurge so the Preferences "purge stale" action shares it.
     *
     * @return Collection<int, int>
     */
    private function missingSpecialInfoIds(): Collection
    {
        return collect(EntryPurge::missingSpecialInfo(TenantContext::load()));
    }

    /**
     * Legacy entries.admin.php: the whole table is one form; inline
     * cells (judging number, paid/received checkboxes, box number, admin
     * and staff notes) posted per-row as `brewJudgingNumber{id}` etc.
     * Legacy saved each cell via AJAX save_column; the port persists the
     * whole form in one pass. Redirect carries the legacy updated msg.
     */
    public function updateForm(Request $request): RedirectResponse
    {
        $ids = $request->input('ids');
        if (! is_array($ids)) {
            return redirect('/backoffice/entries?msg=updated');
        }

        // Validate every posted cell before writing, as the companion
        // update() path already does: the inline judging number is the
        // barcode/QR key, so it must be a six-character value, and the box
        // and notes fields match the edit form's limits.
        $rules = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $rules['brewJudgingNumber'.$id] = ['nullable', 'string', 'max:6'];
            $rules['brewBoxNum'.$id] = ['nullable', 'string', 'max:10'];
            $rules['brewAdminNotes'.$id] = ['nullable', 'string', 'max:255'];
            $rules['brewStaffNotes'.$id] = ['nullable', 'string', 'max:255'];
        }
        $request->validate($rules);

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $judging = (string) $request->input('brewJudgingNumber'.$id, '');
            $box = (string) $request->input('brewBoxNum'.$id, '');
            $admin = (string) $request->input('brewAdminNotes'.$id, '');
            $staff = (string) $request->input('brewStaffNotes'.$id, '');

            DB::table('brewing')->where('id', $id)->update([
                'brewJudgingNumber' => $judging !== '' ? strtolower($judging) : null,
                'brewPaid' => $request->boolean('brewPaid'.$id) ? 1 : 0,
                'brewReceived' => $request->boolean('brewReceived'.$id) ? 1 : 0,
                'brewBoxNum' => self::blankToNull($box),
                'brewAdminNotes' => self::blankToNull($admin),
                'brewStaffNotes' => self::blankToNull($staff),
                'brewUpdated' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        return redirect('/backoffice/entries?msg=updated');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $entry = DB::table('brewing')->where('id', $id)->first();
        if ($entry === null) {
            return redirect('/backoffice/entries?msg=not-found');
        }

        return view('admin.entries_edit', [
            'ctx' => $ctx,
            'entry' => $entry,
            'styles' => BrewController::activeStyles($ctx),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $ctx = TenantContext::load();

        $data = $request->validate([
            'brewName' => ['required', 'string', 'max:255'],
            // Pin 1: the posted value must be one of the active set's
            // `<cat>-<sub>` codes, so the subcategory cannot contain '-'.
            'brewStyle' => ['required', 'string'],
            'brewPaid' => ['nullable', 'boolean'],
            'brewReceived' => ['nullable', 'boolean'],
            'brewAdminNotes' => ['nullable', 'string', 'max:255'],
            'brewStaffNotes' => ['nullable', 'string', 'max:255'],
            'brewBoxNum' => ['nullable', 'string', 'max:10'],
        ]);

        // Canonicalize the posted code FIRST (pin-4 choice: '001-C' →
        // '01-C'), then resolve the styles row against the CANONICAL
        // values. Group/sub are handed to StyleSets::findStyle() in their
        // canonical form — it does NO re-padding, so an already-canonical
        // '01-C' resolves directly (pin 4). findStyle() walks the active
        // set's versions newest-first, so AABC beer styles (AABC2022) and
        // BJCP cider (BJCP2025) both resolve without a group heuristic.
        [$cat, $sub] = explode('-', $data['brewStyle'], 2);
        // Pin 1: subcategory cannot contain '-' (legacy explode semantics
        // would silently truncate; the port rejects instead).
        if ($cat === '' || $sub === '' || str_contains($sub, '-')) {
            return back()->withErrors(['brewStyle' => 'Style code must be <category>-<subcategory>.']);
        }
        $sort = self::categorySort($cat);
        $styleRow = StyleSets::findStyle($ctx->prefsStr('prefsStyleSet') ?? '', $sort, $sub);
        if ($styleRow === null) {
            return back()->withErrors(['brewStyle' => 'Choose a style from the active style set.']);
        }

        DB::table('brewing')->where('id', $id)->update([
            'brewName' => self::blankToNull($data['brewName']),
            'brewStyle' => $styleRow->brewStyle,
            // Pins 2–4: ltrim then pad (see class docblock for pin 4).
            'brewCategory' => self::category($cat),
            'brewCategorySort' => self::categorySort($cat),
            'brewSubCategory' => $sub,
            'brewStyleType' => $styleRow->brewStyleType ?? null,
            'brewPaid' => (int) ($request->boolean('brewPaid')),
            'brewReceived' => (int) ($request->boolean('brewReceived')),
            'brewAdminNotes' => self::blankToNull((string) ($data['brewAdminNotes'] ?? '')),
            'brewStaffNotes' => self::blankToNull((string) ($data['brewStaffNotes'] ?? '')),
            'brewBoxNum' => self::blankToNull((string) ($data['brewBoxNum'] ?? '')),
            'brewUpdated' => now()->format('Y-m-d H:i:s'),
        ]);

        return redirect('/backoffice/entries?msg=updated');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            // Legacy quirk mirrored: process_delete go=entries removes ONE
            // judging_scores row per entry (getOne → first match), not all.
            $scoreId = DB::table('judging_scores')->where('eid', $id)->value('id');
            if ($scoreId !== null) {
                DB::table('judging_scores')->where('id', $scoreId)->delete();
            }
            DB::table('brewing')->where('id', $id)->delete();
        });

        return redirect('/backoffice/entries?msg=deleted');
    }

    /** Entry cell: legacy sprintf("%06s", id). */
    public static function entryNumber(int|string $id): string
    {
        return str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Updated cell: tenant-formatted brewUpdated epoch (stored as a
     * datetime string in this port).
     */
    public static function updated(TenantContext $ctx, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return DateFmt::date(strtotime($value) ?: null, $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat')) ?? '';
    }

    /**
     * brewCategory storage: ltrim zeros (pin 2); alpha untouched (pin 3);
     * zero-padded input normalized rather than preserved (pin-4 choice).
     * All-zero input trims to '' and is stored NULL, like legacy
     * blank_to_null(ltrim(cat,'0')).
     */
    private static function category(string $cat): ?string
    {
        return self::blankToNull(ltrim($cat, '0'));
    }

    /**
     * brewCategorySort storage: numeric single digits pad to '0X'; alpha
     * categories never padded (pins 2–3). Input is canonicalized via
     * ltrim BEFORE padding so posted '002' stores '02' (pin 4).
     */
    private static function categorySort(string $cat): string
    {
        $cat = ctype_digit($cat) ? ltrim($cat, '0') : $cat;

        return ctype_digit($cat) && (int) $cat < 10 ? '0'.$cat : $cat;
    }

    private static function blankToNull(string $v): ?string
    {
        return $v === '' ? null : $v;
    }
}
