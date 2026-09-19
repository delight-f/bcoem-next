<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Styles\StyleSets;
use App\Support\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts named entries onto the style they were actually brewed to.
 *
 * The baseline dump gave "New Zealand-Style India Pale Ale" the same code as
 * "New Zealand-Style Pale Ale" (both 06-182), and the entry form writes the
 * resolved style's NAME onto the entry, so an entry saved as the India Pale
 * Ale was stored — and printed, pulled and judged — as the Pale Ale. The
 * catalog is fixed now (2026_09_19_140000), but the entries already written
 * keep the wrong code and name, and which of the two a given entry was cannot
 * be read back off the row: both styles stored the same code and the same
 * name. Only the organiser knows, so this takes the entry ids and the style
 * they should carry.
 *
 * The columns written are the ones the entry form and the backoffice edit
 * write: brewStyle, brewCategory, brewCategorySort, brewSubCategory and
 * brewStyleType.
 *
 * Dry run by default; pass --apply to write. Idempotent: an entry already on
 * the named style is reported as such and left alone.
 */
final class AssignEntryStyle extends Command
{
    protected $signature = 'bcoem:assign-entry-style
                            {style : Catalog code to move the entries to, e.g. 06-183}
                            {entries* : Entry ids (brewing.id), one or more}
                            {--set= : Style set to read the code in (default: the site\'s own)}
                            {--apply : Write the corrections (default: dry run)}';

    protected $description = 'Put named entries onto the style they were actually brewed to.';

    public function handle(): int
    {
        $code = (string) $this->argument('style');
        $apply = (bool) $this->option('apply');

        [$cat, $sub] = array_pad(explode('-', $code, 2), 2, '');

        if ($cat === '' || $sub === '' || str_contains($sub, '-')) {
            $this->error("A style is a catalog code like 06-183 — got '".$code."'.");

            return self::FAILURE;
        }

        $set = $this->option('set');
        if (! is_string($set) || $set === '') {
            $set = TenantContext::load()->prefsStr('prefsStyleSet') ?? '';
        }

        $style = StyleSets::findStyle($set, self::categorySort($cat), $sub);
        if ($style === null) {
            // An install that has never saved the tab carries no set at all.
            $label = StyleSets::label($set) !== '' ? StyleSets::label($set) : 'active';
            $this->error("No style '".$code."' in the ".$label.' set — check the style list in the admin.');

            return self::FAILURE;
        }

        $ids = array_map(intval(...), (array) $this->argument('entries'));
        $rows = DB::table('brewing')
            ->whereIn('id', $ids)
            ->get(['id', 'brewStyle', 'brewCategorySort', 'brewSubCategory', 'brewReceived']);

        // Refuse the whole call rather than repair some of the named entries.
        $missing = array_values(array_diff($ids, $rows->pluck('id')->map(intval(...))->all()));
        if ($missing !== []) {
            $this->error('No such entr'.(count($missing) === 1 ? 'y' : 'ies').': '.implode(', ', $missing).'. Nothing written.');

            return self::FAILURE;
        }

        $this->line($apply
            ? 'Applying the style correction.'
            : 'Dry run — nothing will be written. Re-run with --apply to write.');

        $fields = [
            'brewStyle' => (string) $style->brewStyle,
            'brewCategory' => self::blankToNull(ltrim($cat, '0')),
            'brewCategorySort' => self::categorySort($cat),
            'brewSubCategory' => $sub,
            'brewStyleType' => $style->brewStyleType ?? null,
        ];

        $changed = 0;
        $received = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            $unchanged = (string) $row->brewStyle === $fields['brewStyle']
                && (string) $row->brewCategorySort === $fields['brewCategorySort']
                && (string) $row->brewSubCategory === $fields['brewSubCategory'];

            if ($unchanged) {
                $this->line(sprintf('id=%d already on %s', $id, $fields['brewStyle']));

                continue;
            }

            $changed++;

            if ((int) $row->brewReceived === 1) {
                $received[] = (string) $id;
            }

            $this->line(sprintf(
                'id=%d %s: %s (%s-%s) => %s (%s-%s)',
                $id,
                $apply ? 'corrected' : 'would change',
                (string) $row->brewStyle,
                (string) $row->brewCategorySort,
                (string) $row->brewSubCategory,
                $fields['brewStyle'],
                $fields['brewCategorySort'],
                $sub,
            ));

            if ($apply) {
                DB::table('brewing')->where('id', $id)->update(
                    $fields + ['brewUpdated' => now()->format('Y-m-d H:i:s')],
                );
            }
        }

        if ($changed === 0) {
            $this->line('Nothing to do: every entry is already on that style.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d entr%s %s.', $changed, $changed === 1 ? 'y' : 'ies', $apply ? 'corrected' : 'would change'));

        if ($received !== []) {
            $this->warn('Already received: '.implode(', ', $received).'. Their category, judging table and pull sheet move with them.');
        }

        return self::SUCCESS;
    }

    /** brewCategory storage: the bare category, unpadded. */
    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** Entry-form storage for brewCategorySort: numeric < 10 pads to '0X', alpha never. */
    private static function categorySort(string $cat): string
    {
        $cat = ctype_digit($cat) ? ltrim($cat, '0') : $cat;

        return ctype_digit($cat) && (int) $cat < 10 ? '0'.$cat : $cat;
    }
}
