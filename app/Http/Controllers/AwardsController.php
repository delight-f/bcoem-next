<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Awards\AwardDeckBuilder;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Awards presentation (legacy awards.php, PARITY-001). reveal.js deck:
 * title, sponsors, judges/stewards/staff rolls, stats, winner slides
 * (table/category/subcategory per prefsWinnerMethod), BOS + special-best,
 * Best Brewer / Best Club, thank-you.
 *
 * Access gate (legacy :28-37): public once judging is past, entry +
 * registration + judge windows closed, prefsDisplayWinners=Y and the
 * winner delay passed; admins (userLevel <= 1) always.
 *
 * ?view= white|black|blue selects the reveal theme (legacy
 * $reveal_theme map, default white); ?go= orders table slides:
 * table-numbers | table-name-only | table-entry-count-asc | -desc.
 *
 * Slide assembly lives in App\Support\Awards\AwardDeckBuilder (the
 * accumulation tables / CoA pools / truncations are long enough that
 * keeping them out of the controller keeps this class a gate+view-data
 * shim). Best Brewer/Club standings come from
 * App\Support\Results\BestBrewerStandings.
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

        $view = self::THEMES[$request->string('view', 'default')->toString()] ?? 'white';
        $go = $request->string('go', 'table-numbers')->toString();
        if (! in_array($go, self::SORTS, true)) {
            $go = 'table-numbers';
        }

        $builder = new AwardDeckBuilder;
        $staffRolls = $this->staffRolls();
        $proEdition = (int) $ctx->prefsStr('prefsProEdition') === 1;
        $winnerMethod = (int) ($ctx->prefsStr('prefsWinnerMethod') ?? 0);
        $bestBrewerSlides = $builder->bestBrewerSlides($ctx);

        $coaScoring = (int) ($ctx->prefsStr('prefsScoringCOA') ?? 0) === 1;
        $placePoints = array_map(
            static fn (string $k): float => (float) ($ctx->prefs[$k] ?? 0),
            ['prefsFirstPlacePts', 'prefsSecondPlacePts', 'prefsThirdPlacePts', 'prefsFourthPlacePts', 'prefsHMPts'],
        );
        $tiebreakerLabels = [
            'TBTotalPlaces' => 'The highest total number of first, second, and third places.',
            'TBTotalExtendedPlaces' => 'The highest total number of first, second, third, fourth (if applicable), and honorable mention places.',
            'TBFirstPlaces' => 'The highest number of first places.',
            'TBNumEntries' => 'The lowest number of entries.',
            'TBMinScore' => 'The highest minimum score.',
            'TBMaxScore' => 'The highest maximum score.',
            'TBAvgScore' => 'The highest average score.',
        ];
        $tiebreakers = array_values(array_filter(array_map(
            static fn (string $k): string => (string) ($ctx->prefs[$k] ?? ''),
            ['prefsTieBreakRule1', 'prefsTieBreakRule2', 'prefsTieBreakRule3', 'prefsTieBreakRule4', 'prefsTieBreakRule5', 'prefsTieBreakRule6'],
        ), static fn (string $v): bool => $v !== ''));
        $tiebreakers = array_map(
            static fn (string $v): string => $tiebreakerLabels[$v] ?? 'Unused.',
            $tiebreakers,
        );

        return view('awards.show', [
            'ctx' => $ctx,
            'theme' => $view,
            'contestName' => $ctx->contestStr('contestName'),
            'contestLogo' => $ctx->contestStr('contestLogo'),
            'sponsors' => $this->sponsors($ctx),
            'staffRolls' => $staffRolls,
            'stats' => $this->stats(),
            'winnerSlides' => $builder->winnerSlides($ctx, $go, $request->boolean('empty')),
            'bosSlides' => $builder->bosSlides($ctx),
            'specialBestSlides' => $builder->specialBestSlides($ctx),
            'bestBrewerSlides' => $bestBrewerSlides,
            'proEdition' => $proEdition,
            'styleSet' => (string) $ctx->prefsStr('prefsStyleSet'),
            'noscript' => 'For an optimal experience and so that all features and functions execute properly, please enable JavaScript to continue using this site. Otherwise, unexpected behavior will occur.',
            'coaScoring' => $coaScoring,
            'winnerMethod' => $winnerMethod,
            'placePoints' => $placePoints,
            'tiebreakers' => $tiebreakers,
            'today' => DateFmt::date(now()->getTimestamp(), $ctx->prefsStr('prefsTimeZone'), $ctx->prefsStr('prefsDateFormat'), 'long'),
        ]);
    }

    /** @return list<object{id:string,url:string}&\stdClass> */
    private function sponsors(TenantContext $ctx): array
    {
        if ($ctx->prefsStr('prefsSponsorLogos') !== 'Y') {
            return [];
        }

        return array_values(DB::table('sponsors')
            ->where('sponsorEnable', 1)
            ->whereNotNull('sponsorImage')
            ->where('sponsorImage', '!=', '')
            ->orderBy('sponsorLevel')->orderBy('sponsorName')
            ->get()
            ->filter(static fn ($s): bool => is_file(public_path('user_images/'.(string) $s->sponsorImage)))
            ->map(static fn ($s): object => (object) ['id' => (string) $s->id, 'url' => asset('user_images/'.(string) $s->sponsorImage)])
            ->values()
            ->all());
    }

    /**
     * Legacy staff roll lists (:59-110).
     *
     * @return array{judges:string,bos:string,stewards:string,staff:string,organizers:string}
     */
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

    /**
     * Legacy stats slide (:1285-1315).
     *
     * @return array{entries:int,entrants:int,judges:int,stewards:int,staff:int,placing:int}
     */
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
}
