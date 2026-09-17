<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Entries-by-dropoff-location sheet (legacy output/dropoff.output.php +
 * lib/output.lib.php dropoff helpers).
 *
 * Mirrored quirks:
 *  - Location 999 is the shipping pseudo-location: brewers whose profile
 *    has brewerDropOff=999 are counted under the contest's shipping name/
 *    address, shown only when a shipping address is configured.
 *  - Counts join brewing.brewBrewerID → brewer.uid and count EVERY entry
 *    row of those brewers, received or not — the sheet carries the legacy
 *    footnote warning that counts reflect the entrant's chosen location,
 *    not physical receipt.
 *  - go=default renders one summary row per configured drop_off location
 *    (zero-count rows included); go=check renders a per-location entry
 *    list with an empty "received" check box cell, skipping empty
 *    locations, one section per page.
 */
final class DropoffController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();
        $mode = $request->query('go') === 'check' ? 'check' : 'default';

        $shippingName = (string) ($ctx->contest['contestShippingName'] ?? '');
        $shippingAddress = (string) ($ctx->contest['contestShippingAddress'] ?? '');

        $locations = [];

        if ($shippingAddress !== '') {
            $locations[] = [
                'name' => $shippingName !== '' ? $shippingName.' (Shipping Location)' : 'Shipping Location',
                'address' => $shippingAddress,
                'count' => self::entryCount(999),
                'entries' => [],
            ];
        }

        foreach (DB::table('drop_off')->orderBy('dropLocationName')->get(['id', 'dropLocation', 'dropLocationName']) as $row) {
            $count = self::entryCount($row->id);
            if ($mode === 'check' && $count === 0) {
                continue;
            }

            $locations[] = [
                'name' => (string) $row->dropLocationName,
                'address' => (string) $row->dropLocation,
                'count' => $count,
                'entries' => $mode === 'check' ? self::entriesAt($row->id) : [],
            ];
        }

        return StreamPdf::response('outputs.dropoff', [
            'mode' => $mode,
            'locations' => $locations,
            'total' => array_sum(array_column($locations, 'count')),
        ], 'dropoff.pdf');
    }

    private static function entryCount(int|string $locationId): int
    {
        return DB::table('brewing as b')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->where('br.brewerDropOff', (int) $locationId)
            ->count();
    }

    /**
     * @return list<object>
     */
    private static function entriesAt(int|string $locationId): array
    {
        /** @var list<object> */
        return DB::table('brewing as b')
            ->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
            ->where('br.brewerDropOff', (int) $locationId)
            ->orderBy('b.id')
            ->get(['b.id', 'b.brewName', 'br.brewerLastName', 'br.brewerFirstName'])
            ->all();
    }
}
