<?php

declare(strict_types=1);

namespace App\Http\Controllers\Output;

use App\Http\Controllers\Controller;
use App\Support\Outputs\StreamPdf;
use App\Support\Tenant\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Entry bottle labels (spec §7 P5.2). Legacy: output/bottle_label.output.php
 * — legacy streamed HTML with a JS self-print timer; the port renders the
 * same 3-column label grid to PDF via the shared StreamPdf pipeline.
 *
 * Quirks mirrored:
 *  - Label variant flags derive from prefsEntryForm via legacy's exact
 *    membership arrays (:93-99): anon vs standard contact block,
 *    barcode/QR, large entry number, large style text, judging- vs
 *    entry-number display.
 *  - Copies per entry = jPrefsBottleNum, default 3 when unset (:110-111).
 *  - Page break every 9 labels when the variant shows a barcode/QR (taller
 *    labels), else every 12 (legacy :119-127).
 *  - Barcode value is %06d of the judging number or entry number per the
 *    variant (:143-144).
 *  - brewInfo "^" separators render as spaces; truncated to 200 chars, or
 *    150 when mead/cider required-info lines are also present (:229-234).
 *  - BJCP 2021/2025 category 02A prints "Regional Variation:" instead of
 *    "Required Info:" as the brewInfo label (:238).
 *  - US phone numbers get format_phone_us treatment; others pass through.
 *  - Standard labels show brewery name + "Contact:" line when
 *    prefsProEdition == 1, else just the brewer's name (:253-258).
 *
 * Divergences:
 *  - Admin-only here; legacy let a logged-in brewer print their own labels
 *    (bid ownership check :32). The port's route group is admin-gated per
 *    ticket.
 *  - Legacy pulled remote code39/QR PNGs from an external service
 *    (admin.brewingcompetitions.com code39 + api.qrserver.com QR); the
 *    port renders the QR locally as an inline base64 SVG via
 *    bacon/bacon-qr-code — payload mirrors legacy "$base_url/qr.php?id=N"
 *    as url('/qr?id=N') on the request host. The code39 value still renders
 *    as bracketed text (dompdf has no code39 encoder).
 */
