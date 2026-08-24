<?php

declare(strict_types=1);

namespace App\Support\Results;

use Illuminate\Support\Facades\DB;

/**
 * Read-only result queries behind the public results block and the
 * past-winners archive view.
 *
 * Semantics from the behavior ledgers:
 * - Selection filter is scorePlace IN ('1','2','3','4','5'); literal 'HM'
 *   rows are excluded everywhere, faithful to legacy (winners-display #3/#4).
 *   The HM-vs-'5' normalization question stays open in ticket 04 — reads
 *   do not silently widen.
 * - BOS eligibility was decided at WRITE time via styleTypeBOSMethod; the
 *   read side takes judging_scores_bos as given.
 * - Archive views read sibling tables named <base>_<suffix>; the suffix is
 *   sanitized to alphanumerics (legacy $filter_clean contract) and every
 *   archived table's existence is checked before use (legacy table_exists
 *   guards — a suffix may name tables that were never created).
 */
final class ResultsRepository
{
    private const PLACES = ['1', '2', '3', '4', '5'];

    private function __construct(private readonly ?string $suffix) {}

    public static function current(): self
    {
        return new self(null);
    }

    /** @param string|int $rawFilter unsanitized request input */
    public static function forArchive(string|int $rawFilter): self
    {
        return new self(preg_replace('/[^a-zA-Z0-9]+/', '', (string) $rawFilter));
    }

    /**
     * Winning entries joined to brewer, ordered category sort → subcategory
     * → place ascending. Empty when the (archived) scores table is absent.
     *
     * @return list<object>
     */
    public function winners(): array
    {
        if (! self::tableExists($this->name('judging_scores')) || ! self::tableExists($this->name('brewing'))) {
            return [];
        }

        $rows = DB::table($this->name('judging_scores').' as js')
            ->join($this->name('brewing').' as b', 'js.eid', '=', 'b.id')
            ->join($this->name('brewer').' as br', 'b.brewBrewerID', '=', 'br.id')
            ->whereIn('js.scorePlace', self::PLACES)
            ->where('b.brewReceived', 1)
            ->orderBy('b.brewCategorySort')
            ->orderBy('b.brewSubCategory')
            ->orderByRaw('CAST(js.scorePlace AS UNSIGNED)')
            ->get([
                'js.scorePlace',
                'b.id as entryId',
                'b.brewName',
                'b.brewStyle',
                'b.brewCategorySort',
                'b.brewSubCategory',
                'br.brewerFirstName',
                'br.brewerLastName',
                'br.brewerClubs',
            ]);

        /** @var list<object> */
        return array_values($rows->all());
    }

    /**
     * Best-of-show placements ordered by BOS place ascending. Empty when
     * no BOS table exists for the context (legacy gates on count > 0).
     *
     * @return list<object>
     */
    public function bos(): array
    {
        if (! self::tableExists($this->name('judging_scores_bos')) || ! self::tableExists($this->name('brewing'))) {
            return [];
        }

        $rows = DB::table($this->name('judging_scores_bos').' as jsb')
            ->join($this->name('brewing').' as b', 'jsb.eid', '=', 'b.id')
            ->join($this->name('brewer').' as br', 'b.brewBrewerID', '=', 'br.id')
            ->whereIn('jsb.scorePlace', self::PLACES)
            ->where('b.brewReceived', 1)
            ->orderByRaw('CAST(jsb.scorePlace AS UNSIGNED)')
            ->get([
                'jsb.scorePlace',
                'b.id as entryId',
                'b.brewName',
                'b.brewStyle',
                'br.brewerFirstName',
                'br.brewerLastName',
            ]);

        /** @var list<object> */
        return array_values($rows->all());
    }

    /**
     * Archive rows flagged for public winner display. Suffixes become
     * table-name fragments downstream; re-sanitize defensively even though
     * legacy sanitized at write time.
     *
     * @return list<object>
     */
    public static function archives(): array
    {
        $rows = DB::table('archive')
            ->where('archiveDisplayWinners', 'Y')
            ->whereNotNull('archiveSuffix')
            ->where('archiveSuffix', '!=', '')
            ->orderBy('archiveSuffix')
            ->get()
            ->map(function ($row) {
                $row->archiveSuffix = preg_replace('/[^a-zA-Z0-9]+/', '', (string) $row->archiveSuffix);

                return $row;
            })
            ->filter(fn ($row) => $row->archiveSuffix !== '');

        /** @var list<object> */
        return array_values($rows->values()->all());
    }

    private function name(string $base): string
    {
        return $this->suffix === null ? $base : $base.'_'.$this->suffix;
    }

    private static function tableExists(string $table): bool
    {
        return DB::select("SHOW TABLES LIKE '".str_replace("'", '', $table)."'") !== [];
    }
}
