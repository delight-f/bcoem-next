<?php

declare(strict_types=1);

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Support\Entries\UserDocs;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competition close-out archive (spec §7 P5.6; ledger/archive-purge.md).
 * Legacy: admin/archive.admin.php + includes/process/process_archive.inc.php.
 *
 * HIGHEST DATA-LOSS-RISK MODULE. Archive = for each table either RENAME
 * TABLE t → t_<suffix> + CREATE TABLE t LIKE t_<suffix> (history preserved
 * in the sibling, live table empty + structurally identical + AUTO_INCREMENT
 * restarted), or — for kept flags — CREATE TABLE t_<suffix> LIKE t +
 * INSERT SELECT (copy), leaving live data in place. See store() for the
 * per-flag disposition, mirroring ledger pins 1–6.
 */
final class ArchiveController extends Controller
{
    /** Base rename+recreate set from process_archive.inc.php:28. */
    private const RENAME_TABLES = [
        'brewing',
        'judging_assignments',
        'judging_flights',
        'judging_scores',
        'judging_scores_bos',
        'judging_tables',
        'staff',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.archive', [
            'ctx' => TenantContext::load(),
            'archives' => DB::table('archive')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Server-enforced confirmation: the mutation only runs when the
        // request came through the warning UI (hidden confirm=yes field).
        if ($request->input('confirm') !== 'yes') {
            return redirect('/admin/archive')->with('error', 'Archive not run — open the confirmation panel and confirm first.');
        }

        $data = $request->validate([
            'archiveSuffix' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9]+$/'],
        ]);
        $suffix = (string) $data['archiveSuffix'];

        if (DB::table('archive')->where('archiveSuffix', $suffix)->exists()) {
            return redirect('/admin/archive')->with('error', "An archive with suffix {$suffix} already exists.");
        }

        $keepParticipants = $request->boolean('keepParticipants');
        $keepSpecialBest = $request->boolean('keepSpecialBest');
        $keepSponsors = $request->boolean('keepSponsors');
        $keepDropoff = $request->boolean('keepDropoff');
        $keepLocations = $request->boolean('keepLocations');
        $keepStyleTypes = $request->boolean('keepStyleTypes');
        $keepEvaluations = $request->boolean('keepEvaluations');

        $errors = [];

        // Pin 6: scoresheets under user_docs are moved into user_docs/<suffix>/,
        // then whatever remains at the top level is recursively deleted —
        // IRRECOVERABLE file deletion (legacy rmove + rdelete).
        self::archiveUserDocs($suffix);

