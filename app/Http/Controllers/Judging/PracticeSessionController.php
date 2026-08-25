<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\Controller;
use App\Support\Brewer\Clubs;
use App\Support\Entries\JudgingNumber;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * practice_session bootstrap (spec P4.7): ajax/practice_session.ajax.php —
 * builds a self-contained judging sandbox: a practice location, dummy
 * entrant, one custom style + entry per requested style type, table 999
 * with all of it in flight 1, and every judge assigned + marked available.
 *
 * Body parity: legacy echoed raw HTML fragments ("<Style> Complete<br>"
 * per imported entry, "<Scoresheet Practice> Table Added<br>"), no JSON.
 * Those fragments are deterministic given the labels, so they are the
 * parity surface; everything else (dummy credentials, judging numbers) is
 * random in legacy too. Failure to create the location echoes nothing;
 * an unauthorized hit gets legacy's redirect to the placeholder image.
 *
 * Gate: userLevel == 0 in-controller per legacy (admin only). CSRF-
 * protected POST is port hardening. Only style types 1|2|3 are processed —
 * the source form offers exactly those checkboxes.
 */
final class PracticeSessionController extends Controller
{
    public function store(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || (int) $user->userLevel !== 0) {
            return response()->redirectTo('https://pbs.twimg.com/media/CGx6dsDVIAAV0am.png');
        }

        $ctx = TenantContext::load();

        $out = '';

        $types = (array) $request->input('selected_style_types', ['1', '2', '3']);

        $start = $request->input('judging_session_start');
        if ($start !== null && $start != 0) {
            $start = AjaxController::sterilize((string) $start);
        } else {
            $start = time(); // "immediately"
        }

        // Last posted judging date across real locations; in the past (or
        // none) ⇒ a week out, as legacy defaulted.
        $dates = [];
        foreach (DB::table('judging_locations')->where('judgingLocType', '<', 2)->get(['judgingDate', 'judgingDateEnd']) as $row) {
            foreach (['judgingDate', 'judgingDateEnd'] as $col) {
                if ($row->$col !== null && $row->$col !== '') {
                    $dates[] = $row->$col;
                }
            }
        }
        $lastJudgingDate = $dates === [] ? '' : (string) max($dates);
        if ($lastJudgingDate === '' || time() > (int) $lastJudgingDate) {
            $lastJudgingDate = time() + 604800;
        }

        $sessionLabel = trans('site.practice_session');
        $entryLabel = trans('site.practice_entry');
        $tableLabel = trans('site.scoresheet_practice');

