<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Results\Place;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\WindowState;
use App\Support\Tenant\Windows;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Awards presentation (legacy awards.php, PARITY-001). reveal.js deck:
 * title, sponsors, judges/stewards/staff rolls, stats, per-table or
 * per-category winner slides (prefsWinnerMethod 0/1), BOS + special-best
 * slides, thank-you.
 *
 * Access gate (legacy :28-37): public once judging is past, entry +
 * registration + judge windows closed, prefsDisplayWinners=Y and the
 * winner delay passed; admins (userLevel <= 1) always.
 *
 * ?view= white|black|blue selects the reveal theme (legacy
 * $reveal_theme map, default white); ?go= orders table slides:
 * table-numbers | table-name-only | table-entry-count-asc | -desc.
 */
final class AwardsController extends Controller
{
    private const THEMES = [
        'default' => 'white',
        'white' => 'white',
        'black' => 'black',
        'blue' => 'moon',
    ];

    private const SORTS = ['table-numbers', 'table-name-only', 'table-entry-count-asc', 'table-entry-count-desc'];

    public function show(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        $judgingPast = $windows->futureJudgingSessions === 0;
        $displayToPublic = $judgingPast
            && $windows->entry === WindowState::After
            && $windows->registration === WindowState::After
            && $windows->judge === WindowState::After
            && $ctx->prefsStr('prefsDisplayWinners') === 'Y'
            && $now > (int) ($ctx->prefsStr('prefsWinnerDelay') ?: 0);

        $user = $request->user();
        $displayToAdmin = $user !== null && (int) $user->userLevel <= 1;

        if (! $displayToPublic && ! $displayToAdmin) {
            return redirect('/?msg=7');
        }

        $view = self::THEMES[(string) $request->query('view', 'default')] ?? 'white';
        $go = (string) $request->query('go', 'table-numbers');
        if (! in_array($go, self::SORTS, true)) {
            $go = 'table-numbers';
        }

        $repo = ResultsRepository::current();

        return view('awards.show', [
            'ctx' => $ctx,
            'theme' => $view,
            'contestName' => $ctx->contestStr('contestName'),
            'contestLogo' => $ctx->contestStr('contestLogo'),
            'sponsors' => $this->sponsors($ctx),
            'staffRolls' => $this->staffRolls(),
            'stats' => $this->stats(),
            'tableSlides' => $this->tableSlides($ctx, $repo, $go),
            'bosSlides' => $this->bosSlides($ctx, $repo),
            'proEdition' => (int) $ctx->prefsStr('prefsProEdition') === 1,
            'styleSet' => (string) $ctx->prefsStr('prefsStyleSet'),
            'today' => now()->format((string) ($ctx->prefsStr('prefsDateFormat') ?: 'F j, Y')),
        ]);
    }

    /** @return list<object{id:string,url:string}> */
    private function sponsors(TenantContext $ctx): array
    {
        if ($ctx->prefsStr('prefsSponsorLogos') !== 'Y') {
            return [];
        }

        return DB::table('sponsors')
            ->where('sponsorEnable', 1)
            ->whereNotNull('sponsorImage')
            ->where('sponsorImage', '!=', '')
            ->orderBy('sponsorName')
            ->get()
            ->map(fn ($s): object => (object) ['id' => (string) $s->id, 'url' => url('/storage/user_images/'.(string) $s->sponsorImage)])
            ->all();
    }

