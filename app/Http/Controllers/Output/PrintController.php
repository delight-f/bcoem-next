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
 * Printable contact card(s) (legacy output/print.output.php).
 *
 * Divergence, verified in source: legacy print.output.php was an HTML
 * print CHROME — it dispatched ?section=… to the other output modules and
 * only owned one piece of content itself, the contact card (its own
 * obfuscated-email JS and auto-window.print() script are meaningless in a
 * server-rendered PDF). Every wrapped module now has its own self-contained
 * PDF endpoint under /admin/output/, so this route keeps only that owned
 * content: the contact sheet. A second divergence: without action=edit&id=N
 * legacy printed just the FIRST contact row; here an absent `id` renders
 * all contacts by last name, which is what the card is for.
 */
final class PrintController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $id = $request->query('id');

        $query = DB::table('contacts')->orderBy('contactLastName')->orderBy('contactFirstName');
        if ($id !== null) {
            $query->where('id', (int) $id);
        }

        return StreamPdf::response('outputs.print', [
            'contacts' => $query->get(['id', 'contactFirstName', 'contactLastName', 'contactPosition', 'contactEmail'])->all(),
            'notFound' => $id !== null && DB::table('contacts')->where('id', (int) $id)->doesntExist(),
        ], 'print.pdf');
    }
}
