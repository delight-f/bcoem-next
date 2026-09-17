<?php

declare(strict_types=1);

namespace App\Support\Eval;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Evaluation → official-score consensus engine (spec P4.6, ticket P4.6).
 *
 * Port of eval/ajax/import_scores.ajax.php, pinned by
 * tests/Characterization/EvalConsensusTest.php:
 *
 *  - import requires ≥2 evaluations per entry (one ⇒ "singles" bucket);
 *  - official score = MAX of the judges' evalFinalScore (highest wins,
 *    NOT an average — a deliberate legacy quirk, preserved);
 *  - place = max numeric place > 0, else no place (NULL sentinel; legacy
 *    used [] / "" before a final zero-scrub pass, reproduced below);
 *  - mini-BOS = max flag across judges (>0 ⇒ 1);
 *  - existing judging_scores rows NEVER get their entered score columns
 *    (eid/bid/scoreTable/scoreEntry) touched — only scorePlace /
 *    scoreType / scoreMiniBOS may change, and a stored non-empty place is
 *    never overwritten by a different one (discrepancy report instead);
 *  - therefore repeated runs converge: idempotent re-import.
 *
 * Reads the shared tenant DB through Laravel like every runtime surface
 * (the typed BCOEM repositories are MysqliDb-backed and used from the
 * legacy-connection tests); same tables, no second connection.
 *
 * Supersession note: legacy additionally refused to insert (and flagged)
 * when judges' final scores disagreed (the `flagged` bucket at
 * ajax/import_scores.ajax.php:264-271). tests/Characterization/
 * EvalConsensusTest::provideConsensusGroups expects MAX-wins once two or
 * more evaluations exist ([38,42] ⇒ imported 42), so there is no unanimity
 * gate here.
 */
final class EvalConsensus
{
    /**
     * Official consensus score, or NULL when fewer than two judges
     * evaluated the entry (never imported).
     *
     * @param  list<float|int|string|null>  $judgeScores
     */
    public static function consensusScore(array $judgeScores): ?float
    {
        if (count($judgeScores) < 2) {
            return null;
        }

        return (float) max($judgeScores);
    }

    /**
     * Max numeric place > 0, else NULL ("no place" sentinel).
     *
     * @param  list<float|int|string|null>  $places
     */
    public static function consensusPlace(array $places): ?int
    {
        $numeric = array_values(array_filter($places, is_numeric(...)));

        if ($numeric === []) {
            return null;
        }

        $max = max($numeric);

        return $max > 0 ? (int) $max : null;
    }

    /**
     * Mini-BOS as max flag across judges (>0 anywhere ⇒ 1).
     *
     * @param  list<float|int|string|null>  $flags
     */
    public static function consensusMiniBos(array $flags): int
    {
        $numeric = array_filter($flags, is_numeric(...));

        return ($numeric !== [] && max($numeric) > 0) ? 1 : 0;
    }

    /**
     * Run the full import over every evaluated entry.
     *
     * @return array{status: int, imported: int, updated: int, singles: list<int>, discrepancies: list<string>}
     *                                                                                                          status: 0 no evaluations, 1 ok, 2 write failure (legacy codes).
     */
    public function import(): array
    {
        $report = ['status' => 1, 'imported' => 0, 'updated' => 0, 'singles' => [], 'discrepancies' => []];

        /** @var array<int, list<stdClass>> $byEid evaluation rows grouped per entry */
        $byEid = [];
        foreach (DB::table('evaluation')->get() as $row) {
            if ($row->eid !== null) {
                $byEid[$row->eid][] = $row;
            }
        }

        if ($byEid === []) {
            return ['status' => 0, 'imported' => 0, 'updated' => 0, 'singles' => [], 'discrepancies' => []];
        }

        /** @var array<int, stdClass> $scored first judging_scores row per eid */
        $scored = [];
        foreach (DB::table('judging_scores')->get() as $score) {
            if ($score->eid !== null && ! isset($scored[$score->eid])) {
                $scored[$score->eid] = $score;
            }
        }

        foreach ($byEid as $eid => $rows) {
            if (isset($scored[$eid])) {
                $this->updateScored($eid, $scored[$eid], $rows, $report);
            } elseif (count($rows) === 1) {
                $report['singles'][] = $eid;
            } else {
                $this->insertConsensus((int) $eid, $rows, $report);
            }
        }

        // Legacy final scrub: any stray zero places become NULL.
        DB::table('judging_scores')->where('scorePlace', 0)->update(['scorePlace' => null]);

        return $report;
    }