        $locationId = DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 1, // Practice location
            'judgingDate' => $start,
            'judgingDateEnd' => $lastJudgingDate,
            'judgingLocName' => is_string($sessionLabel) ? $sessionLabel : 'Practice Session',
            'judgingLocation' => ucfirst(strtolower(is_string($sessionLabel) ? $sessionLabel : 'Practice Session')),
            'judgingRounds' => 1,
        ]);

        if ($locationId === false || $locationId === null) {
            return response($out, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // Mark every judge available at the new location and remember them
        // for assignment below.
        $judgesList = [];
        foreach (DB::table('brewer')->where('brewerJudge', 'Y')->get(['id', 'uid', 'brewerJudgeLocation', 'brewerFirstName', 'brewerLastName']) as $judge) {
            $judgesList[] = [
                'uid' => $judge->uid,
                'brewerFirstName' => $judge->brewerFirstName,
                'brewerLastName' => $judge->brewerLastName,
            ];

            DB::table('brewer')->where('id', $judge->id)
                ->update(['brewerJudgeLocation' => $judge->brewerJudgeLocation.',Y-'.$locationId]);
        }

        // Dummy participant the practice entries attach to.
        $username = Str::random(10).'@practice-user.com';
        $userId = (int) DB::table('users')->insertGetId([
            'user_name' => $username,
            'userLevel' => 2,
            'password' => app('hash')->make(Str::random(10)),
            'userQuestion' => 'Randomly Generated',
            'userQuestionAnswer' => app('hash')->make(AjaxController::sterilize(Str::random(10))),
            'userCreated' => date('Y-m-d H:i:s'),
            'userAdminObfuscate' => 1,
        ]);

        $knownClubs = Clubs::known($ctx);
        $club = $knownClubs === [] ? '' : $knownClubs[array_rand($knownClubs)];

        DB::table('brewer')->insert([
            'uid' => $userId,
            'brewerFirstName' => 'Practice',
            'brewerLastName' => 'Entrant',
            'brewerAddress' => '1234 Main',
            'brewerCity' => 'Denver',
            'brewerState' => 'CO',
            'brewerZip' => '80000',
            'brewerCountry' => 'United States',
            'brewerPhone1' => '(000) 867-5309',
            'brewerClubs' => $club,
            'brewerEmail' => $username,
            'brewerJudgeNotes' => 'Dummy participant for electronic scoresheet practice.',
            'brewerDropOff' => 0,
        ]);

        // One custom style (group >= 50, sub "A") per selected type.
        $group = (int) DB::table('styles')
            ->where('brewStyleGroup', '>=', '50')
            ->where('brewStyleOwn', 'custom')
            ->max('brewStyleGroup');
        if ($group < 50) {
            $group = 50;
        }

        $styleMap = [
            '1' => ['label' => 'practice_beer', 'type' => 1, 'strength' => null, 'carb' => null, 'sweet' => null],
            '2' => ['label' => 'practice_cider', 'type' => 2, 'strength' => 1, 'carb' => 0, 'sweet' => 1],
            '3' => ['label' => 'practice_mead', 'type' => 3, 'strength' => 1, 'carb' => 1, 'sweet' => 1],
        ];

        $addedStyles = [];

        foreach ($types as $type) {
            $map = $styleMap[(string) $type] ?? null;
            if ($map === null) {
                continue;
            }

            $group += 1;

            $labelKey = $map['label'];
            $styleName = is_string(trans("site.$labelKey")) ? trans("site.$labelKey") : ucfirst($labelKey);

            $styleId = DB::table('styles')->insertGetId([
                'brewStyle' => AjaxController::sterilize($styleName),
                'brewStyleType' => $map['type'],
                'brewStyleGroup' => $group,
                'brewStyleNum' => 'A',
                'brewStyleActive' => 'N',
                'brewStyleOwn' => 'custom',
                'brewStyleVersion' => $ctx->prefsStr('prefsStyleSet'),
                'brewStyleStrength' => $map['strength'],
                'brewStyleCarb' => $map['carb'],
                'brewStyleSweet' => $map['sweet'],
            ]);

            $addedStyles[] = [
                'id' => $styleId,
                'brewStyle' => AjaxController::sterilize($styleName),
                'brewStyleGroup' => $group,
                'brewStyleNum' => 'A',
                'strength' => $map['strength'],
                'carb' => $map['carb'],
                'sweet' => $map['sweet'],
                'type' => $map['type'],
            ];
        }

        // One received entry per added style, then the practice table.
        $addedTableStyles = [];
        $addedEntryIds = [];

        foreach ($addedStyles as $key => $style) {
            $addedTableStyles[] = $style['id'];

            $notes = ucfirst(strtolower(
                (is_string(trans('site.'.$styleMap[(string) $style['type']]['label'])) ? trans('site.'.($styleMap[(string) $style['type']]['label'])) : '').'.'
            ));

            $entryId = DB::table('brewing')->insertGetId([
                'brewName' => (is_string($entryLabel) ? $entryLabel : 'Practice Entry').' '.$key,
                'brewStyle' => $style['brewStyle'],
                'brewCategory' => $style['brewStyleGroup'],
                'brewCategorySort' => $style['brewStyleGroup'],
                'brewSubCategory' => $style['brewStyleNum'],
                'brewMead1' => $style['carb'],
                'brewMead2' => $style['sweet'],
                'brewMead3' => $style['strength'],
                'brewStyleType' => $style['type'],
                'brewConfirmed' => 1,
                'brewReceived' => 1,
                'brewPaid' => 0,
                'brewUpdated' => date('Y-m-d H:i:s'),
                'brewJudgingNumber' => JudgingNumber::random(),
                'brewBrewerID' => $userId,
                'brewBrewerFirstName' => 'Practice',
                'brewBrewerLastName' => 'Entrant',
                'brewStaffNotes' => $notes,
                'brewAdminNotes' => $notes,
                'brewPouring' => '{"pouring":"Normal","pouring_rouse":"No"}',
            ]);

            $addedEntryIds[] = $entryId;

            $out .= $style['brewStyle'].' Complete<br>';
        }

        $tableId = DB::table('judging_tables')->insertGetId([
            'tableName' => is_string($tableLabel) ? $tableLabel : 'Scoresheet Practice',
            'tableStyles' => implode(',', $addedTableStyles),
            'tableNumber' => 999,
            'tableLocation' => $locationId,
            'tableEntryLimit' => null,
        ]);
        if ($tableId !== false && $tableId !== null) {
            $out .= (is_string($tableLabel) ? $tableLabel : 'Scoresheet Practice').' Table Added<br>';

            foreach ($addedEntryIds as $entryId) {
                DB::table('judging_flights')->insert([
                    'flightTable' => $tableId,
                    'flightNumber' => 1,
                    'flightEntryID' => $entryId,
                    'flightRound' => 1,
                ]);
            }

            foreach ($judgesList as $judge) {
                DB::table('judging_assignments')->insert([
                    'bid' => $judge['uid'],
                    'assignment' => 'J',
                    'assignTable' => $tableId,
                    'assignFlight' => 1,
                    'assignRound' => 1,
                    'assignLocation' => $locationId,
                    'assignPlanning' => null,
                    'assignRoles' => null,
                ]);
            }
        }

        return response($out, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
