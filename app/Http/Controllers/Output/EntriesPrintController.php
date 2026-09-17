<?php

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Legacy go=entries&action=print (admin/entries.admin.php:700-733, 988-1027).
 *
 * An HTML print view — the same DataTables table as the admin page (columns:
 * Entry, Judging, Name, Style, Brewer, Paid, Rec'd, Admin Notes, Staff Notes,
 * Loc/Box; hidden-print columns omitted in print mode), sorted server-side by
 * the psort variant (entry_number|judging_number|entry_name|category|
 * brewer_name) and self-printing via window.print().
 *
 * view=all includes unconfirmed entries (brewConfirmed <> 1); the default
 * view is confirmed entries only, matching the page's default query.
 */
final class EntriesPrintController extends Controller
{
    /** psort -> [column, direction] server-side ordering. */
    private const SORTS = [
        'entry_number' => ['brewing.id', 'asc'],
        'judging_number' => ['brewing.brewJudgingNumber', 'asc'],
        'entry_name' => ['brewing.brewName', 'asc'],
        'category' => ['brewing.brewCategorySort', 'asc'],
        'brewer_name' => ['brewer.brewerLastName', 'asc'],
    ];

    public function __invoke(Request $request): View
    {
        $psort = (string) $request->query('psort', 'entry_number');
        $view = (string) $request->query('view', 'default');
        [$sortCol, $dir] = self::SORTS[$psort] ?? self::SORTS['entry_number'];

        $q = DB::table('brewing')
            ->join('brewer', 'brewer.id', '=', 'brewing.brewBrewerID')
            ->when($view === 'paid', fn ($qq) => $qq->where('brewing.brewPaid', 1))
            ->orderBy($sortCol, $dir)
            ->orderBy('brewing.id')
            ->get([
                'brewing.*',
                'brewer.brewerFirstName',
                'brewer.brewerLastName',
                'brewer.brewerClubs',
            ]);

        $ctx = TenantContext::load();
        $proEdition = (int) $ctx->prefsStr('proEdition') === 1;

        // Legacy header (:49-54): "<Contest>: All Entries" (paid/unpaid variants
        // never reach this print path with another $view).
        $header = $ctx->contestStr('contestName').': All Entries';

        return view('outputs.entries-print', [
            'ctx' => $ctx,
            'psort' => $psort,
            'view' => $view,
            'proEdition' => $proEdition,
            'entries' => $q,
            'header' => $header,
        ]);
    }
}
