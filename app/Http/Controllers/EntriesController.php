<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Entries\EntryGates;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entry management actions behind the account surface (ticket 08).
 *
 * Delete ports the legacy flow: brewer_entries.pub.php renders the link,
 * process.inc.php:185 sends the deleter back to `?section=list&msg=5`, and
 * process_delete.inc.php's `$go == "default"` branch enforces ownership.
 * Legacy gated paid/received/window state in the UI only; the port repeats
 * the same rules server-side (EntryGates) so a crafted POST cannot delete a
 * received or (fee-paid) paid entry.
 */
final class EntriesController extends Controller
{
    public function destroy(int $id): RedirectResponse
    {
        $userId = Auth::id();

        $entry = DB::table('brewing')
            ->where('id', $id)
            ->where('brewBrewerID', (int) $userId)
            ->first();

        if ($entry !== null) {
            $ctx = TenantContext::load();
            $windows = Windows::derive($ctx, time());
            $now = time();

            $allowed = EntryGates::delete(
                (int) $entry->brewReceived,
                (int) $entry->brewPaid,
                $windows->entry === WindowState::Open,
                Windows::entryEditDeadline($ctx),
                $now,
                $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate,
                (float) ($ctx->contestStr('contestEntryFee') ?? 0),
            );

            if ($allowed) {
                DB::table('brewing')->where('id', $id)->delete();
            }
        }

        // Legacy redirect target regardless of outcome (process.inc.php:185).
        return redirect('/list?msg=5');
    }
}
