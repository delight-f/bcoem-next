<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ContactMail;
use App\Support\Entries\EntryGates;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\ContestRules;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
    public function home(Request $request): View|RedirectResponse
    {
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

        // index.pub.php: "Welcome {name}!" lead when logged in, then the
        // fw-light interest line with <small> wrapper and host website link.
        $salutation = '';
        if ($request->user() !== null) {
            $firstName = DB::table('brewer')->where('uid', (int) $request->user()->id)->value('brewerFirstName') ?? '';
            $salutation .= '<p class="landing-page-salutation">'.self::t('site.welcome').' '.e($firstName).'!</p>';
        }
        $host = e($ctx->contestStr('contestHost') ?? '');
        $website = $ctx->contestStr('contestHostWebsite');
        $hostHtml = $website !== null && $website !== ''
            ? '<a class="hide-loader" href="'.e($website).'" target="_blank">'.$host.'</a>'
            : $host;
        $salutation .= '<p class="lead landing-page-salutation fw-light"><small>'
            .self::t('site.salutation_interest').' '.e($ctx->contestStr('contestName'))
            .' '.self::t('site.organized_by').' '.$hostHtml
            .($ctx->contestStr('contestHostLocation') ? ', '.e($ctx->contestStr('contestHostLocation')) : '')
            .'.</small></p>';

        // alerts.pub.php stacked info alerts ("For Your Information"):
        // logged-out visitors on the landing page with no msg param.
        $fyiAlerts = [];
        if ($request->user() === null) {
            $tz = $ctx->prefsStr('prefsTimeZone');
            $df = $ctx->prefsStr('prefsDateFormat');
            $tf = $ctx->prefsStr('prefsTimeFormat');
            $long = fn (?int $epoch): string => (string) DateFmt::dateTime($epoch, $tz, $df, $tf, 'long');
            $reg = $windows->registration;
            $entry = $windows->entry;
            $judge = $windows->judge;

            if ($reg === WindowState::Before) {
                $fyiAlerts[] = '<strong>'.self::t('site.fyi_reg_will_open').' '.$long($ctx->contestEpoch('contestRegistrationOpen')).'.</strong> '.self::t('site.fyi_return_register');
            }
            if ($entry === WindowState::Before) {
                $fyiAlerts[] = '<strong>'.self::t('site.fyi_entry_will_open').' '.$long($ctx->contestEpoch('contestEntryOpen')).'.</strong> '.self::t('site.fyi_return_entries');
            }
            if ($reg === WindowState::After && $judge === WindowState::Before) {
                $fyiAlerts[] = '<strong>'.self::t('site.fyi_js_will_open').' '.$long($ctx->contestEpoch('contestJudgeOpen')).'.</strong> '.self::t('site.fyi_return_judge');
            }
            if ($reg === WindowState::Open && $entry === WindowState::Open && (int) $ctx->prefsStr('prefsEntryLimit') > 0) {
                $fyiAlerts[] = '<strong>'.self::t('site.fyi_entry_open').'</strong> '
                    .'A total of '.(int) DB::table('brewing')->count().' entries have been added to the system as of '
                    .DateFmt::dateTime($now, $tz, $df, $tf, 'short').'. '
                    .self::t('site.fyi_reg_will_close').' '.$long($ctx->contestEpoch('contestRegistrationDeadline')).'.';
            }
            if (in_array($reg, [WindowState::Before, WindowState::After], true) && $judge === WindowState::Open) {
                $roles = match ([$windows->judgeCapReached, $windows->stewardCapReached]) {
                    [true, false] => 'Steward',
                    [false, true] => 'Judge',
                    default => 'Judge or steward',
                };
                $fyiAlerts[] = '<strong>'.__('site.fyi_js_open', ['roles' => $roles]).'</strong> '
                    .__('site.fyi_js_close', ['roles' => strtolower($roles), 'date' => $long($ctx->contestEpoch('contestJudgeDeadline'))]);
            }
        }

        return view('public.home', [
            'fyiAlerts' => $fyiAlerts,
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
            'sponsors' => DB::table('sponsors')->orderBy('id')->get(),
            'logos' => $ctx->prefsStr('prefsSponsorLogos') === 'Y',
            'logoFor' => static function (object $sponsor): string {
                $image = (string) $sponsor->sponsorImage;
                if ($image !== '' && is_file(public_path('user_images/'.$image))) {
                    return asset('user_images/'.$image);
                }

                return asset('images/no_image.png');
            },
            'glance' => $this->glanceCards($ctx, $windows, $langLong, $request->user() !== null),
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

        return view('public.account', $this->accountData());
    }

    /**
     * Legacy ?section=volunteers (volunteers.sec.php): public volunteers
     * page. Faithful to the legacy branches — judge-window blurb,
     * non-judging staff-session list, and the contestVolunteers body.
     */
    public function volunteers(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());
        $now = time();

        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');

        // judging_locations.db.php:56-60 — staff sessions are judgingLocType 2.
        $staffLocations = DB::table('judging_locations')
            ->where('judgingLocType', 2)
            ->orderBy('judgingDate')
            ->orderBy('judgingLocName')
            ->get(['judgingLocName', 'judgingDate'])
            ->map(static fn (object $loc): array => [
                'name' => (string) $loc->judgingLocName,
                'when' => DateFmt::dateTime($loc->judgingDate, $tz, $df, $tf, 'long') ?? '',
            ])
            ->all();

        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;

        return view('public.volunteers', [
            'ctx' => $ctx,
            'windows' => $windows,
            'judgingStarted' => $judgingStarted,
            'sponsorsVisible' => $sponsorsVisible,
            // Legacy volunteers.sec.php: judge_window_open > 0 (Open or After).
            'judgeOpen' => $windows->judge !== WindowState::Before,
            // Legacy: registration_open < 2 gates the staff block.
            'registrationClosed' => $windows->registration === WindowState::After,
            'judgeOpenWhen' => DateFmt::dateTime($ctx->contestEpoch('contestJudgeOpen'), $tz, $df, $tf, 'long') ?? null,
            'salutation' => $this->publicSalutation($request, $ctx),
            'staffLocations' => $staffLocations,
        ]);
    }

    /**
     * Legacy ?section=contact (contact.sec.php): public contact page with
     * the officials list (prefsContact=N) or the message form (mode Y).
     */
    public function contact(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());
        $now = time();
        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;

        return view('public.contact', [
            'ctx' => $ctx,
            'mode' => $ctx->prefsStr('prefsContact'),
            'contacts' => DB::table('contacts')->orderBy('id')->get(),
            'judgingStarted' => $judgingStarted,
            'sponsorsVisible' => $sponsorsVisible,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'salutation' => $this->publicSalutation($request, $ctx),
        ]);
    }

    /**
     * Legacy ?section=sponsors (sections/sponsors.sec.php): standalone
     * sponsors page. Same gate as the landing sponsors section —
     * prefsSponsors=Y and at least one sponsor row.
     */
    public function sponsors(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();
        $windows = Windows::derive($ctx, time());
        $now = time();

        $sponsorsVisible = $ctx->prefsStr('prefsSponsors') === 'Y'
            && (int) DB::table('sponsors')->count() > 0;
        if (! $sponsorsVisible) {
            return redirect('/');
        }

        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $logos = $ctx->prefsStr('prefsSponsorLogos') === 'Y';
        $logoFor = static function (object $sponsor) use ($logos): string {
            $image = (string) $sponsor->sponsorImage;
            if ($image !== '' && is_file(public_path('user_images/'.$image))) {
                return asset('user_images/'.$image);
            }

            return asset('images/no_image.png');
        };

        return view('public.sponsors', [
            'ctx' => $ctx,
            'sponsors' => DB::table('sponsors')->orderBy('id')->get(),
            'logos' => $logos,
            'logoFor' => $logoFor,
            'judgingStarted' => $judgingStarted,
            'sponsorsVisible' => $sponsorsVisible,
            'futureJudgingSessions' => $windows->futureJudgingSessions,
            'salutation' => $this->publicSalutation($request, $ctx),
        ]);
    }

    /**
     * Legacy includes/process.inc.php?dbTable=contacts&action=email: sends
     * the contact message to the chosen official. Laravel validation +
     * CSRF + rate-limit stand in for legacy's anti-spam/captcha layer
     * (ponytail: see ContactMail docblock).
     */
    public function contactStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'integer', 'exists:contacts,id'],
            'from_name' => ['required', 'string', 'max:120'],
            'from_email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contact = DB::table('contacts')->where('id', (int) $validated['to'])->first();
        if ($contact === null) {
            return back()->withInput()->withErrors(['to' => __('validation.exists')]);
        }

        $ctx = TenantContext::load();
        Mail::send(new ContactMail(
            toName: $contact->contactFirstName.' '.$contact->contactLastName,
            toEmail: $contact->contactEmail,
            fromName: $validated['from_name'],
            fromEmail: $validated['from_email'],
            subjectLine: $validated['subject'],
            body: $validated['message'],
            contestName: $ctx->contestStr('contestName'),
        ));

        return redirect()->route('contact')->with('contactSent', true);
    }

    /**
     * The landing-page salutation (Welcome {name} + interest line) that
     * legacy index.pub.php renders on every public page. Mirrors home()'s
     * inline build for the standalone volunteers/contact surfaces.
     */
    private function publicSalutation(Request $request, TenantContext $ctx): string
    {
        $salutation = '';
        if ($request->user() !== null) {
            $firstName = DB::table('brewer')->where('uid', (int) $request->user()->id)->value('brewerFirstName') ?? '';
            $salutation .= '<p class="landing-page-salutation">'.self::t('site.welcome').' '.e($firstName).'!</p>';
        }
        $host = e($ctx->contestStr('contestHost') ?? '');
        $website = $ctx->contestStr('contestHostWebsite');
        $hostHtml = $website !== null && $website !== ''
            ? '<a class="hide-loader" href="'.e($website).'" target="_blank">'.$host.'</a>'
            : $host;
        $salutation .= '<p class="lead landing-page-salutation fw-light"><small>'
            .self::t('site.salutation_interest').' '.e($ctx->contestStr('contestName'))
            .' '.self::t('site.organized_by').' '.$hostHtml
            .($ctx->contestStr('contestHostLocation') ? ', '.e($ctx->contestStr('contestHostLocation')) : '')
            .'.</small></p>';

        return $salutation;
    }
    /**
     * View payload for the pub/list.pub.php account surface, shared by
     * /list and /pay (index.pub.php renders the same block for both).
     *
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
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

        // pub/list.pub.php button stack states
        $unpaidCount = DB::table('brewing')
            ->where('brewBrewerID', (int) Auth::id())
            ->where('brewConfirmed', '1')
            ->where('brewPaid', '!=', 1)
            ->count();

        // pub/brewer_entries.pub.php $page_info1: confirmed/unpaid counts and
        // the fee total (unpaid confirmed entries × per-entry fee — the port
        // fee model shared with PayController; legacy total_fees nuances for
        // caps/discounts don't apply to this tenant shape).
        $confirmed = DB::table('brewing')
            ->where('brewBrewerID', (int) Auth::id())
            ->where('brewConfirmed', '1')
            ->count();

        return [
            'ctx' => $ctx,
            'windows' => $windows,
            'judgingStarted' => $judgingStarted,
            'info' => BrewerForm2Controller::infoData($ctx),
            'glance' => $this->listGlanceCards($ctx, $windows, $langLong, $brewer),
            'rows' => $rows,
            'addEntryShow' => $entryWindowOpen && ! $windows->compEntryLimitReached && ! $windows->compPaidEntryLimitReached,
            'payDisabled' => $unpaidCount === 0,
            'entryInfo' => [
                'bottles' => $ctx->judgingStr('jPrefsBottleNum'),
                'editDeadline' => DateFmt::dateTime(
                    Windows::entryEditDeadline($ctx), $ctx->prefsStr('prefsTimeZone'),
                    $ctx->prefsStr('prefsDateFormat'), $ctx->prefsStr('prefsTimeFormat'),
                ) ?? self::t('site.not_set'),
                'confirmed' => $confirmed,
                'unconfirmed' => max(0, count($rows) - $confirmed),
                'unpaidConfirmed' => $unpaidCount,
                'feesToPay' => $unpaidCount * $fee,
                'currency' => $ctx->currencySymbol(),
            ],
        ];
    }

    /**
     * At-a-glance deck for the account page — the legacy `section == list`
     * branch of at-a-glance.pub.php:505-511: judging card only for
     * judge/steward volunteers; entry-registration, drop-off and shipping
     * cards gated by $at_a_glance_entry_info (FALSE only for pro-edition
     * judges/stewards).
     *
     * @return list<array{id: string, title: string, pill: string, color: string, body: string, button: array{text: string, link: string}, buttonColor: string}>
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
                'color' => 'primary',
                'body' => $body,
                'button' => ['text' => '', 'link' => ''],
                'buttonColor' => 'primary',
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

                return [
                    'id' => $id, 'title' => $title, 'pill' => $pill,
                    'color' => match ($state) {
                        WindowState::Open => 'success',
                        WindowState::After => 'danger',
                        default => 'secondary',
                    },
                    'body' => $body,
                    'button' => ['text' => '', 'link' => ''],
                    'buttonColor' => 'secondary',
                ];
            };

            $tz = $ctx->prefsStr('prefsTimeZone');
            $df = $ctx->prefsStr('prefsDateFormat');
            $tf = $ctx->prefsStr('prefsTimeFormat');
            $style = 'short'; // at-a-glance.pub.php always renders numeric short dates
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
     * @return list<array{id: string, title: string, pill: string, color: string, icon: string, body: string, button: array{text: string, link: string}, buttonColor: string}>
     */
    private function glanceCards(TenantContext $ctx, Windows $w, bool $longDates, bool $loggedIn): array
    {
        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');
        $style = 'short'; // at-a-glance.pub.php always renders numeric short dates
        $now = time();

        $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $style) ?? self::t('site.not_set');

        // Legacy at-a-glance.pub.php CTA decision table: each open window's
        // card carries one button (empty link = disabled variant); closed
        // windows render the danger pill and no button.
        $buttonFor = function (WindowState $state, bool $capReached, callable $whenOpen): array {
            /** @var array{text: string, link: string} $button */
            $button = $state === WindowState::Open && ! $capReached
                ? $whenOpen()
                : ['text' => '', 'link' => ''];

            return $button;
        };

        // Window-card pill: legacy does not distinguish a not-yet-open window
        // from a closed one — any non-open state carries the danger "Closed"
        // pill. The countdown <li> placeholders stay empty (server-rendered);
        // the live countdown is a browser-only enhancement the port omits.
        $windowCard = function (
            string $id, string $title, WindowState $state, ?int $openEpoch, ?int $closeEpoch,
            bool $capReached = false, ?array $button = null,
        ) use ($fmt): array {
            /** @var array{text: string, link: string} $cta */
            $cta = $button ?? ['text' => '', 'link' => ''];
            $openAt = $openEpoch !== null ? $fmt($openEpoch) : self::t('site.not_set');
            $closeAt = $closeEpoch !== null ? $fmt($closeEpoch) : self::t('site.not_set');

            $body = '<ul class="list-unstyled">'
                .'<li><strong>'.self::t('site.open_label').'</strong> &ndash; '.$openAt.'</li>';

            if ($state === WindowState::Before) {
                $body .= '<li><i class="fa fa-clock me-1"></i><span id="'.$id.'-open-date"></span></li>';
            }

            $body .= '<li><strong>'.self::t('site.close_label').'</strong> &ndash; '.$closeAt.'</li>';

            if ($state === WindowState::Open) {
                $body .= '<li id="'.$id.'-close-date-item"><i class="fa fa-clock me-1"></i><span id="'.$id.'-close-date"></span></li>';
            }

            $body .= '</ul>';

            if ($capReached) {
                $body .= '<p class="lh-1"><small>'.self::t('site.cap_reached').'</small></p>';
            }

            [$pill, $color] = $state === WindowState::Open
                ? [self::t('site.state_open'), 'success']
                : [self::t('site.state_closed'), 'danger'];

            return [
                'id' => $id, 'title' => $title, 'pill' => $pill, 'color' => $color,
                'icon' => $color === 'success' ? 'circle-check' : 'circle-exclamation',
                'body' => $body,
                'button' => $cta,
                'buttonColor' => $color === 'danger' ? 'secondary' : $color,
            ];
        };

        // Entries status card — Amateur edition only (at-a-glance.pub.php).
        $totalEntries = (int) DB::table('brewing')->count();
        $paidEntries = (int) DB::table('brewing')->where('brewPaid', 1)->count();
        $totalLimit = self::numericOrNull($ctx->prefsStr('prefsEntryLimit'));
        $paidLimit = self::numericOrNull($ctx->prefsStr('prefsEntryLimitPaid'));

        $entryBody = '<ul class="list-unstyled">'
            .'<li><strong>'.self::t('site.total').'</strong> &ndash; <span id="entry-total-count">'.$totalEntries
            .($totalLimit !== null ? ' / '.$totalLimit : '').'</span></li>'
            .'<li><strong>'.self::t('site.paid').'</strong> &ndash; <span id="entry-paid-count">'.$paidEntries
            .($paidLimit !== null ? ' / '.$paidLimit : '').'</span></li>';

        if ($w->entry === WindowState::Before) {
            $entryBody .= '<li class="small text-muted lh-1 pt-1">'.self::t('site.opens').' '.$fmt($ctx->contestEpoch('contestEntryOpen')).'</li>';
        } elseif ($w->entry === WindowState::Open) {
            $entryBody .= '<li class="small text-muted lh-1 pt-1">'.self::t('site.updated').' '.$fmt($now).'</li>';
        } elseif ($w->entry === WindowState::After) {
            $entryBody .= '<li class="small text-muted lh-1 pt-1">'.self::t('site.closed').' '.$fmt($ctx->contestEpoch('contestEntryDeadline')).'</li>';
        }
        $entryBody .= '</ul>';

        $entryStatusCard = [
            'id' => 'entries', 'title' => self::t('site.entries'), 'pill' => self::t('site.status'),
            'color' => 'primary', 'icon' => 'circle-info', 'body' => $entryBody,
            'button' => ['text' => '', 'link' => ''], 'buttonColor' => 'primary',
        ];

        // Entry Registration card: legacy shows "Add Entry" for logged-in
        // entrants with remaining slots; anonymous visitors get the disabled
        // "Log In to Enter" variant (at-a-glance.pub.php:158-175).
        $entryButton = $buttonFor($w->entry, false, function () use ($ctx, $loggedIn): array {
            if (! $loggedIn) {
                return ['text' => self::t('site.log_in_to_enter'), 'link' => ''];
            }
            $limit = self::numericOrNull($ctx->prefsStr('prefsEntryLimit'));
            $remaining = $limit === null ? PHP_INT_MAX : max(0, $limit - (int) DB::table('brewing')->count());

            return ['text' => self::t('site.add_entry'), 'link' => $remaining > 0 ? url('/brew') : ''];
        });

        $accountButton = $buttonFor($w->registration, false, fn (): array => $loggedIn
            ? ['text' => self::t('site.edit_account'), 'link' => url('/list/edit-account')]
            : ['text' => self::t('site.register'), 'link' => url('/register')]);

        $judgeButton = $buttonFor($w->judge, $w->judgeCapReached, fn (): array => $loggedIn
            ? ['text' => self::t('site.edit_account'), 'link' => url('/list/edit-account')]
            : ['text' => self::t('site.register_as_judge'), 'link' => url('/register/judge')]);

        $stewardButton = $buttonFor($w->judge, $w->stewardCapReached, fn (): array => $loggedIn
            ? ['text' => self::t('site.edit_account'), 'link' => url('/list/edit-account')]
            : ['text' => self::t('site.register_as_steward'), 'link' => url('/register/steward')]);

        // Judges/stewards share the same registration window ($w->judge).
        $entryRegOpen = $ctx->contestEpoch('contestEntryOpen');
        $entryRegClose = $ctx->contestEpoch('contestEntryDeadline');
        $regOpen = $ctx->contestEpoch('contestRegistrationOpen');
        $regClose = $ctx->contestEpoch('contestRegistrationDeadline');
        $judgeOpen = $ctx->contestEpoch('contestJudgeOpen');
        $judgeClose = $ctx->contestEpoch('contestJudgeDeadline');
        $dropoffOpen = $ctx->contestEpoch('contestDropoffOpen');
        $dropoffClose = $ctx->contestEpoch('contestDropoffDeadline');
        $shipOpen = $ctx->contestEpoch('contestShippingOpen');
        $shipClose = $ctx->contestEpoch('contestShippingDeadline');

        $shippingGated = (int) $ctx->prefsStr('prefsShipping') === 1
            && ! empty($ctx->contestStr('contestShippingAddress'))
            && $shipOpen !== null;

        $entryRegCard = $windowCard('entry-registration', self::t('site.entries_registration'), $w->entry, $entryRegOpen, $entryRegClose, button: $entryButton);
        $accountRegCard = $windowCard('account-registration', self::t('site.account_registration'), $w->registration, $regOpen, $regClose, button: $accountButton);
        $judgeRegCard = $windowCard('judge-registration', self::t('site.judge_registration'), $w->judge, $judgeOpen, $judgeClose, $w->judgeCapReached, $judgeButton);
        $stewardRegCard = $windowCard('steward-registration', self::t('site.steward_registration'), $w->judge, $judgeOpen, $judgeClose, $w->stewardCapReached, $stewardButton);
        $dropOffCard = $dropoffOpen !== null
            ? $windowCard('drop-off', self::t('site.drop_off'), $w->dropoff, $dropoffOpen, $dropoffClose)
            : null;
        $shippingCard = $shippingGated
            ? $windowCard('shipping', self::t('site.entry_shipping'), $w->shipping, $shipOpen, $shipClose)
            : null;

        // Judging card — rendered whenever judging sessions exist
        // (legacy $date_arr non-empty); status is the $judging_start tri-state.
        $judgingCard = null;
        if ($w->firstJudgingDate !== null) {
            $open = $fmt($w->firstJudgingDate);
            $close = $w->lastJudgingDate !== null ? $fmt($w->lastJudgingDate) : null;
            $startLi = '<li><strong>'.self::t('site.start').'</strong> &ndash; '.$open.'</li>';
            $endLi = $close !== null
                ? '<li><strong>'.self::t('site.end').'</strong> &ndash; '.$close.'</li>'
                : '';
            $judgeState = $w->judgingState($now, $ctx->contestEpoch('contestAwardsLocDate'));

            [$pill, $color, $icon, $body] = match ($judgeState) {
                1 => [
                    self::t('site.judging_in_progress'), 'primary', 'sync-spin',
                    '<ul class="list-unstyled">'.$startLi.$endLi
                        .'<li><i class="fa fa-clock me-1"></i><span id="judging-close-date"></span></li></ul>',
                ],
                2 => [
                    self::t('site.judging_concluded'), 'success', 'circle-check',
                    '<ul class="list-unstyled">'.$startLi.$endLi.'</ul>',
                ],
                default => [
                    self::t('site.judging_not_started'), 'secondary', 'clock',
                    '<ul class="list-unstyled">'.$startLi
                        .'<li><i class="fa fa-clock me-1"></i><span id="judging-open-date"></span></li>'
                        .$endLi.'</ul>',
                ],
            };

            $judgingCard = [
                'id' => 'judging', 'title' => self::t('site.judging'), 'pill' => $pill, 'color' => $color,
                'icon' => $icon, 'body' => $body, 'button' => ['text' => '', 'link' => ''], 'buttonColor' => 'secondary',
            ];
        }

        // Awards card — once judging has started AND an award venue is named
        // (legacy $glance_awards). Pro edition additionally gates on the
        // shipping window existing (faithful to at-a-glance.pub.php quirk).
        $awardsCard = null;
        $awardName = $ctx->contestStr('contestAwardsLocName');
        if ($judgingCard !== null && ! empty($awardName) && $w->judgingState($now, $ctx->contestEpoch('contestAwardsLocDate')) > 0) {
            $body = '<ul class="list-unstyled">';
            $locAddr = $ctx->contestStr('contestAwardsLocation');
            if (empty($locAddr)) {
                $body .= '<li><strong>'.self::t('site.location').'</strong> &ndash; '.e($awardName).'</li>';
            } else {
                $addr = rtrim($locAddr, '&amp;KeepThis=true');
                $addr = str_replace(' ', '+', $addr);
                $mapLink = 'http://maps.google.com/maps?f=q&source=s_q&hl=en&q='.$addr;
                $body .= '<li><strong>'.self::t('site.location').'</strong> &ndash; '.e($awardName)
                    .'<a class="hide-loader" href="'.e($mapLink).'" data-bs-toggle="tooltip" data-bs-placement="top" title="Map to '.e($awardName).'" target="_blank"><i class="fa fa-lg fa-map-marker ms-1"></i></a></li>';
            }
            $awardTime = $ctx->contestEpoch('contestAwardsLocTime');
            if ($awardTime !== null) {
                $body .= '<li><strong>'.self::t('site.date_label').'</strong> &ndash; '.$fmt($awardTime).'</li>';
                if ($now < $awardTime) {
                    $body .= '<li id="awards-date-item"><i class="fa fa-clock me-1"></i><span id="awards-date"></span></li>';
                }
            }
            $body .= '</ul>';

            $awardsCard = [
                'id' => 'awards', 'title' => self::t('site.awards'), 'pill' => self::t('site.info'), 'color' => 'primary',
                'icon' => 'circle-info', 'body' => $body, 'button' => ['text' => '', 'link' => ''], 'buttonColor' => 'secondary',
            ];
        }

        // Deck assembly mirrors at-a-glance.pub.php's per-edition card order.
        $proEdition = (int) $ctx->prefsStr('prefsProEdition') === 1;
        $cards = [];

        if ($proEdition) {
            $cards[] = $entryRegCard;
            $cards[] = $accountRegCard;
            $cards[] = $judgeRegCard;
            $cards[] = $stewardRegCard;
            if ($dropOffCard !== null) {
                $cards[] = $dropOffCard;
            }
            if ($shippingCard !== null) {
                $cards[] = $shippingCard;
            }
            if ($shippingCard !== null && $awardsCard !== null) {
                $cards[] = $awardsCard;
            }
            if ($judgingCard !== null) {
                $cards[] = $judgingCard;
            }
        } else {
            $cards[] = $entryStatusCard;
            if ($awardsCard !== null) {
                $cards[] = $awardsCard;
            }
            if ($judgingCard !== null) {
                $cards[] = $judgingCard;
            }
            $cards[] = $entryRegCard;
            $cards[] = $accountRegCard;
            $cards[] = $judgeRegCard;
            $cards[] = $stewardRegCard;
            if ($dropOffCard !== null) {
                $cards[] = $dropOffCard;
            }
            if ($shippingCard !== null) {
                $cards[] = $shippingCard;
            }
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
