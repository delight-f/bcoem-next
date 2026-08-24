<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Entries\EntryGates;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
    public function home(): View|RedirectResponse
    {
        // Legacy served login as ?section=login (plus the reset flow via
        // go=password&action=forgot|reset-password); the standalone build
        // uses the clean /login URL — legacy query shapes redirect there.
        if (request('section') === 'login') {
            return redirect('/login');
        }

        // Legacy served registration as ?section=register&go={entrant|judge|
        // steward}; the clean /register URL is canonical.
        if (request('section') === 'register') {
            $go = (string) (request('go') ?: 'entrant');

            return redirect('/register/'.$go);
        }

        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);

        $langLong = str_starts_with((string) $ctx->prefsStr('prefsLanguage'), 'en-');

        // Landing state machine (index.pub.php + default.pub.php): once every
        // judging session is past AND registration/entry are closed, legacy
        // always shows the "thanks to all who participated" blurb; winners
        // replace the at-a-glance cards only when enabled AND the reveal
        // delay has strictly passed (winners-display ledger #4).
        $allClosed = $windows->futureJudgingSessions === 0
            && $windows->registration === WindowState::After
            && $windows->entry === WindowState::After;
        $displayWinners = $ctx->prefsStr('prefsDisplayWinners') === 'Y';
        $delayPassed = $now > (int) ($ctx->prefsStr('prefsWinnerDelay') ?: 0);

        $resultsVisible = $allClosed && $displayWinners && $delayPassed;
        $cardsVisible = ! $allClosed || ($displayWinners && ! $delayPassed);

        // Legacy nav hides Rules/Volunteers once judging has started
        // (nav.pub.php), and shows Entry Info only while future sessions
        // remain; the sponsors link mirrors the section gate.
        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;

        $salutation = self::t('site.salutation_interest').' '.e($ctx->contestStr('contestName'))
            .' '.self::t('site.organized_by').' '.e($ctx->contestStr('contestHost'))
            .($ctx->contestStr('contestHostLocation') ? ', '.e($ctx->contestStr('contestHostLocation')) : '').'.';

        return view('public.home', [
            'ctx' => $ctx,
            'windows' => $windows,
            'longDates' => $langLong,
            'resultsVisible' => $resultsVisible,
            'cardsVisible' => $cardsVisible,
            // judge_closed.pub.php: received entries + registered participants.
            'blurbCounts' => $allClosed
                ? [
                    'received' => (int) DB::table('brewing')->where('brewReceived', 1)->count(),
                    'participants' => (int) DB::table('brewer')->count(),
                ]
                : null,
            'judgingStarted' => $judgingStarted,
            'sponsorsVisible' => $sponsorsVisible,
            'glance' => $this->glanceCards($ctx, $windows, $langLong),
            'heroImage' => self::heroImage($ctx),
            'salutation' => $salutation,
            'archives' => ResultsRepository::archives(),
        ]);
    }

    public function pastWinners(string $filter): View|RedirectResponse
    {
        // Legacy (constants_post_lang.inc.php): a suffix with no
        // displayable archive (missing row, winners disabled, absent
        // sibling tables, or zero archived scores) bounces to ?msg=8 —
        // the landing with "Archived data is not available."
        if (! ResultsRepository::archiveDisplayable($filter)) {
            return redirect('/?msg=8');
        }

        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);
        $langLong = str_starts_with((string) $ctx->prefsStr('prefsLanguage'), 'en-');
        $clean = preg_replace('/[^a-zA-Z0-9]+/', '', $filter);

        // Displayable archive: legacy renders the past-winners section
        // reading the archived sibling tables.
        return view('public.home', [
            'resultsSuffix' => $clean === '' ? null : $clean,
            'ctx' => $ctx,
            'windows' => $windows,
            'longDates' => $langLong,
            'resultsVisible' => true,
            'cardsVisible' => false,
            'blurbCounts' => null,
            'judgingStarted' => false,
            'sponsorsVisible' => false,
            'glance' => [],
            'heroImage' => null,
            'salutation' => self::t('site.past_winners').' &ndash; '.$clean,
            'suffix' => $clean === '' ? null : $clean,
            'archives' => ResultsRepository::archives(),
        ]);
    }

    /**
     * Account-gated in legacy: anonymous requests bounce to a login nudge;
     * authenticated users get the account surface (legacy
     * list.pub.php): brewer info block, at-a-glance sidebar, and the
     * entries table with edit/delete gating (ticket 08).
     */
    public function list(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());
        $now = time();
        $langLong = str_starts_with((string) $ctx->prefsStr('prefsLanguage'), 'en-');

        $entryWindowOpen = $windows->entry === WindowState::Open;
        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $editDeadline = Windows::entryEditDeadline($ctx);
        $fee = (float) ($ctx->contestStr('contestEntryFee') ?? 0);

        $rows = [];

        foreach (DB::table('brewing')
            ->where('brewBrewerID', (int) Auth::id())
            ->orderBy('brewCategorySort')
            ->orderBy('brewSubCategory')
            ->get() as $entry) {
            $rows[] = [
                'entry' => $entry,
                'canEdit' => EntryGates::edit((int) $entry->brewReceived, $entryWindowOpen, $editDeadline, $now, $judgingStarted),
                'canDelete' => EntryGates::delete((int) $entry->brewReceived, (int) $entry->brewPaid, $entryWindowOpen, $editDeadline, $now, $judgingStarted, $fee),
            ];
        }

        $brewer = DB::table('brewer')->where('uid', (int) Auth::id())->first();

        return view('public.account', [
            'ctx' => $ctx,
            'windows' => $windows,
            'judgingStarted' => $judgingStarted,
            'info' => BrewerForm2Controller::infoData($ctx),
            'glance' => $this->listGlanceCards($ctx, $windows, $langLong, $brewer),
            'rows' => $rows,
        ]);
    }

    /**
     * At-a-glance deck for the account page — the legacy `section == list`
     * branch of at-a-glance.pub.php:505-511: judging card only for
     * judge/steward volunteers; entry-registration, drop-off and shipping
     * cards gated by $at_a_glance_entry_info (FALSE only for pro-edition
     * judges/stewards).
     *
     * @return list<array{id: string, title: string, pill: string, body: string}>
     */
    private function listGlanceCards(TenantContext $ctx, Windows $w, bool $longDates, ?object $brewer): array
    {
        $isVolunteer = $brewer !== null
            && (($brewer->brewerJudge ?? '') === 'Y' || ($brewer->brewerSteward ?? '') === 'Y');
        $entryInfo = ! ((int) $ctx->prefsStr('prefsProEdition') === 1 && $isVolunteer);

        $cards = [];

        if ($isVolunteer) {
            $state = $w->judgingState(time(), $ctx->contestEpoch('contestAwardsLocDate'));
            $tz = $ctx->prefsStr('prefsTimeZone');
            $df = $ctx->prefsStr('prefsDateFormat');
            $tf = $ctx->prefsStr('prefsTimeFormat');
            $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $longDates ? 'long' : 'short') ?? self::t('site.not_set');

            $body = '<ul class="list-unstyled"><li><strong>'.self::t('site.start').'</strong> &ndash; '.$fmt($w->firstJudgingDate).'</li>';
            if ($w->lastJudgingDate !== null) {
                $body .= '<li><strong>'.self::t('site.end').'</strong> &ndash; '.$fmt($w->lastJudgingDate).'</li>';
            }
            $body .= '</ul>';

            $cards[] = [
                'id' => 'judging',
                'title' => self::t('site.judging'),
                'pill' => match ($state) {
                    1 => self::t('site.judging_in_progress'),
                    2 => self::t('site.judging_concluded'),
                    default => self::t('site.judging_not_started'),
                },
                'body' => $body,
            ];
        }

        if ($entryInfo) {
            $windowCard = function (string $id, string $title, WindowState $state, ?string $openAt, ?string $closeAt): array {
                $body = '<ul class="list-unstyled">'
                    .'<li><strong>'.self::t('site.open_label').'</strong> &ndash; '.($openAt ?? self::t('site.not_set')).'</li>'
                    .'<li><strong>'.self::t('site.close_label').'</strong> &ndash; '.($closeAt ?? self::t('site.not_set')).'</li>'
                    .'</ul>';
                $pill = match ($state) {
                    WindowState::Open => self::t('site.state_open'),
                    WindowState::After => self::t('site.state_closed'),
                    default => self::t('site.state_before'),
                };

                return compact('id', 'title', 'pill', 'body');
            };

            $tz = $ctx->prefsStr('prefsTimeZone');
            $df = $ctx->prefsStr('prefsDateFormat');
            $tf = $ctx->prefsStr('prefsTimeFormat');
            $style = $longDates ? 'long' : 'short';
            $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $style) ?? self::t('site.not_set');

            $cards[] = $windowCard('entry-registration', self::t('site.entries_registration'), $w->entry,
                $fmt($ctx->contestEpoch('contestEntryOpen')), $fmt($ctx->contestEpoch('contestEntryDeadline')));
            $cards[] = $windowCard('drop-off', self::t('site.drop_off'), $w->dropoff,
                $fmt($ctx->contestEpoch('contestDropoffOpen')), $fmt($ctx->contestEpoch('contestDropoffDeadline')));

            if ((int) $ctx->prefsStr('prefsShipping') === 1 && $ctx->contestStr('contestShippingAddress')) {
                $cards[] = $windowCard('shipping', self::t('site.shipping'), $w->shipping,
                    $fmt($ctx->contestEpoch('contestShippingOpen')), $fmt($ctx->contestEpoch('contestShippingDeadline')));
            }
        }

        return $cards;
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
