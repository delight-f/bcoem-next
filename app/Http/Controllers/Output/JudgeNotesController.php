<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Judge/participant notes printout (spec §7 P5.2).
 *
 * Legacy: output/judge_notes.output.php via print.output.php section=notes,
 * three ?go= variants:
 *  - org_notes (default here): participants whose brewerJudgeNotes is set;
 *  - allergens: paid entries with brewPossAllergens, plus table/flight info;
 *  - admin: entries carrying brewAdminNotes and/or brewStaffNotes.
 *
 * DIVERGENCE: legacy renders an empty page when go=default (no section
 * matches); the port renders the org_notes variant by default.
 */
final class JudgeNotesController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        $section = match ($request->query('go')) {
            'allergens' => 'allergens',
            'admin' => 'admin',
            default => 'org_notes',
        };

        // jPrefsQueued == "N" means flights are physically queued, so the
        // round/flight breakdown is meaningful on paper (legacy parity).
        $showFlight = strtoupper((string) $ctx->judgingStr('jPrefsQueued')) !== 'N';

        $data = [
            'contestName' => $ctx->contestStr('contestName'),
            'section' => $section,
            'showFlight' => $showFlight,
            'rows' => null,
            'entries' => null,
        ];

        if ($section === 'org_notes') {
            $data['rows'] = DB::table('brewer')
                ->whereNotNull('brewerJudgeNotes')
                ->where('brewerJudgeNotes', '!=', '')
                ->orderBy('brewerLastName')->orderBy('brewerFirstName')
                ->get(['brewerFirstName', 'brewerLastName', 'brewerJudgeNotes']);
        } else {
            // Legacy get_flight_info(): first flight containing the entry
            // in its CSV list supplies the table/round/flight placement.
            // Resolved in PHP because the tenant table prefix makes a raw
            // FIND_IN_SET join condition un-prefixable.
            $entries = DB::table('brewing')->where('brewPaid', 1)->orderBy('id')
                ->get(['id', 'brewJudgingNumber', 'brewStyle', 'brewPossAllergens', 'brewAdminNotes', 'brewStaffNotes']);

            $match = $section === 'allergens'
                ? static fn ($e) => (string) $e->brewPossAllergens !== ''
                : static fn ($e) => (string) $e->brewAdminNotes !== '' || (string) $e->brewStaffNotes !== '';

            $data['entries'] = $this->withPlacement($entries->filter($match)->values());
        }

        return StreamPdf::response('outputs.judge-notes', $data, 'judge-notes.pdf');
    }

    /**
     * Attach each entry's first flight placement (table number/name and
     * round/flight), mirroring legacy get_flight_info().
     *
     * @param  Collection<int, \stdClass>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function withPlacement($entries)
    {
        $tables = DB::table('judging_tables')->get()->keyBy('id');

        $placement = [];
        foreach (DB::table('judging_flights')->orderBy('id')->get() as $flight) {
            foreach (array_filter(explode(',', (string) $flight->flightEntryID)) as $entryId) {
                $placement[(int) trim($entryId)] ??= [
                    'tableNumber' => $tables[$flight->flightTable]->tableNumber ?? null,
                    'tableName' => $tables[$flight->flightTable]->tableName ?? null,
                    'flightNumber' => $flight->flightNumber,
                    'flightRound' => $flight->flightRound,
                ];
            }
        }

        return $entries->map(static function (\stdClass $entry) use ($placement): array {
            $p = $placement[$entry->id] ?? ['tableNumber' => null, 'tableName' => null, 'flightNumber' => null, 'flightRound' => null];

            $row = [];
            foreach (get_object_vars($entry) as $key => $value) {
                $row[(string) $key] = $value;
            }

            return array_merge($row, $p);
        });
    }
}