    /** Legacy staff roll lists (:59-110). @return array{judges:string,bos:string,stewards:string,staff:string,organizers:string} */
    private function staffRolls(): array
    {
        $rows = DB::table('staff')
            ->join('brewer', 'brewer.uid', '=', 'staff.uid')
            ->orderBy('brewer.brewerLastName')->orderBy('brewer.brewerFirstName')
            ->get(['brewer.brewerFirstName', 'brewer.brewerLastName', 'staff.staff_judge', 'staff.staff_steward', 'staff.staff_judge_bos', 'staff.staff_staff', 'staff.staff_organizer']);

        $name = static fn ($r): string => $r->brewerFirstName.' '.$r->brewerLastName;
        $rolls = ['judges' => '', 'bos' => '', 'stewards' => '', 'staff' => '', 'organizers' => ''];

        foreach ($rows as $r) {
            if ((int) $r->staff_judge === 1) {
                $rolls['judges'] .= $name($r).', ';
            }
            if ((int) $r->staff_judge_bos === 1) {
                $rolls['bos'] .= $name($r).', ';
            }
            if ((int) $r->staff_steward === 1) {
                $rolls['stewards'] .= $name($r).', ';
            }
            if ((int) $r->staff_staff === 1) {
                $rolls['staff'] .= $name($r).', ';
            }
            if ((int) $r->staff_organizer === 1) {
                $rolls['organizers'] .= $name($r).', ';
            }
        }

        return array_map(static fn (string $r): string => rtrim($r, ', '), $rolls);
    }

    /** Legacy stats slide (:1285-1315). @return array{entries:int,entrants:int,judges:int,stewards:int,staff:int,placing:int} */
    private function stats(): array
    {
        $placers = DB::table('judging_scores')
            ->whereIn('scorePlace', ['1', '2', '3', '4', '5'])
            ->distinct()
            ->count('eid');

        return [
            'entries' => (int) DB::table('brewing')->where('brewPaid', 1)->where('brewReceived', 1)->count(),
            'entrants' => (int) DB::table('brewing')->where('brewPaid', 1)->where('brewReceived', 1)->distinct()->count('brewBrewerID'),
            'judges' => (int) DB::table('judging_assignments')->where('assignment', 'J')->distinct()->count('bid'),
            'stewards' => (int) DB::table('judging_assignments')->where('assignment', 'S')->distinct()->count('bid'),
            'staff' => (int) DB::table('staff')->where(fn ($q) => $q->where('staff_staff', 1)->orWhere('staff_organizer', 1))->count(),
            'placing' => (int) $placers,
        ];
    }

    /**
     * Per-table winner slides (prefsWinnerMethod=0 path, legacy :125-240).
     * Ordering per $go: table number / name only / entry count asc/desc.
     *
     * @return list<object{title:string,count:int,judges:string,winners:list<object{place:string,name:string,club:string,entry:string,style:string,fh:int}>}>
     */
    private function tableSlides(TenantContext $ctx, ResultsRepository $repo, string $go): array
    {
        if ((int) $ctx->prefsStr('prefsWinnerMethod') !== 0) {
            return [];
        }

        $tables = DB::table('judging_tables')->orderBy('tableNumber')->get(['id', 'tableNumber', 'tableName']);

        $slides = [];
        foreach ($tables as $table) {
            $tableScores = DB::table('judging_scores as js')
                ->join('brewing as b', 'js.eid', '=', 'b.id')
                ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
                ->join('judging_flights as jf', 'jf.flightEntryID', '=', 'b.id')
                ->where('jf.flightTable', $table->id)
                ->whereIn('js.scorePlace', ['1', '2', '3', '4', '5'])
                ->orderBy('js.scorePlace')
                ->get([
                    'js.scorePlace', 'b.brewName', 'b.brewStyle',
                    'b.brewCategory', 'b.brewSubCategory',
                    'br.brewerFirstName', 'br.brewerLastName', 'br.brewerClubs',
                ]);

            $entryCount = DB::table('judging_flights')->where('flightTable', $table->id)->distinct()->count('flightEntryID');

            $winners = $tableScores->map(function ($r): object {
                $style = (string) $r->brewCategory.(string) $r->brewSubCategory;

                return (object) [
                    'place' => Place::label((string) $r->scorePlace),
                    'fh' => $this->placeHierarchy((string) $r->scorePlace),
                    'name' => trim($r->brewerFirstName.' '.$r->brewerLastName),
                    'club' => (string) ($r->brewerClubs === 'Other' ? '' : $r->brewerClubs),
                    'entry' => (string) $r->brewName,
                    'style' => $style.': '.(string) $r->brewStyle,
                ];
            })->values();

            $slides[] = (object) [
                'title' => 'Table '.$table->tableNumber.': '.$table->tableName,
                'titleLong' => (string) $table->tableName,
                'count' => (int) $entryCount,
                'winners' => $winners->all(),
            ];
        }

        // Ordering per $go (legacy array_multisort :236-237).
        usort($slides, function (object $a, object $b) use ($go): int {
            if ($go === 'table-entry-count-asc' || $go === 'table-entry-count-desc') {
                $cmp = $a->count <=> $b->count;
                if ($cmp !== 0) {
                    return $go === 'table-entry-count-desc' ? -$cmp : $cmp;
                }

                return strcmp((string) $a->titleLong, (string) $b->titleLong);
            }
            if ($go === 'table-name-only') {
                return strcmp((string) $a->titleLong, (string) $b->titleLong);
            }

            return strcmp($a->title, $b->title);
        });

        return $slides;
    }