    /**
     * Existing score row: sync place/type/mini-BOS only — the entered
     * score columns are never overwritten.
     *
     * @param  list<stdClass>  $rows
     * @param  array{status: int, imported: int, updated: int, singles: list<int>, discrepancies: list<string>}  $report
     */
    private function updateScored(int $eid, stdClass $stored, array $rows, array &$report): void
    {
        $place = self::consensusPlace(array_map(static fn ($r) => $r->evalPlace, $rows));
        $miniBos = self::consensusMiniBos(array_map(static fn ($r) => $r->evalMiniBOS, $rows));

        $data = [];

        $storedPlace = ($stored->scorePlace === null || (float) $stored->scorePlace === 0.0)
            ? null
            : (int) $stored->scorePlace;

        if ($place !== $storedPlace) {
            if ($storedPlace === null) {
                $data['scorePlace'] = $place;
            } else {
                // A judged place already recorded: report, don't overwrite.
                $report['discrepancies'][] = $eid.'|'.$storedPlace.'|'.($place ?? 'none');
            }
        }

        if ((int) ($stored->scoreMiniBOS ?? 0) !== $miniBos) {
            $data['scoreMiniBOS'] = $miniBos;
        }

        $type = $this->consensusType($rows);
        if (($type ?? 0) !== (int) ($stored->scoreType ?? 0)) {
            $data['scoreType'] = $type;
        }

        if ($data !== []) {
            $updated = DB::table('judging_scores')->where('id', $stored->id)->update($data);
            if ($updated) {
                $report['updated']++;
            } else {
                $report['status'] = 2;
            }
        }
    }

    /**
     * Unscored entry with ≥2 evaluations: insert the consensus row.
     *
     * @param  list<stdClass>  $rows
     * @param  array{status: int, imported: int, updated: int, singles: list<int>, discrepancies: list<string>}  $report
     */
    private function insertConsensus(int $eid, array $rows, array &$report): void
    {
        $final = self::consensusScore(array_map(static fn ($r) => $r->evalFinalScore, $rows));

        if ($final === null) {
            return; // unreachable: caller guarantees ≥2 rows
        }

        $uids = array_filter(array_map(static fn ($r) => $r->uid, $rows));
        $tables = array_filter(array_map(static fn ($r) => $r->evalTable, $rows));

        $inserted = DB::table('judging_scores')->insertGetId([
            'eid' => $eid,
            'bid' => $uids === [] ? null : max($uids),
            'scoreTable' => $tables === [] ? null : max($tables),
            'scoreEntry' => $final,
            'scoreMiniBOS' => self::consensusMiniBos(array_map(static fn ($r) => $r->evalMiniBOS, $rows)),
            'scorePlace' => self::consensusPlace(array_map(static fn ($r) => $r->evalPlace, $rows)),
            'scoreType' => $this->consensusType($rows),
        ]);

        if ($inserted === false || $inserted === 0) {
            $report['status'] = 2;

            return;
        }

        $report['imported']++;
    }

    /**
     * Max brewStyleType across the evaluations' style references (unknown
     * styles skipped; NULL when none resolve — legacy wrote ""/0 there).
     *
     * @param  list<stdClass>  $rows
     */
    private function consensusType(array $rows): ?int
    {
        $types = [];
        foreach ($rows as $row) {
            if ($row->evalStyle === null) {
                continue;
            }
            $type = DB::table('styles')->where('id', $row->evalStyle)->value('brewStyleType');
            if (is_numeric($type)) {
                $types[] = (int) $type;
            }
        }

        return $types === [] ? null : max($types);
    }
}
