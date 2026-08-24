<?php

declare(strict_types=1);

namespace App\Support\Brewer;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Legacy `brewerClubs` storage semantics, ported byte-for-byte from
 * `includes/process/process_brewer_info.inc.php:60-73`: a submitted value
 * that names a known club is stored as-is; "Other" stores the free-text
 * field Title-Cased ("Other" itself when left blank); anything else is
 * cleared. The value is a single string — legacy's select offers one club,
 * never a list.
 *
 * Known-club source for the picker and validation: the contest-info
 * `contestClubs` JSON session list plus every club already stored on a
 * `brewer` row (ledger/contest-info.md; legacy fetched the same list from
 * a remote corpus with contestClubs merged on top).
 */
final class Clubs
{
    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function value(array $data, TenantContext $ctx): string
    {
        $club = $data['brewerClubs'] ?? null;

        if (empty($club)) {
            return '';
        }

        if (in_array(strtolower((string) $club), self::known($ctx), true)) {
            return (string) $club;
        }

        if ($club === 'Other' && ! empty($data['brewerClubsOther'])) {
            return ucwords((string) $data['brewerClubsOther']);
        }

        // Legacy stores the literal "Other" when the free-text field is
        // blank; blank_to_null() then keeps it (it is not blank).
        return $club === 'Other' ? 'Other' : '';
    }

    /**
     * @return list<string>
     */
    public static function known(TenantContext $ctx): array
    {
        $known = [];

        $json = $ctx->contestStr('contestClubs');
        if ($json !== null && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $club) {
                    if (is_string($club) && $club !== '') {
                        $known[] = $club;
                    }
                }
            }
        }

        $stored = DB::table('brewer')
            ->whereNotNull('brewerClubs')
            ->where('brewerClubs', '!=', '')
            ->distinct()
            ->pluck('brewerClubs');

        foreach ($stored as $club) {
            if (is_string($club) && $club !== '') {
                $known[] = $club;
            }
        }

        return array_values(array_unique(array_map('strtolower', $known)));
    }
}
