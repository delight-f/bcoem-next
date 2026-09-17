<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\OutputFormat;
use App\Support\Outputs\StreamPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * One-row-per-participant list of entry numbers and judging numbers
 * (legacy output/participant_entries_list.output.php). Useful for
 * handing out scoresheets sorted by number after the awards ceremony.
 */
final class ParticipantEntriesListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $brewers = DB::table('brewer as br')
            ->join('users as u', 'br.brewerEmail', '=', 'u.user_name')
            ->orderBy('br.brewerLastName')
            ->get(['br.uid', 'br.brewerFirstName', 'br.brewerLastName']);

        $rows = [];

        foreach ($brewers as $brewer) {
            // Received entries only, ordered by judging number
            // (output_participant_summary.db.php — both participant outputs share it).
            $entries = DB::table('brewing')
                ->where('brewBrewerID', $brewer->uid)
                ->where('brewReceived', '1')
                ->orderBy('brewJudgingNumber')
                ->get(['id', 'brewJudgingNumber']);

            if ($entries->isEmpty()) {
                continue;
            }

            $rows[] = [
                'name' => $brewer->brewerLastName.', '.$brewer->brewerFirstName,
                'entryNumbers' => $entries->map(fn (object $e): string => sprintf('%06s', $e->id))->implode(', '),
                'judgingNumbers' => $entries->map(fn (object $e): string => OutputFormat::judgingNumber($e->brewJudgingNumber))->implode(', '),
            ];
        }

        return StreamPdf::response('outputs.participant-entries-list', [
            'rows' => $rows,
        ], 'participant-entries-list.pdf');
    }
}