    /**
     * BOS per style-type + special-best slides (legacy :440-560).
     *
     * @return list<object{title:string,subtitle:string,winners:list<object{place:string,name:string,club:string,entry:string,style:string,fh:int}>}>
     */
    private function bosSlides(TenantContext $ctx, ResultsRepository $repo): array
    {
        $slides = [];

        $styleTypes = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get(['id', 'styleTypeName']);
        foreach ($styleTypes as $type) {
            $rows = DB::table('judging_scores_bos as jsb')
                ->join('brewing as b', 'jsb.eid', '=', 'b.id')
                ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
                ->where('jsb.scoreType', $type->id)
                ->whereIn('jsb.scorePlace', ['1', '2', '3', '4', '5'])
                ->orderBy('jsb.scorePlace')
                ->get([
                    'jsb.scorePlace', 'b.brewName', 'b.brewStyle',
                    'b.brewCategory', 'b.brewSubCategory',
                    'br.brewerFirstName', 'br.brewerLastName', 'br.brewerClubs',
                ]);

            if ($rows->isEmpty()) {
                continue;
            }

            $slides[] = (object) [
                'title' => 'Best of Show',
                'subtitle' => (string) $type->styleTypeName,
                'winners' => $rows->map(fn ($r): object => (object) [
                    'place' => Place::label((string) $r->scorePlace),
                    'fh' => $this->placeHierarchy((string) $r->scorePlace),
                    'name' => trim($r->brewerFirstName.' '.$r->brewerLastName),
                    'club' => (string) ($r->brewerClubs === 'Other' ? '' : $r->brewerClubs),
                    'entry' => (string) $r->brewName,
                    'style' => (string) $r->brewCategory.(string) $r->brewSubCategory.': '.(string) $r->brewStyle,
                ])->values()->all(),
            ];
        }

        // Special/custom best-of categories.
        foreach (DB::table('special_best_info')->orderBy('sbi_name')->get(['id', 'sbi_name']) as $sbi) {
            $rows = DB::table('special_best_data')
                ->join('brewing', 'special_best_data.eid', '=', 'brewing.id')
                ->join('brewer', 'brewing.brewBrewerID', '=', 'brewer.uid')
                ->where('special_best_data.sid', $sbi->id)
                ->orderBy('special_best_data.sbd_place')
                ->get([
                    'special_best_data.sbd_place', 'brewing.brewName',
                    'brewer.brewerFirstName', 'brewer.brewerLastName',
                ]);

            if ($rows->isEmpty()) {
                continue;
            }

            $slides[] = (object) [
                'title' => (string) $sbi->sbi_name,
                'subtitle' => '',
                'winners' => $rows->map(fn ($r): object => (object) [
                    'place' => (string) $r->sbd_place !== '' ? Place::label($r->sbd_place) : '',
                    'fh' => 1,
                    'name' => trim($r->brewerFirstName.' '.$r->brewerLastName),
                    'club' => '',
                    'entry' => (string) $r->brewName,
                    'style' => '',
                ])->values()->all(),
            ];
        }

        return $slides;
    }

    /** Legacy place_heirarchy: fragment reveal order (1st→1, 2nd→2 …). */
    private function placeHierarchy(string $place): int
    {
        return match ($place) {
            '1' => 1, '2' => 2, '3' => 3, '4' => 4, default => 5,
        };
    }
}
