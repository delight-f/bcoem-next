<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Paper entry forms (spec §7 P5.2).
 *
 * DIVERGENCE: legacy output/entry.output.php is dead code — its template
 * body was commented out in 2.7.0 (the live "entry" print became
 * bottle_label.output.php via output.inc.php section=entry-form-multi,
 * ported separately as bottle_label). This controller restores the
 * deprecated behaviour's intent: one printed paper-entry sheet per
 * received entry, with the fields the old bcoem-entry templates carried
 * (entry/judging numbers, style, brewer, special ingredients, mead
 * attributes, paid marker).
 */
final class EntryController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $entries = DB::table('brewing')
            ->join('brewer', 'brewer.id', '=', 'brewing.brewBrewerID')
            ->where('brewing.brewReceived', 1)
            ->orderBy('brewing.id')
            ->get([
                'brewing.*',
                'brewer.brewerFirstName',
                'brewer.brewerLastName',
                'brewer.brewerClubs',
                'brewer.brewerEmail',
            ]);

        return StreamPdf::response('outputs.entry', [
            'entries' => $entries,
        ], 'paper-entry-forms.pdf');
    }
}