final class BottleLabelController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $idsQuery = $request->query('ids', '');
        $idQuery = $request->query('id', 'default');
        $bidQuery = $request->query('bid', 'default');
        $data = self::build(
            TenantContext::load(),
            is_string($idsQuery) ? $idsQuery : '',
            is_string($idQuery) ? $idQuery : 'default',
            is_string($bidQuery) ? $bidQuery : 'default',
        );

        return StreamPdf::response('outputs.bottle_label', $data['view'], $data['filename']);
    }

    /**
     * View payload builder, public so the feature test can assert the exact
     * cells that feed the PDF (PDF streams are compressed binary).
     *
     * @return array{view: array<string, mixed>, filename: string}
     */
    public static function build(TenantContext $ctx, string $idsParam, string $idParam = 'default', string $bidParam = 'default'): array
    {
        // Requested entries: `ids` CSV mirrors legacy's POST id[] array,
        // `id` the single-entry mode (:21-27, :139).
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));
        $singleId = ctype_digit($idParam) ? (int) $idParam : 0;

        $bid = ctype_digit($bidParam) ? (int) $bidParam : 0;
        if ($bid === 0 && $singleId !== 0) {
            $bid = (int) (DB::table('brewing')->where('id', $singleId)->value('brewBrewerID') ?? 0);
        }
        if ($bid === 0 && $ids !== []) {
            $bid = (int) (DB::table('brewing')->whereIn('id', $ids)->value('brewBrewerID') ?? 0);
        }

        $contestName = $ctx->contestStr('contestName') ?? '';

        if ($bid === 0) {
            return [
                'view' => [
                    'contest' => $contestName,
                    'info' => 'No user recognized.',
                    'barcodeQr' => false,
                    'large' => false,
                    'perPage' => 12,
                    'cells' => [],
                ],
                'filename' => 'bottle_labels.pdf',
            ];
        }

        $brewer = (array) DB::table('brewer')->where('uid', $bid)->first();

        // Legacy reads ALL the brewer's entries then filters in the loop
        // (:37-41, :139) — order preserved by id.
        $entries = DB::table('brewing')->where('brewBrewerID', $bid)->orderBy('id')->get()
            ->filter(fn ($e) => $ids !== [] ? in_array($e->id, $ids) : ($singleId === 0 || $e->id === $singleId));

        $form = (int) $ctx->prefsStr('prefsEntryForm');

        // Legacy :93-99 membership arrays, verbatim.
        $anon = in_array($form, [1, 2, 3, 4, 6, 8, 9, 0]);
        $standard = in_array($form, [5, 7, 10, 11]);
        $barcodeQr = in_array($form, [1, 3, 5, 6, 0, 11]);
        $largeNum = in_array($form, [1, 2, 3, 4]);
        $largeText = in_array($form, [10, 11]);
        $useJudgingNumber = in_array($form, [3, 4, 9, 0]);

        // Legacy :110-111 — jPrefsBottleNum or default 3.
        $copies = max(1, (int) $ctx->judgingStr('jPrefsBottleNum') ?: 3);
        $styleSet = (string) $ctx->prefsStr('prefsStyleSet');
        $proEdition = (int) $ctx->prefsStr('prefsProEdition') === 1;

        $phone = (($brewer['brewerCountry'] ?? '') === 'United States')
            ? self::formatPhoneUs((string) ($brewer['brewerPhone1'] ?? ''))
            : (string) ($brewer['brewerPhone1'] ?? '');

        $cells = [];
        foreach ($entries as $entry) {
            // Mead/cider required-info lines share the label's length budget.
            $meadCider = array_values(array_filter([
                (string) $entry->brewMead1, (string) $entry->brewMead2, (string) $entry->brewMead3,
            ], fn (string $v): bool => $v !== ''));

            // Barcode value: judging number or entry number per variant.
            $code = sprintf('%06d', $useJudgingNumber ? (int) $entry->brewJudgingNumber : (int) $entry->id);

            // Legacy :155-159 — QR payload "$base_url/qr.php?id=N"; the
            // port encodes the port's /qr?id=N equivalent on the request host.
            $qrSvg = self::qrSvg(url('/qr?id='.(int) $entry->id));

            for ($i = 0; $i < $copies; $i++) {
                $cells[] = [
                    'code' => $code,
                    'qrSvg' => $qrSvg,
                    'styleSet' => $styleSet,
                    'largeNum' => $largeNum,
                    'largeText' => $largeText,
                    'barcodeQr' => $barcodeQr,
                    'anon' => $anon,
                    'standard' => $standard,
                    'entryName' => mb_substr((string) $entry->brewName, 0, 30),
                    // AABC drops leading zeros as cat.sub (:191/:211); other
                    // sets print raw cat.sub (:196/:212).
                    'category' => $styleSet === 'AABC'
                        ? ltrim((string) $entry->brewCategory, '0').'.'.ltrim((string) $entry->brewSubCategory, '0')
                        : $entry->brewCategory.$entry->brewSubCategory,
                    'styleName' => mb_substr((string) $entry->brewStyle, 0, 25),
                    // BJCP 2021/2025 mead category 02A wording quirk (:238).
                    'infoLabel' => in_array($styleSet, ['BJCP2021', 'BJCP2025'])
                        && $entry->brewCategorySort === '02' && $entry->brewSubCategory === 'A'
                        ? 'Regional Variation:' : 'Required Info:',
                    'requiredInfo' => self::requiredInfo($entry, $meadCider),
                    'meadCider' => implode('   ', $meadCider),
                    'contact' => $standard ? self::contactLines($brewer, $proEdition, $phone) : [],
                ];
            }
        }

        return [
            'view' => [
                'contest' => $contestName,
                'info' => '',
                'barcodeQr' => $barcodeQr,
                'large' => $largeNum || $largeText,
                // Legacy :119-127 — 9 labels/page with barcode/QR, else 12.
                'perPage' => $barcodeQr ? 9 : 12,
                'cells' => $cells,
            ],
            'filename' => str_replace(' ', '_', $contestName).'_Entry_Bottle_Labels.pdf',
        ];
    }

    /** Legacy qRClas::qRCreate equivalent: QR image as an inline SVG string. */
    private static function qrSvg(string $payload): string
    {
        $renderer = new ImageRenderer(new RendererStyle(150), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($payload);
    }

    /**
     * brewInfo cell content: "^" stored separators become spaces (:231),
     * truncated shorter when mead/cider info shares the label (:232-233).
     *
     * @param  list<string>  $meadCider
     */
    private static function requiredInfo(\stdClass $entry, array $meadCider): string
    {
        if ((string) $entry->brewInfo === '') {
            return '';
        }

        $limit = $meadCider !== [] ? 150 : 200;

        return mb_substr(str_replace('^', ' ', (string) $entry->brewInfo), 0, $limit);
    }

    /**
     * Standard-label contact block (:249-263).
     *
     * @param  array<string, mixed>  $brewer
     * @return list<string>
     */
    private static function contactLines(array $brewer, bool $proEdition, string $phone): array
    {
        $name = trim(($brewer['brewerFirstName'] ?? '').' '.($brewer['brewerLastName'] ?? ''));

        return [
            $proEdition
                ? (($brewer['brewerBreweryName'] ?? '')."\nContact: ".$name)
                : $name,
            (string) ($brewer['brewerEmail'] ?? ''),
            $phone,
        ];
    }

    /** Legacy format_phone_us common.lib.php:3495 (numeric formats). */
    private static function formatPhoneUs(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return match (strlen($digits)) {
            7 => (string) preg_replace('/(\d{3})(\d{4})/', '$1-$2', $digits),
            10 => (string) preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $digits),
            11 => (string) preg_replace('/(\d{1})(\d{3})(\d{3})(\d{4})/', '$1($2) $3-$4', $digits),
            default => $phone,
        };
    }
}
