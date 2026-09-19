<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Entries\EntryLimits;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Styles admin (spec §7 P5.4) — port of admin/styles.admin.php +
 * process_styles.inc.php, under the semantics pinned by
 * ledger/styles.md.
 *
 * Set-version lookup predicate (ledger pins #5-#7, styles.db.php): the
 * active set's rows are selected as
 *   AABC2025 / BJCP2025 (dual-version):
 *     ((version = SET AND brewStyleType='2') OR
 *      (version = PREV AND brewStyleType != '2') OR brewStyleOwn='custom')
 *   everything else:
 *     (brewStyleVersion = SET OR brewStyleOwn='custom')
 * — customs extend every set and bypass all version filters (#5).
 *
 * Custom-style add/edit normalization (pin #4 decision): legacy stored the
 * posted category verbatim, so zero-padded input ('002') produced an
 * inconsistent three-zero-width sort downstream; that input was unreachable
 * through the own UI (maxlength=3 + AJAX guard) but is trivially reachable
 * for a crafted POST. The port normalizes on input via
 * EntryLimits::normalizeCategory() (numeric ≤9 padded to '0X', alpha kept
 * whole, leading zeros collapsed) — documented divergence from legacy
 * storage so UI-created rows always match entry-path sort codes.
 *
 * The accepted-styles bulk update rewrites prefsSelectedStyles JSON exactly
 * like process_styles.inc.php action=update (checked rows only, keyed by id,
 * carrying group/num/version/type); editing a style name cascades into the
 * brewing table's brewStyle copies (legacy edit branch).
 */
final class StylesAdminController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        return view('admin.styles', [
            'ctx' => $ctx,
            'styles' => self::setQuery((string) $ctx->prefsStr('prefsStyleSet'))
                ->orderBy('brewStyleType')->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get(),
            'styleTypes' => DB::table('style_types')->orderBy('id')->get(),
            'selected' => self::selectedStyles(),
            'editing' => null,
        ]);
    }

    /** Accepted-styles / at-limit checklist submit (action=update). */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'array']]);

        $selected = [];
        foreach ((array) ($data['id'] ?? []) as $id) {
            $id = (int) $id;
            // Checkbox posts value "Y" (legacy) — presence means checked.
            if ($request->input('brewStyleActive'.$id) === null) {
                continue;
            }

            $row = DB::table('styles')->where('id', $id)->first();
            if ($row !== null) {
                $selected[$row->id] = [
                    'id' => (int) $row->id,
                    'brewStyle' => (string) $row->brewStyle,
                    'brewStyleGroup' => (string) $row->brewStyleGroup,
                    'brewStyleNum' => (string) $row->brewStyleNum,
                    'brewStyleVersion' => (string) $row->brewStyleVersion,
                    'brewStyleType' => $row->brewStyleType,
                ];
            }
        }

        DB::table('preferences')->where('id', 1)->update([
            'prefsSelectedStyles' => json_encode($selected, JSON_THROW_ON_ERROR),
        ]);

        return redirect('/admin/styles?msg=2');
    }

    public function create(Request $request): View|RedirectResponse
    {
        return $this->formView($request, null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = self::validatedRow($request);

        DB::table('styles')->insert($data);

        if (($data['brewStyleActive'] ?? '') === 'Y') {
            $this->appendToSelectedStyles((int) DB::getPdo()->lastInsertId(), $data);
        }

        return redirect('/admin/styles?msg=9');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        return $this->formView($request, $id);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $current = DB::table('styles')->where('id', $id)->first();
        // Shipped system styles are read-only (the list hides Edit/Delete).
        if ($current === null || $current->brewStyleOwn === 'bcoe') {
            return redirect('/admin/styles');
        }

        $data = self::validatedRow($request);
        DB::table('styles')->where('id', $id)->update($data);

        // Legacy cascade: renaming a style updates brewing.brewStyle copies,
        // matched by the OLD name the form posts (the pinned parity test keys
        // on the posted value, so this is legacy semantics, not a bug).
        $oldName = (string) $request->input('brewStyleOld', '');
        if ($oldName !== '' && (string) $data['brewStyle'] !== $oldName) {
            DB::table('brewing')->where('brewStyle', $oldName)->update([
                'brewStyle' => (string) $data['brewStyle'],
            ]);
        }

        if (($data['brewStyleActive'] ?? '') === 'Y') {
            $this->appendToSelectedStyles($id, $data);
        }

        return redirect('/admin/styles?msg=9');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $row = DB::table('styles')->where('id', $id)->first();
        if ($row === null || $row->brewStyleOwn === 'bcoe') {
            return redirect('/admin/styles');
        }

        DB::table('styles')->delete($id);

        return redirect('/admin/styles?msg=9');
    }

    /**
     * Ledger pins #5/#6/#7 — the exact set lookup predicate from
     * styles.db.php. Lives in StyleSets::activeQuery() (single source of
     * truth); repointed here so every caller shares one definition.
     */
    private static function setQuery(string $set): Builder
    {
        return StyleSets::activeQuery($set);
    }

    /** @return array<int, mixed> decoded prefsSelectedStyles map */
    private static function selectedStyles(): array
    {
        $raw = TenantContext::load()->prefsStr('prefsSelectedStyles');
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Legacy appends the new/edited row to prefsSelectedStyles when its
     * checkbox is on (process_styles.inc.php tail).
     *
     * @param  array<string, mixed>  $data
     */
    private function appendToSelectedStyles(int $id, array $data): void
    {
        $selected = self::selectedStyles();
        $selected[$id] = [
            'id' => $id,
            'brewStyle' => (string) $data['brewStyle'],
            'brewStyleGroup' => (string) $data['brewStyleGroup'],
            'brewStyleNum' => (string) $data['brewStyleNum'],
            'brewStyleVersion' => (string) $data['brewStyleVersion'],
            'brewStyleType' => (int) $data['brewStyleType'],
        ];

        DB::table('preferences')->where('id', 1)->update([
            'prefsSelectedStyles' => json_encode($selected, JSON_THROW_ON_ERROR),
        ]);
    }

    private function formView(Request $request, ?int $id): View|RedirectResponse
    {
        $editing = $id === null ? null : DB::table('styles')->where('id', $id)->first();

        // Shipped system styles cannot be opened for edit (the list hides it).
        if ($id !== null && ($editing === null || $editing->brewStyleOwn === 'bcoe')) {
            return redirect('/admin/styles');
        }

        return view('admin.styles', [
            'ctx' => TenantContext::load(),
            'styles' => self::setQuery((string) TenantContext::load()->prefsStr('prefsStyleSet'))
                ->orderBy('brewStyleType')->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get(),
            'styleTypes' => DB::table('style_types')->orderBy('id')->get(),
            'selected' => self::selectedStyles(),
            'editing' => $editing,
        ]);
    }

    /**
     * Exact process_styles.inc.php add/edit column map, with the pin-4
     * input normalization on brewStyleGroup (see class docblock).
     *
     * @return array<string, mixed>
     */
    private static function validatedRow(Request $request): array
    {
        $data = $request->validate([
            'brewStyle' => ['required', 'string', 'max:100'],
            'brewStyleGroup' => ['required', 'string', 'max:3'],
            'brewStyleNum' => ['required', 'string', 'max:2'],
            'brewStyleType' => ['required', 'integer'],
            'brewStyleReqSpec' => ['nullable', 'in:0,1'],
            'brewStyleStrength' => ['nullable', 'in:0,1'],
            'brewStyleCarb' => ['nullable', 'in:0,1'],
            'brewStyleSweet' => ['nullable', 'in:0,1'],
            'brewStyleEntry' => ['nullable', 'string'],
            'brewStyleInfo' => ['nullable', 'string'],
            'brewStyleOG' => ['nullable', 'string', 'max:10'],
            'brewStyleOGMax' => ['nullable', 'string', 'max:10'],
            'brewStyleFG' => ['nullable', 'string', 'max:10'],
            'brewStyleFGMax' => ['nullable', 'string', 'max:10'],
            'brewStyleABV' => ['nullable', 'string', 'max:10'],
            'brewStyleABVMax' => ['nullable', 'string', 'max:10'],
            'brewStyleIBU' => ['nullable', 'string', 'max:10'],
            'brewStyleIBUMax' => ['nullable', 'string', 'max:10'],
            'brewStyleSRM' => ['nullable', 'string', 'max:10'],
            'brewStyleSRMMax' => ['nullable', 'string', 'max:10'],
            'brewStyleLink' => ['nullable', 'string', 'max:255'],
            'brewStyleActive' => ['nullable', 'in:Y,N'],
        ]);

        // Type 2 ("other" cider/perry rows) can never require strength.
        $strength = $request->input('brewStyleType') == 2
            ? '0'
            : (string) ($data['brewStyleStrength'] ?? '0');

        return [
            'brewStyle' => (string) $data['brewStyle'],
            'brewStyleOG' => self::blankToNull((string) ($data['brewStyleOG'] ?? '')),
            'brewStyleOGMax' => self::blankToNull((string) ($data['brewStyleOGMax'] ?? '')),
            'brewStyleFG' => self::blankToNull((string) ($data['brewStyleFG'] ?? '')),
            'brewStyleFGMax' => self::blankToNull((string) ($data['brewStyleFGMax'] ?? '')),
            'brewStyleABV' => self::blankToNull((string) ($data['brewStyleABV'] ?? '')),
            'brewStyleABVMax' => self::blankToNull((string) ($data['brewStyleABVMax'] ?? '')),
            'brewStyleIBU' => self::blankToNull((string) ($data['brewStyleIBU'] ?? '')),
            'brewStyleIBUMax' => self::blankToNull((string) ($data['brewStyleIBUMax'] ?? '')),
            'brewStyleSRM' => self::blankToNull((string) ($data['brewStyleSRM'] ?? '')),
            'brewStyleSRMMax' => self::blankToNull((string) ($data['brewStyleSRMMax'] ?? '')),
            'brewStyleType' => (int) $data['brewStyleType'],
            'brewStyleInfo' => self::blankToNull(trim((string) ($data['brewStyleInfo'] ?? ''))),
            'brewStyleLink' => self::blankToNull((string) ($data['brewStyleLink'] ?? '')),
            'brewStyleGroup' => EntryLimits::normalizeCategory((string) $data['brewStyleGroup']),
            'brewStyleNum' => (string) $data['brewStyleNum'],
            'brewStyleActive' => (string) ($data['brewStyleActive'] ?? 'Y'),
            'brewStyleOwn' => 'custom',
            'brewStyleVersion' => (string) TenantContext::load()->prefsStr('prefsStyleSet'),
            'brewStyleReqSpec' => self::blankToNull((string) ($data['brewStyleReqSpec'] ?? '0')),
            'brewStyleStrength' => self::blankToNull($strength),
            'brewStyleCarb' => self::blankToNull((string) ($data['brewStyleCarb'] ?? '0')),
            'brewStyleSweet' => self::blankToNull((string) ($data['brewStyleSweet'] ?? '0')),
            'brewStyleEntry' => self::blankToNull(trim((string) ($data['brewStyleEntry'] ?? ''))),
        ];
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
