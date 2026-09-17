<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Style reference sheet for the active style set (legacy
 * output/styles.output.php). Rows respect brewStyleActive='Y' plus the
 * active-set version predicates (styles ledger #5-#7) — the legacy output
 * printed the whole set regardless of the active flag; the ticket pins the
 * stricter filter.
 */
final class StylesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ctx = TenantContext::load();
        $set = $ctx->prefsStr('prefsStyleSet') ?? 'BJCP2021';

        // Active-set predicate lives in StyleSets (#5-#7) rather than being
        // inlined here, so the dual-version/custom rules have one definition.
        $query = StyleSets::activeQuery($set)->where('brewStyleActive', 'Y');

        $styles = [];

        foreach ($query->orderBy('brewStyleType')->orderBy('brewStyleGroup')->orderBy('brewStyleNum')->get() as $row) {
            $styles[] = [
                'name' => (string) $row->brewStyle,
                'category' => (string) $row->brewStyleCategory,
                'number' => ltrim((string) $row->brewStyleGroup, '0').(string) $row->brewStyleNum,
                'info' => self::emphasize((string) $row->brewStyleInfo),
                'comEx' => (string) $row->brewStyleComEx,
                'entry' => (string) $row->brewStyleEntry,
                'og' => self::range($row->brewStyleOG, $row->brewStyleOGMax, 3),
                'fg' => self::range($row->brewStyleFG, $row->brewStyleFGMax, 3),
                'abv' => self::range($row->brewStyleABV, $row->brewStyleABVMax, 1, '%'),
                'ibu' => self::bitterness($row->brewStyleIBU, $row->brewStyleIBUMax),
                'srm' => self::color($row->brewStyleSRM, $row->brewStyleSRMMax),
                'link' => (string) $row->brewStyleLink,
            ];
        }

        return StreamPdf::response('outputs.styles', [
            'titleSet' => str_replace('2', ' 2', $set),
            'styles' => $styles,
        ], 'styles.pdf');
    }

    /** Legacy's MUST/MAY specify/provide underlining on guideline text. */
    private static function emphasize(string $info): string
    {
        return str_replace(
            ['must specify', 'may specify', 'MUST specify', 'MAY specify', 'must provide'],
            ['<u>must</u> specify', '<u>may</u> specify', '<u>MUST</u> specify', '<u>MAY</u> specify', '<u>must</u> provide'],
            $info,
        );
    }

    /** Numeric range cell: empty minimum renders "Varies", else min–max at fixed decimals with optional suffix. */
    private static function range(?string $min, ?string $max, int $decimals, string $suffix = ''): string
    {
        if ($min === null || $min === '') {
            return 'Varies';
        }

        return number_format((float) $min, $decimals, '.', '').' – '
            .number_format((float) $max, $decimals, '.', '').$suffix;
    }

    /** IBU cell adds the N/A case and trims leading zeros like legacy. */
    private static function bitterness(?string $min, ?string $max): string
    {
        if ($min === null || $min === '') {
            return 'Varies';
        }

        if ($min === 'N/A') {
            return 'N/A';
        }

        return ltrim($min, '0').' – '.ltrim((string) $max, '0').' IBU';
    }

    /** SRM cell as colored swatches; N/A passes through, empty means "Varies". */
    private static function color(?string $min, ?string $max): string
    {
        if ($min === null || $min === '') {
            return 'Varies';
        }

        if ($min === 'N/A') {
            return 'N/A';
        }

        $srmMin = ltrim($min, '0');
        $srmMax = ltrim((string) $max, '0');

        return self::swatch($srmMin).' – '.self::swatch($srmMax).' SRM';
    }

    private static function swatch(string $srm): string
    {
        $bg = match (true) {
            $srm >= 1 && $srm < 2 => '#f3f993',
            $srm >= 2 && $srm < 3 => '#f5f75c',
            $srm >= 3 && $srm < 4 => '#f6f513',
            $srm >= 4 && $srm < 5 => '#eae615',
            $srm >= 5 && $srm < 6 => '#e0d01b',
            $srm >= 6 && $srm < 7 => '#d5bc26',
            $srm >= 7 && $srm < 8 => '#cdaa37',
            $srm >= 8 && $srm < 9 => '#c1963c',
            $srm >= 9 && $srm < 10 => '#be8c3a',
            $srm >= 10 && $srm < 11 => '#be823a',
            $srm >= 11 && $srm < 12 => '#c17a37',
            $srm >= 12 && $srm < 13 => '#bf7138',
            $srm >= 13 && $srm < 14 => '#bc6733',
            $srm >= 14 && $srm < 15 => '#b26033',
            $srm >= 15 && $srm < 16 => '#a85839',
            $srm >= 16 && $srm < 17 => '#985336',
            $srm >= 17 && $srm < 18 => '#8d4c32',
            $srm >= 18 && $srm < 19 => '#7c452d',
            $srm >= 19 && $srm < 20 => '#6b3a1e',
            $srm >= 20 && $srm < 21 => '#5d341a',
            $srm >= 21 && $srm < 22 => '#4e2a0c',
            $srm >= 22 && $srm < 23 => '#4a2727',
            $srm >= 23 && $srm < 24 => '#361f1b',
            $srm >= 24 && $srm < 25 => '#261716',
            $srm >= 25 && $srm < 26 => '#231716',
            $srm >= 26 && $srm < 27 => '#19100f',
            $srm >= 27 && $srm < 28 => '#16100f',
            $srm >= 28 && $srm < 29 => '#120d0c',
            $srm >= 29 && $srm < 30 => '#100b0a',
            $srm >= 30 && $srm < 31 => '#050b0a',
            $srm > 31 => '#000000',
            default => '#ffffff',
        };

        // Dark text on light beer, white text once the swatch darkens (~15 SRM).
        $fg = $srm >= 15 ? '#ffffff' : '#000000';

        return '<span style="background-color:'.$bg.'; color:'.$fg.';">&nbsp;'.$srm.'&nbsp;</span>';
    }
}
