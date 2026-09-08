<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Participant address/name label sheets (spec §7 P5.2). Legacy:
 * output/labels.output.php, go=participants&action=address_labels branch
 * (the only address/name-sheet mode; the other ~10 legacy label modes —
 * judging box labels, quicksort, required-info, nametags, award/medal,
 * round bottle labels — are separate surfaces not in this ticket's scope).
 *
 * Quirks mirrored:
 *  - psort selects the sheet density: Avery 3422 = 24 labels/sheet,
 *    anything else = Avery 5160 = 30 labels/sheet (:54-55).
 *  - filter=with_entries prints TWO labels per participant with received
 *    entries: an "Entry Summary" label (name, count, entry/judging-number
 *    list) followed by the address label; brewers are ordered by
 *    brewerLastName ASC and only brewReceived='1' rows are counted
 *    (output_labels.db.php:105-113 + lib/output.lib.php user_entry_count()).
 *  - view=entry lists %06d entry numbers ordered by id; anything else lists
 *    %06d judging numbers ordered by judging number (user_entry_count :202).
 *  - Non-US participants get their country as the last address line;
 *    "United States" is suppressed (:979, :1020).
 *  - `sort` request param repeats every label N times — legacy's copies
 *    loop from its sibling round-label branches (:706).
 */
final class LabelsController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $psort = $request->query('psort', '5160');
        $withEntries = $request->query('filter') === 'with_entries';
        $copies = max(1, (int) $request->query('sort', '1'));
        // Legacy user_entry_count($uid,$view): view=entry lists entry
        // numbers ordered by id; anything else lists judging numbers.
        $view = $request->query('view') === 'entry' ? 'entry' : 'judging';

        $contest = str_replace(' ', '_', (string) $ctx->contestStr('contestName'));
        $filename = $contest.'_Participants_'.($withEntries ? 'With_Entries_' : '')
            .'Address_Labels'.($psort === '3422' ? '_Avery3422' : '_Avery5160').'.pdf';

        return StreamPdf::response('outputs.labels', [
            'perSheet' => $psort === '3422' ? 24 : 30,
            'labels' => self::build($withEntries, $copies, $view),
        ], $filename);
    }

    /**
     * View payload: a flat list of labels, each a list of text lines.
     *
     * @return list<list<string>>
     */
    public static function build(bool $withEntries, int $copies, string $view = 'judging'): array
    {
        // output_labels.db.php:106 — all brewers, last-name order.
        $brewers = DB::table('brewer')->orderBy('brewerLastName')->get();

        // filter=with_entries: distinct brewers having received entries.
        $withIds = [];
        if ($withEntries) {
            $withIds = DB::table('brewing')->where('brewReceived', '1')
                ->distinct()->pluck('brewBrewerID')->all();
        }

        $labels = [];
        foreach ($brewers as $b) {
            if ($withEntries && ! in_array($b->uid, $withIds)) {
                continue;
            }

            $country = $b->brewerCountry !== 'United States' ? (string) $b->brewerCountry : '';

            if ($withEntries) {
                // Entry-summary label first (legacy :993-1001).
                $rows = DB::table('brewing')->where('brewBrewerID', $b->uid)
                    ->where('brewReceived', '1')
                    ->orderBy($view === 'entry' ? 'id' : 'brewJudgingNumber')
                    ->get();

                $numbers = $rows->map(
                    fn ($r) => sprintf('%06d', $view === 'entry' ? (int) $r->id : (int) $r->brewJudgingNumber),
                )->unique()->implode(', ');

                $count = $rows->count().' '.($rows->count() === 1 ? 'Entry' : 'Entries');

                // Legacy truncates the number list to fit the remaining
                // label lines (126 chars when a country line follows,
                // else 166; :990-991).
                $numbers = mb_substr($numbers, 0, $country !== '' ? 126 : 166);

                for ($i = 0; $i < $copies; $i++) {
                    $labels[] = [
                        'Entry Summary for '.trim($b->brewerFirstName.' '.$b->brewerLastName),
                        $count,
                        'Entry #: '.$numbers,
                        $country,
                    ];
                }
            }

            $address = [
                trim($b->brewerFirstName.' '.$b->brewerLastName),
                (string) $b->brewerAddress,
                trim(sprintf('%s, %s %s', $b->brewerCity, $b->brewerState, $b->brewerZip), ', '),
                $country,
            ];

            for ($i = 0; $i < $copies; $i++) {
                $labels[] = $address;
            }
        }

        return $labels;
    }
}
