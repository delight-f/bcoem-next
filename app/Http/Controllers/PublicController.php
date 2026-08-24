<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Results\ResultsRepository;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Read-only public surface (Phase 2 / Slice A).
 *
 * Faithful to the live fork's dispatcher: the landing page at "/" carries
 * the info sections (at-a-glance, rules/entry windows, entry info,
 * volunteers, sponsors, contacts) and — after judging concludes and the
 * winner delay passes — the results block. `past-winners/{filter}` reads
 * archived sibling tables. `list` is account-gated exactly like legacy.
 */
final class PublicController extends Controller
{
    public function home(): View
    {
        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        $langLong = str_starts_with((string) $ctx->prefsStr('prefsLanguage'), 'en-');

        // Results gate: every judging session in the past, winners enabled,
        // reveal delay strictly passed (ledger #4).
        $resultsVisible = $windows->futureJudgingSessions === 0
            && $windows->registration === WindowState::After
            && $ctx->prefsStr('prefsDisplayWinners') === 'Y'
            && $now > (int) ($ctx->prefsStr('prefsWinnerDelay') ?: 0);

        // Landing salutation flips once judging is over (index.pub.php).
        $judgingOver = $windows->futureJudgingSessions === 0
            && $windows->registration === WindowState::After;
        $judgedEntries = (int) DB::table('judging_scores')->distinct()->count('eid');
        $participants = (int) DB::table('brewer')->count();
        $salutation = self::t('site.salutation_interest').' '.e($ctx->contestStr('contestName'))
            .' '.self::t('site.organized_by').' '.e($ctx->contestStr('contestHost'))
            .($ctx->contestStr('contestHostLocation') ? ', '.e($ctx->contestStr('contestHostLocation')) : '').'.';

        return view('public.home', [
            'ctx' => $ctx,
            'windows' => $windows,
            'longDates' => $langLong,
            'resultsVisible' => $resultsVisible,
            'glance' => $this->glanceCards($ctx, $windows, $langLong),
            'heroImage' => self::heroImage($ctx),
            'salutation' => $salutation,
            'salutationCounts' => $judgingOver
                ? ['judged' => $judgedEntries, 'participants' => $participants]
                : null,
            'archives' => ResultsRepository::archives(),
        ]);
    }

    public function pastWinners(string $filter): View
    {
        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);
        $langLong = str_starts_with((string) $ctx->prefsStr('prefsLanguage'), 'en-');
        $clean = preg_replace('/[^a-zA-Z0-9]+/', '', $filter);

