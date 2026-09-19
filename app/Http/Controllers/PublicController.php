<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ContactMail;
use App\Support\Entries\EntryGates;
use App\Support\Results\ResultsRepository;
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
        // fw-light interest line with <small> wrapper. The organising club
        // link is rendered as a second line (issue #57).
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
            .'</small></p>';

        // The organising club gets its own line (issue #57) so a long host
        // name cannot orphan a single word of the interest sentence above.
        if ($host !== '') {
            $salutation .= '<p class="lead landing-page-salutation landing-page-host fw-light"><small>'
                .self::t('site.organized_by').' '.$hostHtml
                .($ctx->contestStr('contestHostLocation') ? ', '.e($ctx->contestStr('contestHostLocation')) : '')
                .'.</small></p>';
        }

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

            // Not-yet-open windows, consolidated. A window with no open date
            // cannot promise a date, so it never emits a dangling "will open ."
            // (the pre-modernisation bug); undated windows collapse into one
            // "dates to be announced" line, and windows that share an open date
            // collapse into one sentence with a single shared call to action.
            $pending = [];
            if ($reg === WindowState::Before) {
                $pending[] = ['label' => self::t('site.fyi_label_account'), 'action' => self::t('site.fyi_action_account'), 'at' => $ctx->contestEpoch('contestRegistrationOpen')];
            }
            if ($entry === WindowState::Before) {
                $pending[] = ['label' => self::t('site.fyi_label_entry'), 'action' => self::t('site.fyi_action_entry'), 'at' => $ctx->contestEpoch('contestEntryOpen')];
            }
            if ($reg === WindowState::After && $judge === WindowState::Before) {
                $pending[] = ['label' => self::t('site.fyi_label_judge'), 'action' => self::t('site.fyi_action_judge'), 'at' => $ctx->contestEpoch('contestJudgeOpen')];
            }

            $undated = [];
            $byDate = [];
            foreach ($pending as $item) {
                if ($item['at'] === null) {
                    $undated[] = $item;
                } else {
                    $byDate[$item['at']][] = $item;
                }
            }
            ksort($byDate);

            foreach ($byDate as $at => $items) {
                $labels = array_map(static fn (array $item): string => (string) $item['label'], $items);
                $actions = array_map(static fn (array $item): string => (string) $item['action'], $items);
                $fyiAlerts[] = '<strong>'.self::joinLabels(...$labels).' '
                    .self::t(count($items) > 1 ? 'site.fyi_opens_many' : 'site.fyi_opens_one').' '.$long($at).'.</strong> '
                    .self::t('site.fyi_return_then').' '.self::joinLabels(...$actions).'.';
            }
            if ($undated !== []) {
                $labels = array_map(static fn (array $item): string => (string) $item['label'], $undated);
                $fyiAlerts[] = '<strong>'.self::joinLabels(...$labels).'</strong> &mdash; '.self::t('site.fyi_dates_tba');
            }

            if ($reg === WindowState::Open && $entry === WindowState::Open && (int) $ctx->prefsStr('prefsEntryLimit') > 0) {
                $fyiAlerts[] = '<strong>'.self::t('site.fyi_entry_open').'</strong> '
                    .__('site.fyi_total_added', ['count' => (int) DB::table('brewing')->count(), 'time' => DateFmt::dateTime($now, $tz, $df, $tf, 'short')])
                    .' '.self::t('site.fyi_reg_will_close').' '.$long($ctx->contestEpoch('contestRegistrationDeadline')).'.';
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
            'logoFor' => static function (\stdClass $sponsor): string {
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
        $logoFor = static function (\stdClass $sponsor): string {
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
        $ctx = TenantContext::load();

        // The message form only exists while prefsContact is 'Y' ('N' shows the
        // officials list, 'X' nothing). A crafted POST must obey that too — the
        // view gate alone is cosmetic (D2-02).
        if ($ctx->prefsStr('prefsContact') !== 'Y') {
            return redirect()->route('contact');
        }

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

        try {
            Mail::send(new ContactMail(
                toName: $contact->contactFirstName.' '.$contact->contactLastName,
                toEmail: $contact->contactEmail,
                fromName: $validated['from_name'],
                fromEmail: $validated['from_email'],
                subjectLine: $validated['subject'],
                body: $validated['message'],
                contestName: $ctx->contestStr('contestName') ?? '',
                // prefsEmailCC: copy the sender when the site has CC enabled.
                ccEmail: $ctx->prefsStr('prefsEmailCC') === '1' ? $validated['from_email'] : null,
            ));
        } catch (\Throwable $e) {
            // The message is the whole point of this request, so unlike the
            // signup receipts a failure cannot be swallowed. It still must not
            // reach the sender as a 500: hand the form back with what they
            // typed plus an explanation, and log the transport error.
            report($e);

            return back()->withInput()->withErrors(['message' => __('site.contact_send_failed')]);
        }

        return redirect()->route('contact')->with('contactSent', true);
    }

    /**
     * Spam-safe "email this official" link (issue #54): the contacts list links
     * here through a Laravel signed URL instead of printing a mailto: address,
     * so harvesting the page HTML yields no addresses. The signed middleware
     * rejects tampered links, and the route is throttled to blunt enumeration.
     *
     * ponytail: a client that fetches the signed link still learns the address;
     * the throttle is the ceiling. Move to a per-contact form with CSRF if
     * harvesting ever shows up in the logs.
     */
    public function contactEmail(Request $request, int $contact): RedirectResponse
    {
        $row = DB::table('contacts')->where('id', $contact)->first();

        $address = trim((string) ($row->contactEmail ?? ''));
        if ($row === null || $address === '') {
            abort(404);
        }

        return redirect()->away('mailto:'.$address);
    }

    /**
     * Section-page salutation for standalone public surfaces (volunteers,
     * contact, sponsors). Legacy index.pub.php:106-111 renders for
     * non-default sections: the contest-name h1 plus the logged-in
     * "Welcome {name}!" line. The interest line is landing-only
     * (default/maintenance/numeric sections, index.pub.php:129-135), so
     * it must NOT appear here.
     */
    private function publicSalutation(Request $request, TenantContext $ctx): string
    {
        $salutation = '<h1 class="fw-bold animate__animated animate__fadeInDown">'.e($ctx->contestStr('contestName')).'</h1>';
        if ($request->user() !== null) {
            $firstName = DB::table('brewer')->where('uid', (int) $request->user()->id)->value('brewerFirstName') ?? '';
            $salutation .= '<p class="landing-page-salutation">'.self::t('site.welcome').' '.e($firstName).'!</p>';
        }

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
            // pub/list.pub.php button stack: the pay button goes inert when
            // there is nothing to collect — no unpaid confirmed entries, or a
            // free competition (per-entry fee 0). The partial explains why on
            // hover.
            'payDisabled' => $unpaidCount === 0 || $fee <= 0,
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
     * @return list<array{id: string, title: string, accent: string, pill: string, color: string, body: string, buttons: list<array{text: string, link: string, color: string}>}>
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

            $body = '<p class="glance-date mb-0">'.self::t('site.start').' '.$fmt($w->firstJudgingDate).'</p>';
            if ($w->lastJudgingDate !== null) {
                $body .= '<p class="glance-date-sub mb-0">'.self::t('site.end').' '.$fmt($w->lastJudgingDate).'</p>';
            }

            [$pill, $color, $icon] = match ($state) {
                1 => [self::t('site.judging_in_progress'), 'primary', 'sync-spin'],
                2 => [self::t('site.judging_concluded'), 'success', 'circle-check'],
                default => [self::t('site.judging_not_started'), 'info', 'clock'],
            };

            $cards[] = [
                'id' => 'judging',
                'title' => self::t('site.judging'),
                'accent' => 'purple',
                'pill' => $pill,
                'color' => $color,
                'icon' => $icon,
                'body' => $body,
                'buttons' => [],
            ];
        }

        if ($entryInfo) {
            $windowCard = function (string $id, string $title, string $accent, WindowState $state, ?string $openAt, ?string $closeAt): array {
                [$pill, $color, $icon] = match ($state) {
                    WindowState::Open => [self::t('site.state_open'), 'success', 'circle-check'],
                    WindowState::After => [self::t('site.state_closed'), 'secondary', 'circle-xmark'],
                    default => [self::t('site.state_before'), 'info', 'clock'],
                };

                return [
                    'id' => $id, 'title' => $title, 'accent' => $accent,
                    'pill' => $pill, 'color' => $color, 'icon' => $icon,
                    'body' => self::windowBody($state, $openAt, $closeAt),
                    'buttons' => [],
                ];
            };

            $tz = $ctx->prefsStr('prefsTimeZone');
            $df = $ctx->prefsStr('prefsDateFormat');
            $tf = $ctx->prefsStr('prefsTimeFormat');
            $style = 'short'; // at-a-glance.pub.php always renders numeric short dates
            $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $style) ?? self::t('site.not_set');

            $cards[] = $windowCard('entry-registration', self::t('site.entries_registration'), 'blue', $w->entry,
                $fmt($ctx->contestEpoch('contestEntryOpen')), $fmt($ctx->contestEpoch('contestEntryDeadline')));

            // Drop-off and shipping each follow their own Display switch
            // (prefsDropOff / prefsShipping): with the switch off the window is
            // not part of the competition, so its card must not render.
            if ((int) $ctx->prefsStr('prefsDropOff') === 1) {
                $cards[] = $windowCard('drop-off', self::t('site.drop_off'), 'cyan', $w->dropoff,
                    $fmt($ctx->contestEpoch('contestDropoffOpen')), $fmt($ctx->contestEpoch('contestDropoffDeadline')));
            }

            if ((int) $ctx->prefsStr('prefsShipping') === 1 && $ctx->contestStr('contestShippingAddress')) {
                $cards[] = $windowCard('shipping', self::t('site.shipping'), 'cyan', $w->shipping,
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
     * CTA decision table: a window contributes a button only while it is open
     * and under its cap.
     *
     * @param  callable(): array{text: string, link: string}  $whenOpen
     * @return list<array{text: string, link: string}>
     */
    private static function ctaFor(WindowState $state, bool $capReached, callable $whenOpen): array
    {
        return ($state === WindowState::Open && ! $capReached) ? [$whenOpen()] : [];
    }

    /** Join phrases into a readable enumeration: "a", "a and b", "a, b and c". */
    private static function joinLabels(string ...$items): string
    {
        $last = array_pop($items);
        if ($last === null) {
            return '';
        }
        if ($items === []) {
            return $last;
        }

        return implode(', ', $items).' '.self::t('site.and').' '.$last;
    }

    /**
     * Condensed window body: one lead date line plus an optional muted
     * secondary, replacing the legacy four-row Open/Close list. A window with
     * no date at all says so rather than rendering a blank value.
     */
    private static function windowBody(WindowState $state, ?string $openAt, ?string $closeAt, bool $capReached = false): string
    {
        if ($openAt === null && $closeAt === null) {
            $body = '<p class="glance-date-sub mb-0">'.self::t('site.glance_dates_tba').'</p>';
        } elseif ($state === WindowState::Open) {
            $body = '<p class="glance-date mb-0">'.self::t('site.glance_closes').' '.$closeAt.'</p>'
                .($openAt !== null ? '<p class="glance-date-sub mb-0">'.self::t('site.glance_opens').' '.$openAt.'</p>' : '');
        } elseif ($state === WindowState::After) {
            $body = '<p class="glance-date mb-0">'.self::t('site.state_closed').'</p>'
                .($closeAt !== null ? '<p class="glance-date-sub mb-0">'.self::t('site.closed').' '.$closeAt.'</p>' : '');
        } else {
            $body = '<p class="glance-date mb-0">'.self::t('site.glance_opens').' '
                .($openAt ?? self::t('site.glance_dates_tba')).'</p>';
        }

        if ($capReached) {
            $body .= '<p class="glance-date-sub mb-0">'.self::t('site.cap_reached').'</p>';
        }

        return $body;
    }

    /**
     * @return list<array{id: string, title: string, accent: string, pill: string, color: string, icon: string, body: string, buttons: list<array{text: string, link: string, color: string}>}>
     */
    private function glanceCards(TenantContext $ctx, Windows $w, bool $longDates, bool $loggedIn): array
    {
        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');
        $style = 'short'; // at-a-glance.pub.php always renders numeric short dates
        $now = time();

        $fmt = fn (?int $epoch): string => DateFmt::dateTime($epoch, $tz, $df, $tf, $style) ?? self::t('site.not_set');

        // Two independent axes. The DOMAIN ACCENT gives each card its identity
        // (entries / account / volunteering / logistics / judging / awards) and
        // the STATE PILL carries open / not-yet-open / closed. Colouring by state
        // alone made the whole deck one colour, because every window moves on the
        // same contest dates.
        $accentEntry = 'blue';
        $accentAccount = 'indigo';
        $accentVolunteer = 'teal';
        $accentLogistics = 'cyan';
        $accentJudging = 'purple';
        $accentAwards = 'amber';

        // Window card: one condensed lead date with a muted secondary, a domain
        // accent, and a semantic state pill.
        /**
         * @param  list<array{text: string, link: string}>  $buttons
         * @return array{id: string, title: string, accent: string, pill: string, color: string, icon: string, body: string, buttons: list<array{text: string, link: string, color: string}>}
         */
        $windowCard = function (
            string $id, string $title, string $accent, WindowState $state, ?int $openEpoch, ?int $closeEpoch,
            bool $capReached = false, array $buttons = [],
        ) use ($fmt): array {
            $openAt = $openEpoch !== null ? $fmt($openEpoch) : null;
            $closeAt = $closeEpoch !== null ? $fmt($closeEpoch) : null;

            [$pill, $color, $icon] = match ($state) {
                WindowState::Open => [self::t('site.state_open'), 'success', 'circle-check'],
                WindowState::After => [self::t('site.state_closed'), 'secondary', 'circle-xmark'],
                default => [self::t('site.state_before'), 'info', 'clock'],
            };

            return [
                'id' => $id, 'title' => $title, 'accent' => $accent,
                'pill' => $pill, 'color' => $color, 'icon' => $icon,
                'body' => self::windowBody($state, $openAt, $closeAt, $capReached),
                'buttons' => array_values(array_map(
                    static fn (array $b): array => [
                        'text' => (string) $b['text'],
                        'link' => (string) $b['link'],
                        'color' => $accent,
                    ],
                    $buttons,
                )),
            ];
        };

        // Entry Registration card — absorbs the old standalone "Entries" card,
        // so the entry counts and the entry window live in one place instead of
        // two cards that duplicated the same state.
        $totalEntries = (int) DB::table('brewing')->count();
        $paidEntries = (int) DB::table('brewing')->where('brewPaid', 1)->count();
        $totalLimit = self::numericOrNull($ctx->prefsStr('prefsEntryLimit'));
        $paidLimit = self::numericOrNull($ctx->prefsStr('prefsEntryLimitPaid'));

        $entryButton = self::ctaFor($w->entry, false, function () use ($ctx, $loggedIn): array {
            if (! $loggedIn) {
                return ['text' => self::t('site.log_in_to_enter'), 'link' => ''];
            }
            $limit = self::numericOrNull($ctx->prefsStr('prefsEntryLimit'));
            $remaining = $limit === null ? PHP_INT_MAX : max(0, $limit - (int) DB::table('brewing')->count());

            return ['text' => self::t('site.add_entry'), 'link' => $remaining > 0 ? url('/brew') : ''];
        });

        $entryRegCard = $windowCard('entry-registration', self::t('site.entries_registration'), $accentEntry, $w->entry, $ctx->contestEpoch('contestEntryOpen'), $ctx->contestEpoch('contestEntryDeadline'), buttons: $entryButton);
        $entryRegCard['body'] .= '<p class="glance-date-sub mb-0">'.self::t('site.total')
            .' <span id="entry-total-count">'.$totalEntries.($totalLimit !== null ? ' / '.$totalLimit : '').'</span></p>'
            .'<p class="glance-date-sub mb-0">'.self::t('site.paid')
            .' <span id="entry-paid-count">'.$paidEntries.($paidLimit !== null ? ' / '.$paidLimit : '').'</span></p>';

        // Account Registration card.
        $accountButton = self::ctaFor($w->registration, false, fn (): array => $loggedIn
            ? ['text' => self::t('site.edit_account'), 'link' => url('/list/edit-account')]
            : ['text' => self::t('site.register'), 'link' => url('/register')]);

        $accountRegCard = $windowCard('account-registration', self::t('site.account_registration'), $accentAccount, $w->registration, $ctx->contestEpoch('contestRegistrationOpen'), $ctx->contestEpoch('contestRegistrationDeadline'), buttons: $accountButton);

        // Volunteer Registration card — judges and stewards share one contest
        // window, so they share one card instead of two near-identical ones.
        // Anonymous visitors get a CTA per open role; a logged-in visitor has
        // already registered, so the single action is to edit the account.
        /** @var list<array{text: string, link: string}> $volunteerButtons */
        $volunteerButtons = [];
        if ($loggedIn) {
            $volunteerButtons = self::ctaFor($w->judge, $w->judgeCapReached && $w->stewardCapReached, fn (): array => ['text' => self::t('site.edit_account'), 'link' => url('/list/edit-account')]);
        } else {
            if (! $w->judgeCapReached) {
                $volunteerButtons = array_values(array_merge($volunteerButtons, self::ctaFor($w->judge, false, fn (): array => ['text' => self::t('site.register_as_judge'), 'link' => url('/register/judge')])));
            }
            if (! $w->stewardCapReached) {
                $volunteerButtons = array_values(array_merge($volunteerButtons, self::ctaFor($w->judge, false, fn (): array => ['text' => self::t('site.register_as_steward'), 'link' => url('/register/steward')])));
            }
        }

        $volunteerCard = $windowCard('volunteer-registration', self::t('site.volunteer_registration'), $accentVolunteer, $w->judge, $ctx->contestEpoch('contestJudgeOpen'), $ctx->contestEpoch('contestJudgeDeadline'), buttons: $volunteerButtons);
        if ($w->judgeCapReached) {
            $volunteerCard['body'] .= '<p class="glance-date-sub mb-0">'.self::t('site.cap_reached_judges').'</p>';
        }
        if ($w->stewardCapReached) {
            $volunteerCard['body'] .= '<p class="glance-date-sub mb-0">'.self::t('site.cap_reached_stewards').'</p>';
        }

        // Logistics windows.
        $dropoffOpen = $ctx->contestEpoch('contestDropoffOpen');
        $dropoffClose = $ctx->contestEpoch('contestDropoffDeadline');
        $shipOpen = $ctx->contestEpoch('contestShippingOpen');
        $shipClose = $ctx->contestEpoch('contestShippingDeadline');

        $shippingGated = (int) $ctx->prefsStr('prefsShipping') === 1
            && ! empty($ctx->contestStr('contestShippingAddress'))
            && $shipOpen !== null;

        // Drop-off display switch (prefsDropOff) gates the account-deck card the
        // same way prefsShipping gates the shipping card below.
        $dropOffCard = (int) $ctx->prefsStr('prefsDropOff') === 1 && $dropoffOpen !== null
            ? $windowCard('drop-off', self::t('site.drop_off'), $accentLogistics, $w->dropoff, $dropoffOpen, $dropoffClose)
            : null;
        $shippingCard = $shippingGated
            ? $windowCard('shipping', self::t('site.entry_shipping'), $accentLogistics, $w->shipping, $shipOpen, $shipClose)
            : null;

        // Judging card — rendered whenever judging sessions exist
        // (legacy $date_arr non-empty); status is the $judging_start tri-state.
        $judgingCard = null;
        if ($w->firstJudgingDate !== null) {
            $open = $fmt($w->firstJudgingDate);
            $close = $w->lastJudgingDate !== null ? $fmt($w->lastJudgingDate) : null;
            $judgeState = $w->judgingState($now, $ctx->contestEpoch('contestAwardsLocDate'));

            // "Not started" is info, not the grey used for "Closed", so a
            // session that has not begun is not read as finished.
            [$pill, $color, $icon] = match ($judgeState) {
                1 => [self::t('site.judging_in_progress'), 'primary', 'sync-spin'],
                2 => [self::t('site.judging_concluded'), 'success', 'circle-check'],
                default => [self::t('site.judging_not_started'), 'info', 'clock'],
            };

            $body = '<p class="glance-date mb-0">'.self::t('site.start').' '.$open.'</p>';
            if ($close !== null) {
                $body .= '<p class="glance-date-sub mb-0">'.self::t('site.end').' '.$close.'</p>';
            }

            $judgingCard = [
                'id' => 'judging', 'title' => self::t('site.judging'), 'accent' => $accentJudging,
                'pill' => $pill, 'color' => $color, 'icon' => $icon, 'body' => $body, 'buttons' => [],
            ];
        }

        // Awards card — once judging has started AND an award venue is named.
        $awardsCard = null;
        $awardName = $ctx->contestStr('contestAwardsLocName');
        if ($judgingCard !== null && ! empty($awardName) && $w->judgingState($now, $ctx->contestEpoch('contestAwardsLocDate')) > 0) {
            $body = '';
            $locAddr = $ctx->contestStr('contestAwardsLocation');
            if (empty($locAddr)) {
                $body .= '<p class="glance-date mb-0">'.self::t('site.location').' '.e($awardName).'</p>';
            } else {
                $addr = rtrim($locAddr, '&amp;KeepThis=true');
                $addr = str_replace(' ', '+', $addr);
                $mapLink = 'http://maps.google.com/maps?f=q&source=s_q&hl=en&q='.$addr;
                $body .= '<p class="glance-date mb-0">'.self::t('site.location').' '.e($awardName)
                    .'<a class="hide-loader" href="'.e($mapLink).'" data-bs-toggle="tooltip" data-bs-placement="top" title="Map to '.e($awardName).'" target="_blank"><i class="fa fa-lg fa-map-marker ms-1"></i></a></p>';
            }
            $awardTime = $ctx->contestEpoch('contestAwardsLocTime');
            if ($awardTime !== null) {
                $body .= '<p class="glance-date-sub mb-0">'.self::t('site.date_label').' '.$fmt($awardTime).'</p>';
            }

            $awardsCard = [
                'id' => 'awards', 'title' => self::t('site.awards'), 'accent' => $accentAwards,
                'pill' => self::t('site.info'), 'color' => 'dark',
                'icon' => 'circle-info', 'body' => $body, 'buttons' => [],
            ];
        }

        // Single deck order: the windows a visitor can act on first, then the
        // logistics windows, then the event info. The old per-edition ordering
        // no longer applies now that the counts card is folded in and judge and
        // steward share one card.
        $cards = [$entryRegCard, $accountRegCard, $volunteerCard];
        foreach ([$dropOffCard, $shippingCard, $judgingCard, $awardsCard] as $optional) {
            if ($optional !== null) {
                $cards[] = $optional;
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
        // Admin-chosen banner images win (prefsHeroImages: filename => shown),
        // falling back to the built-in pool when none are selected — so the
        // Banner Images screen actually drives the homepage (C1-01).
        $chosen = json_decode((string) $ctx->prefsStr('prefsHeroImages'), true);
        if (is_array($chosen)) {
            $enabled = array_keys(array_filter($chosen));
            if ($enabled !== []) {
                return (string) $enabled[random_int(0, count($enabled) - 1)];
            }
        }

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
