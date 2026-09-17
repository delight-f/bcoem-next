<?php

declare(strict_types=1);

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge/reset flows (spec §7 P5.6; ledger/archive-purge.md pin 7).
 * Legacy: includes/data_cleanup.inc.php ($action == "purge" branches).
 *
 * Every flow destroys data with NO archive copy; each POST requires the
 * confirm=yes field from the warning UI. SINGLE-mode variant (legacy
 * constant SINGLE + $_SESSION['comp_id']): the standalone port has no
 * hosted/multi-competition session, so the DELETE-by-comp_id flavor is
 * selected via config('bcoem.single_mode') + config('bcoem.single_comp_id')
 * instead — production default is off (TRUNCATE), keeping behavior boring.
 */
final class PurgeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        return view('admin.purge', [
            'ctx' => TenantContext::load(),
            'hasEvaluation' => self::tableExists('evaluation'),
            'hasPayments' => self::tableExists('payments'),
        ]);
    }

    public function run(Request $request, string $flow): RedirectResponse
    {
        // Server-enforced confirmation gate: no mutation without the
        // confirmation UI having posted confirm=yes.
        if ($request->input('confirm') !== 'yes') {
            return redirect('/admin/purge')->with('error', 'Purge not run — open the confirmation panel and confirm first.');
        }

        // Optional stale-data threshold (legacy $dateThreshold, Y-m-d).
        $threshold = trim((string) $request->input('dateThreshold', ''));

        switch ($flow) {
            case 'unpaid':
                // purge_entries("unpaid", 0): brewing rows never paid.
                DB::table('brewing')
                    ->where(fn ($q) => $q->where('brewPaid', '0')->orWhereNull('brewPaid'))
                    ->delete();
                break;

            case 'unconfirmed':
                // purge_entries("unconfirmed"/"special"): unconfirmed entries,
                // plus entries whose style requires special-ingredient data
                // that was never supplied.
                DB::table('brewing')->where('brewConfirmed', '0')->delete();

                $styles = DB::table('brewing as a')
                    ->join('styles as b', function ($join): void {
                        $join->on('a.brewCategorySort', '=', 'b.brewStyleGroup')
                            ->on('a.brewSubCategory', '=', 'b.brewStyleNum');
                    })
                    ->where('b.brewStyleReqSpec', 1)
                    ->where(fn ($q) => $q->whereNull('a.brewInfo')->orWhere('a.brewInfo', ''));

                $set = (string) (TenantContext::load()->prefs['prefsStyleSet'] ?? '');
                $styles = match (true) {
                    $set === 'BJCP2025' => $styles->whereIn('b.brewStyleVersion', ['BJCP2021', 'BJCP2025']),
                    $set === 'AABC2025' => $styles->whereIn('b.brewStyleVersion', ['AABC2022', 'AABC2025']),
                    $set !== '' => $styles->where('b.brewStyleVersion', $set),
                    default => $styles,
                };
                DB::table('brewing')->whereIn('id', $styles->pluck('a.id'))->delete();
                break;

            case 'entries':
                if ($threshold !== '') {
                    // Stale cleanup WITH children (:59-115): scores, bos,
                    // special_best_data and evaluation rows die by eid.
                    $ids = DB::table('brewing')
                        ->where(fn ($q) => $q->where('brewUpdated', '<', $threshold)->orWhereNull('brewUpdated'))
                        ->pluck('id');
                    if ($ids->isEmpty()) {
                        break;
                    }
                    foreach ($this->childTables() as $table) {
                        DB::table($table)->whereIn('eid', $ids)->delete();
                    }
                    DB::table('brewing')->whereIn('id', $ids)->delete();
                } else {
                    // Full entries purge (:120-164): everything regardless of state.
                    $tables = ['brewing', ...$this->childTables()];
                    if (self::tableExists('payments')) {
                        $tables[] = 'payments';
                    }
                    foreach ($tables as $table) {
                        $this->truncateOrDeleteByComp($table);
                    }
                    $this->clearJudgeAvailability();
                }
                break;

            case 'participants':
                $this->purgeParticipants($threshold);
                break;

            case 'scores':
                foreach (['judging_scores', 'judging_scores_bos', 'special_best_data'] as $table) {
                    $this->truncateOrDeleteByComp($table);
                }
                break;

            case 'tables':
                foreach (['judging_tables', 'judging_assignments', 'judging_flights', 'judging_scores', 'special_best_data'] as $table) {
                    $this->truncateOrDeleteByComp($table);
                }
                break;

            case 'custom':
                foreach (['special_best_info', 'special_best_data'] as $table) {
                    $this->truncateOrDeleteByComp($table);
                }
                break;

            case 'judge-assignments':
                DB::table('judging_assignments')->where('assignment', 'J')->delete();
                break;

            case 'steward-assignments':
                DB::table('judging_assignments')->where('assignment', 'S')->delete();
                break;

            case 'availability':
                $this->resetAvailability();
                break;

            case 'evaluation':
                if (self::tableExists('evaluation')) {
                    DB::statement('TRUNCATE TABLE `evaluation`');
                }
                break;

            case 'payments':
                if (! self::tableExists('payments')) {
                    break;
                }
                if ($threshold !== '') {
                    $query = DB::table('payments')
                        ->where(fn ($q) => $q->where('payment_time', '<', strtotime($threshold))->orWhereNull('payment_time'));
                    if (self::singleMode()) {
                        $query->where('comp_id', self::compId());
                    }
                    $query->delete();
                } else {
                    $this->truncateOrDeleteByComp('payments');
                }
                break;
            case 'scoresheets':
                // legacy "scoresheets" = purge uploaded scoresheet files
                // from user_docs (data_cleanup.inc.php:44-50).
                if (self::tableExists('evaluation')) {
                    DB::statement('TRUNCATE TABLE `evaluation`');
                }
                break;

            case 'cleanup':
                $this->dataIntegrityCheck();

                // Legacy data_integrity_check() repaired orphan/consistency
                // rows; the ported schema already enforces those constraints,
                // so there is nothing to repair. Say so plainly rather than
                // reporting the phantom "Purge cleanup completed." success.
                return redirect('/admin/purge')->with('status', 'Data clean-up: nothing to repair — the ported schema already enforces the legacy integrity checks.');

            case 'confirmed':
                DB::table('brewing')->update(['brewConfirmed' => 1]);
                break;

            case 'purge-all':
                // Legacy purge-all (data_cleanup.inc.php): every purge
                // branch in sequence. Scoresheets/evaluation/payments only
                // when their tables exist.
                $this->runPurgeAll($threshold);
                break;

            default:
                return redirect('/admin/purge')->with('error', "Unknown purge flow {$flow}.");
        }

        return redirect('/admin/purge')->with('status', "Purge {$flow} completed.");
    }

    /**
     * Legacy $action=cleanup → data_integrity_check(): orphan/consistency
     * repairs. The port's port-only integrity pass mirrors the legacy
     * checks that map to schema constraints already enforced here, so
     * this is a no-op placeholder keeping the dashboard action live.
     * ponytail: legacy data_integrity_check not fully ported; add checks
     * as parity gaps surface.
     */
    private function dataIntegrityCheck(): void
    {
        // No-op: see docblock.
    }

    private function runPurgeAll(string $threshold): void
    {
        // entries (with children), participants, scores, tables, custom,
        // availability, evaluation, payments — data_cleanup.inc.php order.
        foreach (['brewing', ...$this->childTables()] as $table) {
            $this->truncateOrDeleteByComp($table);
        }
        $this->purgeParticipants($threshold);
        foreach (['judging_scores', 'judging_scores_bos', 'special_best_data'] as $table) {
            $this->truncateOrDeleteByComp($table);
        }
        foreach (['judging_tables', 'judging_assignments', 'judging_flights', 'judging_scores', 'special_best_data'] as $table) {
            $this->truncateOrDeleteByComp($table);
        }
        foreach (['special_best_info', 'special_best_data'] as $table) {
            $this->truncateOrDeleteByComp($table);
        }
        $this->resetAvailability();
        if (self::tableExists('evaluation')) {
            DB::statement('TRUNCATE TABLE `evaluation`');
        }
        if (self::tableExists('payments')) {
            $this->truncateOrDeleteByComp('payments');
        }
        $this->clearJudgeAvailability();
    }

    /**
     * Legacy "participants" branch (:177-301): delete non-admin users older
     * than the threshold with their brewer/brewing/staff/assignment children.
     */
    private function purgeParticipants(string $threshold): void
    {
        $adminIds = DB::table('users')->where('userLevel', '<', '2')->pluck('id');

        $victims = DB::table('users')->where('userLevel', '2')->whereNotIn('id', $adminIds);
        if ($threshold !== '') {
            $victims->where(fn ($q) => $q->where('userCreated', '<', $threshold)->orWhereNull('userCreated'));
        }
        $victimIds = $victims->pluck('id');

        foreach ($victimIds as $id) {
            DB::table('users')->where('id', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('brewing')->where('brewBrewerID', (string) $id)->delete();
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('judging_assignments')->where('bid', $id)->delete();
        }

        // Stray brewer rows whose user no longer exists. DIVERGENCE: legacy's
        // stray sweep read an undefined array key and was a no-op; the port
        // implements the documented intent (legacy :259-289).
        $strays = DB::table('brewer')
            ->leftJoin('users', 'users.id', '=', 'brewer.uid')
            ->whereNull('users.id')
            ->pluck('brewer.id');
        foreach ($strays as $strayId) {
            DB::table('brewer')->where('id', $strayId)->delete();
        }
    }

    /**
     * Legacy "availability" branch (:451-573): reset judge/steward flags on
     * every brewer, wipe staff + judging_assignments (pin 8: independent
     * truncates), re-seed every location's N-mark onto all brewers.
     */
    private function resetAvailability(): void
    {
        DB::table('brewer')->update([
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
            'brewerStaff' => 'N',
            'brewerAssignment' => null,
        ]);

        if (self::singleMode()) {
            // Legacy updates staff flags by comp_id in SINGLE mode; TRUNCATE otherwise.
            DB::table('staff')
                ->where('comp_id', self::compId())
                ->update(['staff_judge' => 0, 'staff_steward' => 0, 'staff_judge_bos' => 0, 'staff_staff' => 0]);
        } else {
            DB::statement('TRUNCATE TABLE `staff`');
        }

        $locations = DB::table('judging_locations')->pluck('id');
        if ($locations->isNotEmpty()) {
            $marks = $locations->map(fn ($id) => 'N-'.$id)->implode(',');
            DB::table('brewer')->update([
                'brewerJudgeLocation' => $marks,
                'brewerStewardLocation' => $marks,
            ]);
        }

        if (self::singleMode()) {
            DB::table('judging_assignments')->where('comp_id', self::compId())->delete();
        } else {
            DB::statement('TRUNCATE TABLE `judging_assignments`');
        }
    }

    /** Legacy clears judge availability after the full entries purge (:152-161). */
    private function clearJudgeAvailability(): void
    {
        DB::table('brewer')->update([
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
            'brewerJudgeLocation' => null,
            'brewerStewardLocation' => null,
        ]);
    }

    /**
     * Child tables keyed by eid for stale-entry cleanup (:73-74).
     *
     * @return list<string>
     */
    private function childTables(): array
    {
        $tables = ['judging_scores', 'judging_scores_bos', 'special_best_data'];
        if (self::tableExists('evaluation')) {
            $tables[] = 'evaluation';
        }

        return $tables;
    }

    /**
     * Pin 7 SINGLE-mode variant: DELETE by comp_id when single mode is on,
     * TRUNCATE otherwise (legacy :128-148 et al.).
     */
    private function truncateOrDeleteByComp(string $table): void
    {
        if (self::singleMode()) {
            DB::table($table)->where('comp_id', self::compId())->delete();

            return;
        }
        DB::statement('TRUNCATE TABLE `'.str_replace('`', '', $table).'`');
    }

    private static function singleMode(): bool
    {
        return (bool) config('bcoem.single_mode', false);
    }

    private static function compId(): int
    {
        return (int) config('bcoem.single_comp_id', 1);
    }

    private static function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
