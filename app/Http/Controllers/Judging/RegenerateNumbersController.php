<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Entries\JudgingNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate judging numbers for all entries (legacy
 * ajax/regenerate.ajax.php → lib/process.lib.php
 * generate_judging_numbers($prefix."brewing", $method)).
 *
 * Methods (legacy $method):
 *  - default  : random 6-digit numbers (digits 1–9), unique against the
 *               brewing column and any user_docs scoresheet file
 *  - legacy   : per-category sequence CAT-NNN continuing each category's
 *               highest existing NNN
 *  - identical: judging number = zero-padded entry id
 *
 * The whole table is wiped first (brewJudgingNumber = NULL), then every
 * entry is re-numbered in brewCategorySort/brewSubCategory order, exactly
 * like legacy. Admin-only (userLevel 0, matching the legacy ajax gate).
 */
final class RegenerateNumbersController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ((int) $request->user()?->userLevel !== 0) {
            return redirect('/?msg=99');
        }

        $method = (string) $request->input('method', $request->query('method', ''));
        if (! in_array($method, ['default', 'legacy', 'identical'], true)) {
            return redirect('/admin')->with('error', 'Unknown judging-number method.');
        }

        DB::table('brewing')->update(['brewJudgingNumber' => null]);

        $entries = DB::table('brewing')
            ->orderBy('brewCategorySort')
            ->orderBy('brewSubCategory')
            ->get(['id', 'brewCategory']);

        foreach ($entries as $entry) {
            $number = match ($method) {
                'identical' => JudgingNumber::sameAsEntry((int) $entry->id),
                'legacy' => JudgingNumber::withStylePrefix((string) $entry->brewCategory),
                default => JudgingNumber::random(),
            };

            DB::table('brewing')->where('id', $entry->id)->update(['brewJudgingNumber' => $number]);
        }

        $label = ['default' => 'random', 'legacy' => 'style-prefix', 'identical' => 'entry-number'][$method];

        return redirect('/admin')->with('status', "Judging numbers regenerated ({$label}).");
    }
}
