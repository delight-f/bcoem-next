<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * custom_style lookup (spec P4.7): ajax/custom_style.ajax.php.
 *
 * Legacy GET with rid1..rid4 — the mods custom-category form polls it to
 * learn whether a group/sub number pair is free. rid1/rid2 are the
 * proposed values, rid3/rid4 the previously saved ones ("unchanged" means
 * the organizer isn't editing the numbers, status 4).
 *
 * Envelope parity (legacy key order): {"status", "message"}; message is a
 * legacy HTML fragment or the initial "Awaiting input." sentinel — note
 * status 0 and 4 both keep that sentinel, verbatim.
 *
 * Gate: session + userLevel<=1 in-controller (legacy); an anonymous hit
 * gets {"status":"9","message":"Awaiting input."}, not a redirect. GET
 * lookup only — no write, so no CSRF concern.
 */
final class CustomStyleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $rid1 = $this->rid($request, 'rid1');
        $rid2 = $this->rid($request, 'rid2');
        $rid3 = $this->rid($request, 'rid3');
        $rid4 = $this->rid($request, 'rid4');

        $status = 0;
        $message = 'Awaiting input.';

        if ($request->user() !== null && (int) $request->user()->userLevel <= 1) {
            $doQuery = $rid1 !== 'default' && $rid2 !== 'default';
            $okEditStyle = $rid1 !== $rid3 || $rid2 !== $rid4;

            if ($doQuery) {
                $group = $rid1;
                $sub = $rid2;

                if (is_numeric($group) && (float) $group < 50) {
                    // 1-49 are reserved for system use.
                    $status = 1;
                    $message = '<span class="text-primary">All custom style category numbers must be at least 50 &ndash; 1-49 are reserved for system use. <i class="fa fa-info-circle"></i></span>';
                } else {
                    if ($okEditStyle) {
                        $taken = DB::table('styles')
                            ->where('brewStyleGroup', $group)
                            ->where('brewStyleNum', $sub)
                            ->exists();

                        if ($taken) {
                            $status = 2;
                            $message = '<span class="text-danger">Style and sub-style combination already in use. <i class="fa fa-exclamation-triangle"></i></span>';
                        } elseif ($group !== '' && $sub !== '') {
                            $status = 3;
                            $message = '<span class="text-success">Style and sub-style combination is available. <i class="fa fa-check-circle"></i></span>';
                        }
                    } else {
                        $status = 4;
                    }
                }
            } else {
                $message = '<span class="text-warning">An identifier is needed in both fields. <i class="fa fa-exclamation-triangle"></i></span>';
            }
        } else {
            $status = 9; // Session expired, not enabled, etc.
        }

        return response()->json([
            'status' => (string) $status,
            'message' => $message,
        ]);
    }

    /** Legacy's sterilized $_GET read with its "default" sentinel. */
    private function rid(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? AjaxController::sterilize($value) : 'default';
    }
}