        // Pin 6: clear BJCP contest id.
        try {
            DB::table('contest_info')->where('id', 1)->update(['contestID' => null]);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // When participants are NOT kept the performing admin's row must be
        // re-inserted after users/brewer are renamed away; capture it now.
        $adminUser = [];
        $adminBrewer = [];
        if (! $keepParticipants) {
            $adminUser = (array) DB::table('users')->where('id', $request->user()->id)->first();
            $adminBrewer = (array) DB::table('brewer')->where('uid', $request->user()->id)->first();
        }

        // Copy-path first (kept flags): history copied to <t>_<suffix>,
        // live table left alone (pin 2 keep branch, pin 3, pin 4 keep branch).
        if ($keepParticipants) {
            foreach (['users', 'brewer'] as $table) {
                $errors = [...$errors, ...self::copyTo($table, $suffix)];
            }
        }

        if ($keepSpecialBest) {
            // info: copy; data: rename+recreate (legacy :183-213).
            $errors = [...$errors, ...self::copyTo('special_best_info', $suffix)];
            $errors = [...$errors, ...self::renameRecreate('special_best_data', $suffix)];
        }

        if ($keepStyleTypes) {
            $errors = [...$errors, ...self::copyTo('style_types', $suffix)];
        }

        if ($keepEvaluations && self::tableExists('evaluation')) {
            // Keep-evaluations: copy only, live table untouched (pin 4).
            $errors = [...$errors, ...self::copyTo('evaluation', $suffix)];
        }

        // Rename+recreate path (pin 1). evaluation joins the rename set only
        // when it exists and is not kept (pin 4); never truncated separately.
        $tables = self::RENAME_TABLES;
        if (! $keepEvaluations && self::tableExists('evaluation')) {
            $tables[] = 'evaluation';
        }
        if (! $keepParticipants) {
            $tables[] = 'users';
            $tables[] = 'brewer';
        }
        if (! $keepSpecialBest) {
            $tables[] = 'special_best_info';
            $tables[] = 'special_best_data';
        }
        if (! $keepSponsors) {
            $tables[] = 'sponsors';
        }

        foreach ($tables as $table) {
            $errors = [...$errors, ...self::renameRecreate($table, $suffix)];
        }

        // Truncate-only list (no archive copy): drop_off, sponsors,
        // judging_locations per keep flags (:140-143).
        $truncates = [];
        if (! $keepDropoff) {
            $truncates[] = 'drop_off';
        }
        if (! $keepSponsors) {
            $truncates[] = 'sponsors';
        }
        if (! $keepLocations) {
            $truncates[] = 'judging_locations';
        }
        foreach ($truncates as $table) {
            try {
                DB::statement('TRUNCATE TABLE `'.$table.'`');
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (! $keepStyleTypes) {
            // Pin 5: copy ALL style_types to the archive, then delete custom
            // rows (id >= 16) from live; stock types untouched.
            $errors = [...$errors, ...self::copyTo('style_types', $suffix)];
            try {
                DB::table('style_types')->where('id', '>=', 16)->delete();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (! $keepParticipants && $adminUser !== []) {
            // Re-insert the performing admin verbatim (original ids) so the
            // authenticated session survives the purge.
            // DIVERGENCE: legacy force-rewrites the admin as id=1 and performs
            // a session re-login dance; the standalone port has no $_SESSION
            // snapshot and keeps the real id instead.
            try {
                DB::table('users')->insert($this->filterColumns('users', $adminUser));
                if ($adminBrewer !== []) {
                    $row = $adminBrewer;
                    // Same overrides legacy applies on re-insert (:351-359).
                    $row['brewerJudgeNotes'] = null;
                    $row['brewerDiscount'] = null;
                    $row['brewerProAm'] = '0';
                    $row['brewerDropOff'] = '999';
                    $row['brewerJudgeWaiver'] = 'Y';
                    $row['brewerBreweryInfo'] = null;
                    DB::table('brewer')->insert($this->filterColumns('brewer', $row));
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Clear judging availability/discounts for whoever remains (both
        // legacy branches do this, :390-427).
        try {
            DB::table('brewer')->update([
                'brewerJudge' => 'N',
                'brewerSteward' => 'N',
                'brewerJudgeLocation' => null,
                'brewerStewardLocation' => null,
                'brewerDropOff' => '999',
                'brewerDiscount' => null,
            ]);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // Register the archive (legacy :369-381) from the tenant pref rows.
        $prefs = TenantContext::load()->prefs;
        try {
            DB::table('archive')->insert([
                'archiveSuffix' => $suffix,
                'archiveProEdition' => self::blankToNull((string) ($prefs['prefsProEdition'] ?? '')),
                'archiveStyleSet' => self::blankToNull((string) ($prefs['prefsStyleSet'] ?? '')),
                'archiveScoresheet' => self::blankToNull((string) ($prefs['prefsDisplaySpecial'] ?? '')),
                'archiveWinnerMethod' => self::blankToNull((string) ($prefs['prefsWinnerMethod'] ?? '')),
                'archiveDisplayWinners' => self::blankToNull((string) ($prefs['prefsDisplayWinners'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($errors !== []) {
            return redirect('/admin/archive')->with('error', 'Archive completed with errors: '.implode(' | ', $errors));
        }

        return redirect('/admin/archive')->with('status', "Archive {$suffix} created.");
    }

    /**
     * RENAME TABLE t → t_<suffix>; CREATE TABLE t LIKE t_<suffix>.
     * Live table ends empty, structurally identical, AUTO_INCREMENT reset.
     *
     * @return list<string>
     */
    private static function renameRecreate(string $table, string $suffix): array
    {
        $errors = [];
        foreach ([
            'RENAME TABLE `'.$table.'` TO `'.$table.'_'.$suffix.'`',
            'CREATE TABLE `'.$table.'` LIKE `'.$table.'_'.$suffix.'`',
        ] as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * CREATE TABLE t_<suffix> LIKE t; INSERT INTO t_<suffix> SELECT * FROM t.
     *
     * @return list<string>
     */
    private static function copyTo(string $table, string $suffix): array
    {
        $errors = [];
        foreach ([
            'CREATE TABLE `'.$table.'_'.$suffix.'` LIKE `'.$table.'`',
            'INSERT INTO `'.$table.'_'.$suffix.'` SELECT * FROM `'.$table.'`',
        ] as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Legacy rmove(USER_DOCS, USER_DOCS.<suffix>) + rdelete(USER_DOCS):
     * move every top-level entry into the suffix folder, then recursively
     * delete anything that remains at the top level. IRREVERSIBLE.
     */
    private static function archiveUserDocs(string $suffix): void
    {
        $root = UserDocs::root();
        if (! is_dir($root)) {
            return;
        }

        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        if ($entries === []) {
            return;
        }

        $archiveDir = $root.DIRECTORY_SEPARATOR.$suffix;
        if (! is_dir($archiveDir)) {
            mkdir($archiveDir, 0775, true);
        }

        foreach ($entries as $name) {
            if ($name === $suffix) {
                continue;
            }
            @rename($root.DIRECTORY_SEPARATOR.$name, $archiveDir.DIRECTORY_SEPARATOR.$name);
        }

        // Anything still at top level (failed moves) is deleted, matching
        // the unconditional legacy rdelete().
        foreach (array_diff(scandir($root) ?: [], ['.', '..', $suffix]) as $leftover) {
            $path = $root.DIRECTORY_SEPARATOR.$leftover;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * Narrow a captured row to the columns that actually exist (guards
     * schema drift between the read and the re-insert).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $row): array
    {
        $columns = Schema::getColumnListing($table);

        return array_intersect_key($row, array_fill_keys($columns, true));
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
