<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Google Maps hand-off (legacy output/maps.output.php).
 *
 * Divergence from the ticket's "location sheet" assumption, verified in
 * source: legacy maps draws nothing — it is a redirect to maps.google.com
 * with the `id` query parameter (an address string) as the search query.
 * The trailing "&KeepThis=true" left over from legacy's fancybox links is
 * stripped with rtrim(), mirroring the original call verbatim — including
 * its character-list semantics (it trims any of those characters from the
 * end, not that literal suffix).
 */
final class MapsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $idQuery = $request->query('id', '');
        $address = rtrim(is_string($idQuery) ? $idQuery : '', '&amp;KeepThis=true');

        return redirect()->away('http://maps.google.com/maps?f=q&source=s_q&hl=en&q='.str_replace(' ', '+', $address));
    }
}
