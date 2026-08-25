<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrewController;
use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
            $query->where('brewing.brewPaid', '!=', 1);
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
            'brewer.brewerClubs',
        ]);

        return view('admin.entries', [
            'ctx' => $ctx,
            'entries' => $entries,
            'view' => $view,
            'filter' => $filter,
            'bid' => $bid,
        ]);
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
        // values. BrewController::styleFlags() is deliberately NOT used
        // here: it re-pads its input, so an already-canonical '01-C'
        // would be looked up as '001-C' and never match (pin 4).
        [$cat, $sub] = explode('-', $data['brewStyle'], 2);
        // Pin 1: subcategory cannot contain '-' (legacy explode semantics
        // would silently truncate; the port rejects instead).
        if ($cat === '' || $sub === '' || str_contains($sub, '-')) {
            return back()->withErrors(['brewStyle' => 'Style code must be <category>-<subcategory>.']);
        }
        $sort = self::categorySort($cat);
        $set = $ctx->prefsStr('prefsStyleSet');
        $version = $set === 'BJCP2025' && mb_substr($sort, 0, 1) === 'C'
            ? 'BJCP2025'
            : $set;
        $styleRow = DB::table('styles')
            ->where('brewStyleGroup', $sort)
            ->where('brewStyleNum', $sub)
            ->where(function ($q) use ($version): void {
                $q->where('brewStyleVersion', $version)->orWhere('brewStyleOwn', 'custom');
            })
            ->first();
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
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

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
