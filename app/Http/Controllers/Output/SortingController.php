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
 * Category sorting sheets / judging-number cheat sheets (spec §7 P5.2).
 * Legacy: output/sorting.output.php + output_sorting.db.php.
 *
 * Quirks mirrored:
 *  - The category list comes from DISTINCT styles.brewStyleGroup values
 *    sorted with SORT_NUMERIC (:25) — non-numeric groups collapse to 0
 *    under that sort, mirrored with an int cast in the comparator.
 *  - Entries are selected by brewCategorySort = group with NO
 *    received/paid/confirmed filter and no ORDER BY (output_sorting.db.php)
 *    — unpaid and unreceived entries appear on sorting sheets; the port
 *    orders by id, the storage-order equivalent.
 *  - Header line is "Category {n}: {name} ({count} Entries)" with singular
 *    "Entry"; the category number is ltrim'd of leading zeros (:39); name
 *    from the styles table's brewStyleCategory (style_convert lookup
 *    equivalent for shipped sets).
 *  - Entry rows: %06d entry number (:102), judging number shown only when
 *    view=default (:82-84/:104), brewer "Last, First" + co-brewer on its own
 *    line (:106), paid checkbox glyph vs empty box (:117), empty "sorted"
 *    cell and large empty box for manual use (:118-119).
 *  - Contact cell shows email + phone, US numbers through format_phone_us
 *    (:97-99).
 *  - go=cheat renders the affix sheet instead: subcategory column,
 *    %06d entry number, readable_judging_number() formatting
 *    (common.lib.php:3369 — 5-char numbers split 2/3 as "00-000", 4-char
 *    split 1/3 as "0-000") and an "affixed" empty box.
 *
 * Divergences:
 *  - BA style-set special-casing (:19-38) not ported — corpus and shipped
 *    sets are BJCP; the generic branch covers them.
 */
final class SortingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $cheat = $request->query('go') === 'cheat';
        $showJudging = $request->query('view') !== 'entry';

        $filename = str_replace(' ', '_', (string) $ctx->contestStr('contestName'))
            .'_Sorting_'.($cheat ? 'Cheat' : 'Sheets').'.pdf';

        return StreamPdf::response('outputs.sorting', [
            'cheat' => $cheat,
            'showJudging' => $showJudging,
            'categories' => self::build(),
        ], $filename);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function build(): array
    {
        // Distinct style groups sorted numerically (legacy :15-29).
        $groups = DB::table('styles')->distinct()->orderBy('brewStyleGroup')->pluck('brewStyleGroup')->all();
        usort($groups, fn ($a, $b) => (int) $a <=> (int) $b);
        $groups = array_values(array_unique($groups));

        $categories = [];
        foreach ($groups as $group) {
            // output_sorting.db.php — no received/confirmed filter.
            $entries = DB::table('brewing')
                ->where('brewCategorySort', $group)
                ->orderBy('id')
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            $contacts = DB::table('brewer')
                ->whereIn('uid', $entries->pluck('brewBrewerID')->unique())
                ->get()->keyBy('uid');

            $name = DB::table('styles')->where('brewStyleGroup', $group)->value('brewStyleCategory') ?? '';

            $categories[] = [
                'title' => sprintf(
                    'Category %s: %s (%d %s)',
                    ltrim((string) $group, '0'),
                    $name,
                    $entries->count(),
                    $entries->count() === 1 ? 'Entry' : 'Entries',
                ),
                'rows' => $entries->map(fn ($e) => [
                    'entryNo' => sprintf('%06d', (int) $e->id),
                    'judgingNo' => (string) $e->brewJudgingNumber,
                    'brewer' => trim($e->brewBrewerLastName.', '.$e->brewBrewerFirstName),
                    'coBrewer' => (string) ($e->brewCoBrewer ?? ''),
                    'name' => (string) $e->brewName,
                    'style' => (string) $e->brewStyle,
                    'subCategory' => (string) $e->brewSubCategory,
                    'paid' => $e->brewPaid === '1',
                    'contact' => self::contactLine($contacts->get($e->brewBrewerID)),
                    'readableJudgingNo' => self::readableJudgingNumber((string) $e->brewJudgingNumber),
                ])->all(),
            ];
        }

        return $categories;
    }

    /** Legacy :97-99/:116 — email + phone (US-formatted) in a small cell. */
    private static function contactLine(?\stdClass $brewer): string
    {
        if ($brewer === null) {
            return '';
        }

        $phone = (string) $brewer->brewerPhone1;
        if ($brewer->brewerCountry === 'United States') {
            $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
            $phone = match (strlen($digits)) {
                10 => preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $digits),
                default => $phone,
            };
        }

        return trim($brewer->brewerEmail."\n".$phone);
    }

    /** common.lib.php:3369 readable_judging_number(). */
    private static function readableJudgingNumber(string $number): string
    {
        $split = fn (int $at): string => substr($number, 0, $at).'-'.substr($number, $at);

        return match (strlen($number)) {
            5 => sprintf('%06s', $split(2)),
            4 => sprintf('%06s', $split(1)),
            default => sprintf('%06s', $number),
        };
    }
}