        // Legacy renders the full landing for past-winners (default.sec.php
        // serves both sections); results read the archive tables and come
        // back empty when the suffix names no archived data.
        return view('public.home', [
            'resultsSuffix' => $clean === '' ? null : $clean,
            'ctx' => $ctx,
            'windows' => $windows,
            'longDates' => $langLong,
            'resultsVisible' => true,
            'glance' => [],
            'heroImage' => null,
            'salutation' => self::t('site.past_winners').' &ndash; '.$clean,
            'salutationCounts' => null,
            'suffix' => $clean === '' ? null : $clean,
            'archives' => ResultsRepository::archives(),
        ]);
    }

    /** Account-gated in legacy: anonymous requests bounce to a login nudge. */
    public function list(): never
    {
        redirect('/?msg=99')->send();
        exit;
    }

    /**
     * Build the at-a-glance card deck. Card shape matches the partial:
     * title + pill + pre-rendered body HTML (legacy renders these bodies
     * as inline HTML lists too).
     */
    private static function t(string $key): string
    {
        $v = __($key);

        return is_string($v) ? $v : '';
    }

    /**
     * @return list<array{id: string, title: string, pill: string, body: string}>
     */
    private function glanceCards(TenantContext $ctx, Windows $w, bool $longDates): array
    {
        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');
        $style = $longDates ? 'long' : 'short';

        $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $style) ?? self::t('site.not_set');

        $totalEntries = (int) DB::table('brewing')->count();
        $paidEntries = (int) DB::table('brewing')->where('brewPaid', 1)->count();

        $cards = [];

        $entryBody = '<ul class="list-unstyled">'
            .'<li><strong>'.self::t('site.total').'</strong> &ndash; '.$totalEntries
            .(self::numericOrNull($ctx->prefsStr('prefsEntryLimit')) !== null
                ? ' / '.self::numericOrNull($ctx->prefsStr('prefsEntryLimit')) : '').'</li>'
            .'<li><strong>'.self::t('site.paid').'</strong> &ndash; '.$paidEntries
            .(self::numericOrNull($ctx->prefsStr('prefsEntryLimitPaid')) !== null
                ? ' / '.self::numericOrNull($ctx->prefsStr('prefsEntryLimitPaid')) : '').'</li>';

        if ($w->entry === WindowState::Before) {
            $entryBody .= '<li>'.self::t('site.opens').' '.$fmt($ctx->contestEpoch('contestEntryOpen')).'</li>';
        } elseif ($w->entry === WindowState::After) {
            $entryBody .= '<li>'.self::t('site.closed').' '.$fmt($ctx->contestEpoch('contestEntryDeadline')).'</li>';
        }
        $entryBody .= '</ul>';

        $cards[] = ['id' => 'entries', 'title' => self::t('site.entries'), 'pill' => self::t('site.status'), 'body' => $entryBody];

        $windowCard = function (string $id, string $title, WindowState $state, ?string $openAt, ?string $closeAt, bool $capReached = false): array {
            $body = '<ul class="list-unstyled">'
                .'<li><strong>'.self::t('site.open_label').'</strong> &ndash; '.($openAt ?? self::t('site.not_set')).'</li>'
                .'<li><strong>'.self::t('site.close_label').'</strong> &ndash; '.($closeAt ?? self::t('site.not_set')).'</li>'
                .($capReached ? '<li>'.self::t('site.cap_reached').'</li>' : '')
                .'</ul>';
            $pill = match ($state) {
                WindowState::Open => self::t('site.state_open'),
                WindowState::After => self::t('site.state_closed'),
                default => self::t('site.state_before'),
            };

            return compact('id', 'title', 'pill', 'body');
        };

        $cards[] = $windowCard('account-registration', self::t('site.account_registration'), $w->registration,
            $fmt($ctx->contestEpoch('contestRegistrationOpen')), $fmt($ctx->contestEpoch('contestRegistrationDeadline')));
        $cards[] = $windowCard('judge-registration', self::t('site.judge_registration'), $w->judge,
            $fmt($ctx->contestEpoch('contestJudgeOpen')), $fmt($ctx->contestEpoch('contestJudgeDeadline')), $w->judgeCapReached);
        $cards[] = $windowCard('steward-registration', self::t('site.steward_registration'), $w->judge,
            $fmt($ctx->contestEpoch('contestJudgeOpen')), $fmt($ctx->contestEpoch('contestJudgeDeadline')), $w->stewardCapReached);
        $cards[] = $windowCard('drop-off', self::t('site.drop_off'), $w->dropoff,
            $fmt($ctx->contestEpoch('contestDropoffOpen')), $fmt($ctx->contestEpoch('contestDropoffDeadline')));

        if ((int) $ctx->prefsStr('prefsShipping') === 1 && $ctx->contestStr('contestShippingAddress')) {
            $cards[] = $windowCard('shipping', self::t('site.shipping'), $w->shipping,
                $fmt($ctx->contestEpoch('contestShippingOpen')), $fmt($ctx->contestEpoch('contestShippingDeadline')));
        }

        return $cards;
    }

    /**
     * Random hero image appropriate for the tenant's enabled style types.
     * Fallback set mirrors the live install; prefs-driven custom images
     * land with the admin surface in a later phase.
     *
     * @return string image filename under public/images/
     */
    private static function heroImage(TenantContext $ctx): string
    {
        $poolByType = [
            0 => ['misc-cropped-bottles_3000x500.webp', 'misc-brussels-bottles_3000x500.webp', 'misc-plzen-fermenters_3000x500.webp', 'misc-bottles_3000x500.webp'],
            1 => ['beer-barley-malt_3000x500.webp', 'beer-brussels-barrels_3000x500.webp', 'beer-hop-cones_3000x500.webp', 'beer-kegs_3000x500.webp', 'beer-munich-mugs_3000x500.webp', 'beer-on-bar_3000x500.webp'],
            2 => ['cider-bottles_3000x500.webp'],
            3 => ['mead-bottles_3000x500.webp'],
        ];

        $selected = json_decode((string) $ctx->prefsStr('prefsSelectedStyles'), true);
        $types = [0];
        if (is_array($selected)) {
            foreach ($selected as $styleRow) {
                if (isset($styleRow['brewStyleType'])) {
                    $types[] = (int) $styleRow['brewStyleType'];
                }
            }
        }

        $pool = [];
        foreach (array_unique($types) as $type) {
            foreach ($poolByType[$type] ?? [] as $image) {
                $pool[] = $image;
            }
        }

        return $pool === [] ? 'misc-cropped-bottles_3000x500.webp' : $pool[random_int(0, count($pool) - 1)];
    }

    private static function numericOrNull(?string $v): ?int
    {
        return ($v !== null && $v !== '' && is_numeric($v)) ? (int) $v : null;
    }
}
