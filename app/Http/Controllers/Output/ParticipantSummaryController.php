<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\OutputFormat;
use App\Support\Outputs\StreamPdf;
use App\Support\Results\Place;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-brewer entry summary — one PDF page per participant with received
 * entries (legacy output/participant_summary.output.php +
 * includes/db/output_participant_summary.db.php).
 *
 * Brewers come from the brewer⋈users join on brewerEmail = user_name
 * ordered by last name; only brewers with ≥1 received entry get a page.
 * Place display follows prefsWinnerMethod (0 table / 1 category /
 * 2 subcategory), mirroring winner_check() (common.lib.php:2751) for the
 * methods it actually renders — methods 3-5 fall through empty there too.
 */
final class ParticipantSummaryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();
        $baSet = $ctx->prefsStr('prefsStyleSet') === 'BA';

        $scores = DB::table('judging_scores')->get([
            'eid', 'scoreEntry', 'scorePlace', 'scoreMiniBOS', 'scoreTable',
        ])->keyBy('eid');

        $bosPlaces = DB::table('judging_scores_bos')->get(['eid', 'scorePlace'])->keyBy('eid');
        $tableNames = DB::table('judging_tables')->pluck('tableName', 'id');
        $receivedCount = DB::table('brewing')->where('brewReceived', '1')->count();

        $organizer = DB::table('staff as s')
            ->join('brewer as br', 's.uid', '=', 'br.uid')
            ->where('s.staff_organizer', '1')
            ->orderBy('br.brewerLastName')
            ->first(['br.brewerFirstName', 'br.brewerLastName']);

        $participants = [];

        $brewers = DB::table('brewer as br')
            ->join('users as u', 'br.brewerEmail', '=', 'u.user_name')
            ->orderBy('br.brewerLastName')
            ->get(['br.uid', 'br.brewerFirstName', 'br.brewerLastName']);

        foreach ($brewers as $brewer) {
            $entries = DB::table('brewing')
                ->where('brewBrewerID', $brewer->uid)
                ->where('brewReceived', '1')
                ->orderBy('brewJudgingNumber')
                ->get([
                    'id', 'brewName', 'brewCategory', 'brewCategorySort',
                    'brewSubCategory', 'brewStyle', 'brewJudgingNumber',
                ]);

            if ($entries->isEmpty()) {
                continue;
            }

            $rows = [];

            foreach ($entries as $entry) {
                $score = $scores->get($entry->id);
                $bos = $bosPlaces->get($entry->id);

                // Legacy echoes sort+sub prefix unless the BA set is active.
                $style = $baSet
                    ? (string) $entry->brewStyle
                    : trim((string) $entry->brewCategorySort.(string) $entry->brewSubCategory.': '.(string) $entry->brewStyle);

                $rows[] = [
                    'entry' => sprintf('%06s', $entry->id),
                    'judging' => OutputFormat::judgingNumber($entry->brewJudgingNumber),
                    'name' => (string) $entry->brewName,
                    'style' => $style,
                    'score' => $score === null || $score->scoreEntry === null ? '' : (string) $score->scoreEntry,
                    'miniBos' => $score !== null && (int) $score->scoreMiniBOS === 1,
                    'bosPlace' => $bos !== null && $bos->scorePlace !== null
                        ? OutputFormat::ordinal((string) $bos->scorePlace)
                        : '',
                    'place' => $this->placeLabel($score, $entry, $ctx, $tableNames),
                ];
            }

            $participants[] = [
                'name' => $brewer->brewerFirstName.' '.$brewer->brewerLastName,
                'rows' => $rows,
            ];
        }

        return StreamPdf::response('outputs.participant-summary', [
            'contestName' => $ctx->contestStr('contestName') ?? '',
            'receivedCount' => $receivedCount,
            'organizer' => $organizer,
            'participants' => $participants,
        ], 'participant-summary.pdf');
    }

    /**
     * winner_check() display string: gate is legacy's string compare
     * `$scorePlace >= "1"` (digits and 'HM' pass, ''/null/zero do not);
     * label per prefsWinnerMethod.
     *
     * @param  Collection<int, string>  $tableNames
     */
    private function placeLabel(?\stdClass $score, \stdClass $entry, TenantContext $ctx, object $tableNames): string
    {
        if ($score === null) {
            return '';
        }

        $place = (string) ($score->scorePlace ?? '');

        if ($place === '' || ! ($place >= '1')) {
            return '';
        }

        $label = Place::label($place);

        return match ((int) $ctx->prefsStr('prefsWinnerMethod')) {
            0 => $label.': '.(string) ($tableNames->get((int) $score->scoreTable) ?? ''),
            1 => $label.': '.$this->categoryName((string) $entry->brewCategorySort, $ctx),
            2 => $label.': '.$this->subCategoryName($entry, $ctx),
            default => '',
        };
    }

    /** style_convert(sort, 1): category name of the group's first row under the active-set version predicates. Divergence from legacy: reads the row's brewStyleCategory column instead of the static includes/styles.inc.php tables. */
    private function categoryName(string $group, TenantContext $ctx): string
    {
        $row = self::styleRow($group, null, $ctx);

        return $row === null ? '' : (string) $row->brewStyleCategory;
    }

    private function subCategoryName(\stdClass $entry, TenantContext $ctx): string
    {
        $row = self::styleRow((string) $entry->brewCategorySort, (string) $entry->brewSubCategory, $ctx);

        return $row === null
            ? ''
            : $row->brewStyle.' ('.$entry->brewCategory.$entry->brewSubCategory.')';
    }

    /**
     * Active-set lookup (styles ledger #5/#6/#7): version predicate by set
     * with customs always matching.
     */
    public static function styleRow(string $group, ?string $num, TenantContext $ctx): ?\stdClass
    {
        $set = $ctx->prefsStr('prefsStyleSet');
        $query = DB::table('styles')->where('brewStyleGroup', $group)->where(function ($q) use ($set): void {
            if ($set === 'BJCP2025') {
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'BJCP2021')->where('brewStyleType', '!=', '2');
                });
            } elseif ($set === 'AABC2025') {
                $q->where(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2025')->where('brewStyleType', '2');
                })->orWhere(function ($qq): void {
                    $qq->where('brewStyleVersion', 'AABC2022')->where('brewStyleType', '!=', '2');
                });
            } else {
                $q->where('brewStyleVersion', $set);
            }
            $q->orWhere('brewStyleOwn', 'custom');
        });

        if ($num !== null) {
            $query->where('brewStyleNum', $num);
        }

        return $query->first();
    }
}
