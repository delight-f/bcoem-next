# Unwired and broken features — plain English

This is the plain-English companion to `docs/unwired-features-audit.md`. That document is the technical record; this one explains the same items without code. How it was produced: someone went through every option, button and setting on the site, followed each one from the screen to the piece of code that is supposed to use it, and recorded either what it changes — or that nothing uses it at all. The same reference codes (for example `C2-01`) are used in both documents so you can look up any item in either place. Nothing has been changed, and nothing below is a recommendation; these are findings for you to decide on.

## The short version

The handful that matter most, worst first:

- **C2-01 — a competitor can be charged and their entries still show as unpaid.** If the Stripe webhook secret is missing or wrong, Stripe still appears as a payment option, money is taken, and the entries never get marked paid.
- **B1-01 — checkout can break because the wrong kind of currency value is sent to the payment provider.** The currency drop-down stores a display symbol (like `$` or `euro`), and that same value is sent to Stripe as if it were a three-letter currency code.
- **C2-02 — there is no screen anywhere to set a proper currency code**, so the value the payment system actually needs can never be filled in through the app.
- **B2-01 — the six Tie Break Rules cannot be saved at all.** Choosing any of them makes the whole Best Brewer settings tab refuse to save, so none of its other settings save either.
- **D2-01 — hiding a field destroys stored data.** Turning off the MHP number makes the field disappear, and the next time an entrant saves their club/account page, their stored MHP number is wiped.
- **C3-04 — the Participants page can crash completely** (a blank error page) on competitions that carry assignment data from the old system.
- **D1-01 — the four Results print options all produce the same document.** "All with Scores", "Winners Only with Scores", "All without Scores" and "HTML" all return the same winners-only PDF.
- **D1-04 — the roughly eighteen Data Export links all download the same full file.** Each link promises a different dataset; only the file name changes.

## What already works

Nearly everything was confirmed working. The audit traced **168 individual controls** to real code that changes behaviour — fees, entry limits, judging rounds and flights, tables, labels and pullsheets, participant and entry admin, the public pages, registration, the evaluation screens and the install wizard are all wired as described (Appendix A of the technical document lists every one). The problems below are a minority, but several of them sit on money and data, so they are worth your attention.

---

## Preferences — General and language settings

### A1-01 — One of the three paginated lists ignores your "Records Displayed" setting

**What you see:** a "Records Displayed" setting that controls how many rows appear on a page of a long list.

**What you'd expect:** it applies to the paginated lists — entries, participants and the judge/steward pool pages.

**What actually happens:** the entries list and the participants list follow it; the judge/steward pool-assign table always shows 25 rows a page, whatever you set.

**Why it matters:** small. One screen doesn't honour the setting; nothing breaks.

**Technical pointer:** `resources/views/judging/partials/pool_table.blade.php:13` — `not-consumed`, `low`.

### A1-02 — The date inside the BJCP report ignores your date and time-zone settings

**What you see:** the date format, time format and time zone settings that apply across the site.

**What you'd expect:** every date the software prints uses them.

**What actually happens:** the single date stamped into the BJCP competition report always uses US ordering and a 12-hour clock in the server's own time zone, ignoring all three settings.

**Why it matters:** small — it only affects that one report, and the times shown to you elsewhere are correct.

**Technical pointer:** `app/Http/Controllers/Output/StaffPointsController.php:838` — `broken`, `low`.

### A1-03 — The visitor language menu never appears unless you save a language list

**What you see:** an "Available Languages" group and a setting to let visitors switch language.

**What you'd expect:** turning the toggle on lets visitors pick a language.

**What actually happens:** on a competition that has never opened and saved that setting, the built-in fallback list spells the language codes the wrong way, so only English is ever recognised — and with one language available the language menu is not shown at all.

**Why it matters:** medium. International visitors get the site in English only until someone saves the language list once.

**Technical pointer:** `app/Support/Tenant/Language.php:38` — `broken`, `medium`.

### A1-04 — On a Professional competition, the MHP switch is ignored

**What you see:** an Enable/Disable switch for the MHP number.

**What you'd expect:** your choice is saved.

**What actually happens:** if the competition is set to Professional, the setting is always stored as Disabled no matter which option you pick, and the screen never tells you that.

**Why it matters:** small, and it matches the original system's behaviour — but the screen promises a choice it does not give you.

**Technical pointer:** `app/Http/Controllers/Admin/SitePreferencesController.php:333` — `gated`, `low`.

### A1-05 — "Search Engine Friendly URLs" was removed; the stored setting remains

**What you see:** nothing — this control is gone from the screen.

**What you'd expect:** n/a (no control offered).

**What actually happens:** the old setting column still exists in the database but is never written or used; the modern app always uses clean web addresses.

**Why it matters:** cosmetic housekeeping only.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:125-128` — `retired`, `low`.

### A1-06 — "Automatically Purge…" was replaced by a button

**What you see:** a "Purge stale entries now" button instead of the old automatic-purge switch.

**What you'd expect:** stale entries are cleaned up as described.

**What actually happens:** the automatic schedule was never carried over; the old setting remains unused, and the button runs the same 24-hour rule on demand.

**Why it matters:** none — the cleanup still happens, just when you press the button.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:195-210` — `retired`, `low`.

## Preferences — Entries tab

### A2-01 — The total entry limit hides the form but doesn't actually stop over-entry

**What you see:** "Total Entry Limit – Paid/Unpaid", described as the limit of total entries you will accept.

**What you'd expect:** once the limit is reached, no more entries can be added.

**What actually happens:** the site stops showing the Add Entry button and reports the window as closed, but the page that actually saves an entry never checks the limit — so an entry submitted directly (for example by re-sending a form left open in a browser) is accepted.

**Why it matters:** medium. The limit is a strong hint rather than a hard stop, so a determined entrant can exceed it.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:514-518`; gate `app/Http/Controllers/BrewController.php:132-153` — `wired`, `medium`.

### A2-02 — The paid entry limit has the same gap

**What you see:** "Total Entry Limit – Paid".

**What you'd expect:** once enough entries are paid, no more are accepted.

**What actually happens:** exactly as A2-01 — the count is displayed and the Add Entry button disappears, but the save path does not refuse the entry.

**Why it matters:** medium, same as A2-01.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:520-529` — `wired`, `medium`.

### A2-03 — A limit warning tells you to change a setting that cannot fix it

**What you see:** on the participants screen, a notice saying the list has passed a record limit, with a link to change the "DataTables Record Threshold".

**What you'd expect:** following the advice clears the notice.

**What actually happens:** the notice measures a different, hidden setting that no screen can change, so changing the suggested one has no effect on it.

**Why it matters:** small — it is a misleading message, not lost data, and it only appears on very large competitions.

**Technical pointer:** `resources/views/admin/entries.blade.php:8,99`; `resources/views/admin/participants.blade.php:12-13,28-31` — `not-saved`, `low`.

### A2-04 — The "Restrict Entries" tick-box on styles does nothing

**What you see:** a per-style tick-box that appears to mark a style as being at its limit, plus a note that changing the limit method re-enables styles that were switched off.

**What you'd expect:** ticking it restricts entries for that style.

**What actually happens:** the tick is stored but nothing ever reads it — entry limits are enforced by a separate, newer mechanism — and it is not cleared reliably when a style stops being accepted.

**Why it matters:** medium. The control looks meaningful and is the kind of thing an organiser would rely on; the tick also survives when it should not.

**Technical pointer:** `resources/views/admin/styles.blade.php:59`; `Admin/StylesAdminController.php:75` — `not-consumed`, `medium`.

### A2-05 — A fee cap with pennies in it is refused

**What you see:** "Fee Cap", an amount box that accepts pounds and pence (or dollars and cents).

**What you'd expect:** you can enter a cap like 25.50.

**What actually happens:** the form only accepts whole numbers for this field and refuses to save a value with pence in it, even though the rest of the fee system handles fractional amounts fine.

**Why it matters:** small — whole-number caps work, but a fractional cap cannot be set.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:431-435` vs `SitePreferencesController.php:403` — `broken`, `low`.

## Preferences — Email and Contact tab

### A3-01 — The "Test email settings" button leaves the admin area

**What you see:** a "Test Current Email Sending Settings" button that looks like it will open a small pop-up window.

**What you'd expect:** a small window opens over the settings page.

**What actually happens:** the pop-up behaviour was never carried over, so the button behaves as an ordinary link and takes you to the test screen as a full page — and that screen renders without the admin menu, so it looks like you have left the admin area.

**Why it matters:** small — it still works, it just looks wrong and you have to navigate back.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:1013` — `broken`, `low`.

### A3-02 — "Application default (from .env)" does nothing once a mail host is saved

**What you see:** a mail "How Emails Are Sent" drop-down with an "Application default" option that is meant to hand control back to the server's own configuration.

**What you'd expect:** choosing it returns mail to the server default.

**What actually happens:** if a mail host has ever been saved, choosing the default still sends through SMTP — and because of how the screen decides which option to highlight, the drop-down can even read "Application default" while SMTP is really in use.

**Why it matters:** small now, but it can make you believe mail is going out one way when it is going out another.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:883`; `app/Support/Mail/MailSettings.php:95-101` — `broken`, `low`.

### A3-03 — Mail passwords are stored readable, not scrambled

**What you see:** password fields for your SMTP password and provider API key.

**What you'd expect:** secrets are stored in a protected form and can be reused without retyping.

**What actually happens:** they are stored as ordinary readable text in the database (the old system scrambled them; that step was not carried over), but they are never shown back on screen.

**Why it matters:** medium — anyone who gets a copy of the database can read your mail password. This is a known, documented difference from the old system.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:938,945-946` — `wired`, `medium`.

### A3-04 — A saved SMTP password cannot be cleared

**What you see:** options to keep the stored password or set a new one.

**What you'd expect:** you can remove the stored password if you want to.

**What actually happens:** both options keep the existing password when the field is left blank, so once a password is saved it cannot be removed through the screen.

**Why it matters:** small — you can change it, just not delete it.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:924-939`; `SitePreferencesController.php:587-598` — `wired`, `low`.

### A3-05 — Turning email off quietly resets two other settings

**What you see:** a Yes/No switch for "Allow BCOE&M to Send Emails".

**What you'd expect:** it stops email being sent.

**What actually happens:** turning it off also clears the posted mail-server details and switches the "registration confirmation emails" and "copy the sender" settings to No — so turning email off and back on leaves those two reading No.

**Why it matters:** small, but surprising: a temporary pause can silently change a setting you will not think to check.

**Technical pointer:** `app/Http/Controllers/Admin/SitePreferencesController.php:600-611` — `wired`, `low`.

### A3-06 — A warning message on the test-email screen is out of date

**What you see:** text under the test button saying the test may report success even though no email arrives.

**What you'd expect:** the warning to match what the test really does.

**What actually happens:** the test screen now deliberately warns you when a message would not really be delivered, so the old warning no longer describes it.

**Why it matters:** small — outdated wording only.

**Technical pointer:** `resources/views/admin/site-preferences.blade.php:1015-1021` — `broken`, `low`.

### A3-07 — The test screen lists mail settings that are not in use

**What you see:** a summary of Host, Username, Encryption and Port on the test-email screen.

**What you'd expect:** the summary shows the settings actually in force.

**What actually happens:** those rows are always drawn from the SMTP fields, so if you are using a different sending method the page shows SMTP details that are not being used.

**Why it matters:** small — misleading while setting mail up.

**Technical pointer:** `app/Http/Controllers/Admin/SendTestEmailController.php:59-62` — `broken`, `low`.

## Preferences — Best Brewer and scoring

### B2-01 — The Tie Break Rules cannot be saved, and they block the rest of the tab

**What you see:** six "Tie Break Rule" drop-downs, explained as being used in order to separate tied standings.

**What you'd expect:** you pick a rule for each and save; they then break ties in the standings.

**What actually happens:** choosing anything other than "Unused" makes the whole Best Brewer tab refuse to save with an error — so the tie-break rules are never stored, and while one is selected you cannot save the points values, titles or display positions on that tab either.

**Why it matters:** high. Ties are never separated as you configured, and the tab becomes unsavable until every rule is put back to "Unused".

**Technical pointer:** `app/Http/Controllers/Admin/SitePreferencesController.php:681-686` — `broken`, `high`.

## Payments

### B1-01 — The currency setting sends the wrong kind of value to the payment provider

**What you see:** a currency drop-down on the payment preferences.

**What you'd expect:** it sets the currency used for payments.

**What actually happens:** the display side works (the right symbol appears next to amounts), but the same value is also sent to Stripe as if it were the official three-letter currency code. A value like `$` or `euro` is not a valid code, so card checkout can fail with a currency error; PayPal refuses the payment instead of charging the wrong currency.

**Why it matters:** high. On any competition whose currency is not already a plain code, card checkout can be broken for every entrant.

**Technical pointer:** `app/Support/Payments/StripeGateway.php:48`; `PayPalGateway.php:427` — `broken`, `high`.

### B1-02 — Cash and cheque can be offered even when the switch says No

**What you see:** "Accept Cash?" and "Accept Checks?" switches on the payment preferences.

**What you'd expect:** Yes offers the method; No withdraws it.

**What actually happens:** a method is offered unless it was explicitly saved as off, so a competition that never touched these switches will show "No" on screen yet still offer cash and cheque. The shipped example data is consistent, so most sites will not notice.

**Why it matters:** small — the mismatch is in how an untouched setting is displayed, not in what it does.

**Technical pointer:** `app/Support/Payments/ManualGateway.php:36-47` — `broken`, `low`.

### B1-03 — The payment confirmation email prints the raw currency wording

**What you see:** amounts on screen always shown with the proper symbol, for example `£24.00`.

**What you'd expect:** the confirmation email shows the amount the same way.

**What actually happens:** the email prints the stored currency wording instead of the symbol, so some currencies read oddly — for example "24.00 czkoruna" instead of "Kč 24.00".

**Why it matters:** small — cosmetic, but it is the one message a paying entrant receives.

**Technical pointer:** `app/Support/Payments/PaymentService.php:234` — `broken`, `low`.

### B1-04 — Old PayPal settings remain in the database, unused

**What you see:** nothing — the old PayPal enable/account/IPN controls are gone (only a read-only status line remains).

**What you'd expect:** n/a.

**What actually happens:** the old settings columns still exist but nothing reads them; PayPal is now configured on the newer payment-setup screen.

**Why it matters:** none — housekeeping only.

**Technical pointer:** no UI control; columns only — `retired`, `low`.

### C2-01 — An entrant can be charged while their entries stay unpaid

**What you see:** instructions to add a webhook in Stripe and paste its signing secret, and a status line saying whether that secret is saved.

**What you'd expect:** Stripe only becomes available to entrants once it is set up properly.

**What actually happens:** Stripe appears as a payment option as soon as the account is connected — the webhook secret is not required. If the secret is missing or wrong, the confirmation Stripe sends back is rejected, and because that confirmation is the only thing that marks entries paid, the entrant's money is taken and their entries still show as unpaid.

**Why it matters:** high — this is money collected with no matching record in the app, and it needs a manual fix each time.

**Technical pointer:** `app/Support/Payments/PaymentProviderRegistry.php:66-76`; `PayController.php:146-153` — `broken`, `high`.

### C2-02 — There is no way to set the currency code the payment system needs

**What you see:** the payment setup and Stripe screens.

**What you'd expect:** somewhere to enter the competition's official currency code.

**What actually happens:** no screen offers such a field, and nothing in the software ever writes that value — so the payment system can only fall back to whatever is available, which as B1-01 shows is often not valid.

**Why it matters:** high — this is the missing half of the currency problem, and it cannot be put right from the admin screens.

**Technical pointer:** key `prefsStripe.currency`; readers `StripeGateway.php:48`, `PaymentService.php:191-204` — `not-saved`, `high`.

### C2-03 — There is no way to disconnect Stripe

**What you see:** a "Connect with Stripe" button, and a "Remove PayPal settings" button for PayPal.

**What you'd expect:** a matching way to disconnect Stripe.

**What actually happens:** PayPal can be removed from the screen; Stripe cannot — there is no button and no route, so a connected account can only be cleared by editing the database directly.

**Why it matters:** medium — you cannot revoke or switch a connected Stripe account from the app.

**Technical pointer:** `resources/views/admin/stripe.blade.php` (no action); `StripeSettings::forget()` has no caller — `ui-only`, `medium`.

### C2-04 — PayPal payments cannot be refunded from the ledger

**What you see:** a refund icon next to payments in the payments list.

**What you'd expect:** it refunds the payment and un-confirms the entries.

**What actually happens:** the icon only appears for Stripe payments. PayPal rows show only Delete, even though the software is capable of refunding them — so a PayPal refund has to be done in PayPal itself.

**Why it matters:** medium — an operational gap; the money can still be refunded, just not from here.

**Technical pointer:** `resources/views/backoffice/payments.blade.php:40-46` — `ui-only`, `medium`.

### C2-05 — Deleting a payment leaves the entries marked paid

**What you see:** a Delete icon on each payment record.

**What you'd expect:** deleting a payment record undoes it.

**What actually happens:** it removes only the record — unlike Refund, which also un-confirms the entries — so the entries stay marked as paid with no payment behind them.

**Why it matters:** medium. The dialog does not overpromise and the old system behaved the same, so this is a decision about whether the two actions should stay different.

**Technical pointer:** `app/Http/Controllers/Admin/PaymentsController.php:56-60` — `wired`, `medium`.

## Judging

### B3-01 — "Maximum Rounds per Session" is saved but not used

**What you see:** a preference setting the maximum rounds per judging session.

**What you'd expect:** it caps how many rounds a session can have.

**What actually happens:** the value is saved and never read; each session's own round count is what actually applies.

**Why it matters:** medium — the screen suggests a control you do not really have. (We could not confirm what the original system did here; the old judging files are not in this repository.)

**Technical pointer:** `resources/views/judging/config/preferences.blade.php:170-176` — `not-consumed`, `medium`.

### B3-02 — "Minimum Words" for comments is saved but not used

**What you see:** a minimum-words setting for electronic scoresheet comments.

**What you'd expect:** judges cannot submit a comment shorter than the minimum.

**What actually happens:** the value is saved and nothing reads it, so a one-word comment is accepted with the minimum set to 25.

**Why it matters:** medium — the feedback you asked for is not enforced.

**Technical pointer:** `resources/views/judging/config/preferences.blade.php:109-113` — `not-consumed`, `medium`.

### B3-03 — "Maximum Difference for Consensus Scores" is saved but not used

**What you see:** a setting for how far apart judges' scores may be before they are treated as disagreeing.

**What you'd expect:** it sets the tolerance used when comparing scores.

**What actually happens:** the value is saved and nothing reads it — the software uses fixed thresholds instead.

**Why it matters:** medium — the tolerance you set is not the one in use.

**Technical pointer:** `resources/views/judging/config/preferences.blade.php:117-123` — `not-consumed`, `medium`.

### B3-04 — The pullsheet icons on the tables list lead to a "page not found"

**What you see:** entry-number and judging-number icons beside each table, meant to open that table's pullsheet.

**What you'd expect:** clicking one opens that table's sheet.

**What actually happens:** the address behind the two icons is built without the character that starts the options, so the site cannot find the page and returns "not found". The third icon next to them is built correctly.

**Why it matters:** medium — a routine printing shortcut is dead.

**Technical pointer:** `resources/views/judging/config/tables.blade.php:261,266` vs the correct `:269` — `broken`, `medium`.

### B3-05 — The "which people were un-assigned" warning never appears

**What you see:** a caution dialog about judges or stewards who were un-assigned when the tables mode was switched.

**What you'd expect:** when the switch forces someone off a table, the dialog tells you who.

**What actually happens:** the switch does silently remove those assignments and records a note internally, but nothing ever shows it — the dialog has no trigger.

**Why it matters:** medium — staff quietly disappear from tables with no explanation for the organiser.

**Technical pointer:** `resources/views/judging/config/tables.blade.php:153-169`; `TablesModeController.php:249` — `broken`, `medium`.

### B3-06 — Turning electronic scoresheets off does not lock them

**What you see:** an Enable/Disable switch for electronic scoresheets.

**What you'd expect:** Disabled shuts the function off.

**What actually happens:** it only hides the links. Anyone who knows the web address can still reach and use every evaluation screen.

**Why it matters:** medium — "Disable" is a display change, not a lock. It does not expose data to the public, but it is not the switch it appears to be.

**Technical pointer:** `routes/eval.php:32`; no controller reads the preference — `broken`, `medium`.

### B3-07 — The scoresheet "unique identifier" choice is ignored

**What you see:** a choice between showing judges the six-character judging number or the six-digit entry number.

**What you'd expect:** the judges' scoresheets use the one you picked.

**What actually happens:** the scoresheet header always prints the plain internal entry number, with neither option applied — the choice is used only in a help paragraph and an archive note.

**Why it matters:** medium — the identification you chose for judging is not what judges see.

**Technical pointer:** `eval/partials/scoresheet-head.blade.php:7` — `broken`, `medium`.

### B3-08 — The check-in "switch view" link does nothing

**What you see:** a link offering to switch the check-in screen to the box-and-paid layout.

**What you'd expect:** the screen switches.

**What actually happens:** the link changes the web address but the screen looks exactly the same as before.

**Why it matters:** small — an inert link.

**Technical pointer:** `resources/views/judging/checkin.blade.php:6`; `BarcodeCheckinController.php:39-49` — `ui-only`, `low`.

### B3-09 — "Judges Not Assigned to a Table" does nothing on the add/edit table form

**What you see:** a "View…" menu on the table form offering the list of un-assigned judges and stewards.

**What you'd expect:** it opens that list.

**What actually happens:** those menu items point at lists that are only drawn on the tables list page, so clicking them on the form does nothing.

**Why it matters:** small — a convenience menu that only works on one of the two pages.

**Technical pointer:** `resources/views/judging/config/table-form.blade.php:21-22` — `ui-only`, `low`.

### B3-10 — Custom "best of" categories have no control for showing places

**What you see:** the custom "best of" category form.

**What you'd expect:** something that decides whether that category's places appear on the awards display.

**What actually happens:** the awards display honours such a setting, but no screen offers it and nothing writes it — only categories carried over from the old system have it set.

**Why it matters:** small — the awards display follows a setting you cannot reach.

**Technical pointer:** `AwardDeckBuilder.php:216,235`; `SpecialBestController.php:120-125` — `not-saved`, `low`.

### B3-11 — The drop-off help text promises a public map that does not exist — decision needed

**What you see:** help text saying drop-off locations are displayed publicly with a link to a map and driving directions.

**What you'd expect:** the public site lists your drop-off locations with map links.

**What actually happens:** no public page lists drop-off locations — they feed an admin report, the entrant's own profile line and counts only. There are no map links for drop-offs anywhere (though the pattern exists for judging sessions).

**Why it matters:** small, but it is a promise on screen that the site does not keep. **A decision is needed** because we could not establish whether a public drop-off map was ever intended for this competition.

**Technical pointer:** `resources/views/judging/config/dropoff.blade.php:19-34` — `needs-decision`, `low`.

## Competition Info and other admin screens

### C1-01 — Choosing banner images does not change the homepage banner

**What you see:** a Banner Images screen where you upload images and tick which ones are shown on the homepage, described as being picked at random.

**What you'd expect:** only your ticked images appear on the homepage.

**What actually happens:** your selection is saved and re-read only by that same screen; the homepage picks its banner from a fixed, built-in set, so ticking or uploading images changes nothing there.

**Why it matters:** medium — the whole screen has no effect on the public site.

**Technical pointer:** `resources/views/admin/hero-images.blade.php:74-84`; `PublicController.php:877-884` — `not-consumed`, `medium`.

### C1-02 — A module set to extend an admin screen renders nowhere

**What you see:** on the Modules screen, a setting that lets a module extend an admin area (dashboard, entry administration, scoring and so on).

**What you'd expect:** the module appears in that admin area.

**What actually happens:** the setting is saved and never read, and the only place modules can render is the public site — so an admin-extending module appears nowhere.

**Why it matters:** medium — the option promises something the software cannot do.

**Technical pointer:** `resources/views/admin/mods.blade.php:95-121`; `ModsController.php:101-120` — `not-consumed`, `medium`.

### C1-03 — Four long text areas on Competition Info are never shown

**What you see:** four text areas on Competition Info for bottles, awards structure, Best of Show award and circuit qualifying events.

**What you'd expect:** this text appears on the public rules and information pages.

**What actually happens:** all four are saved and shown back to you on the admin form, but nothing on the public site renders them — only the separate Rules text does.

**Why it matters:** medium — you can write pages of information that no entrant ever sees.

**Technical pointer:** `resources/views/admin/competition-info.blade.php:371-375,425-447` — `not-consumed`, `medium`.

### C1-04 — The "single file upload" link always shows the multiple-file uploader

**What you see:** a note that a single-file upload option is available as an alternative.

**What you'd expect:** following it opens the single-file uploader.

**What actually happens:** the screen ignores the request and always shows the multiple-file uploader. (On the other upload screen, the equivalent link does work.)

**Why it matters:** small — misleading text; uploading still works.

**Technical pointer:** `resources/views/admin/upload-scoresheets.blade.php:14`; `UploadScoresheetsController.php:22-28` — `ui-only`, `low`.

## Styles, Entries and Participants

### C3-03 — The delete-account icon on the participants list returns an error

**What you see:** a delete icon in the "Participants with Entries" view, with a confirmation prompt describing what it will delete.

**What you'd expect:** confirming deletes the participant and their entries.

**What actually happens:** the icon is the wrong kind of request for the delete operation, so the page returns an error and nothing is deleted; the confirmation prompt also has no code behind it. The same action in the other view is correct.

**Why it matters:** medium — a destructive-looking action that quietly fails.

**Technical pointer:** `resources/views/admin/participants.blade.php:307`; `routes/backoffice.php:21` — `broken`, `medium`.

### C3-04 — The Participants page can crash entirely

**What you see:** the Participants page, with an "Assigned As" link that opens a small window listing the tables a person is assigned to.

**What you'd expect:** the page loads and the window lists their tables.

**What actually happens:** on competitions carrying assignment information from the old system, opening that window tries to display a data structure as if it were plain text, and the whole Participants page returns a serious error instead of loading.

**Why it matters:** high on those competitions — an admin screen becomes unusable. A fresh competition is not affected. A related crash elsewhere on this page was already fixed; this second one was not.

**Technical pointer:** `resources/views/admin/participants.blade.php:150-190`; `ParticipantsController.php:125-135` — `broken`, `high`.

### C3-05 — The "Assigned As" column prints computer data instead of a role

**What you see:** an "Assigned As" column meant to show Judge, Steward or Staff.

**What you'd expect:** a plain role name.

**What actually happens:** the column holds a different kind of data than the column assumes, so it prints a mangled block of computer text — and the built-in check for the word "Judge" is looking at the wrong content.

**Why it matters:** medium — staff roles are shown incorrectly on the participants list.

**Technical pointer:** `resources/views/admin/participants.blade.php:150,292,349-352` — `broken`, `medium`.

### C3-06 — The participants print view crashes (only reachable by a hand-made address)

**What you see:** a "Location(s) Available" column on the printable judges/stewards list.

**What you'd expect:** the print view opens.

**What actually happens:** the page tries to use a piece of code as if it were a list and fails. It only happens if you reach that print view by typing the address — no link in the app takes you there.

**Why it matters:** small — no button leads to it.

**Technical pointer:** `resources/views/admin/participants-print.blade.php:44` — `broken`, `low`.

### C3-08 — The purge dashboard repeats every card three times

**What you see:** the purge and reset screen, listing destructive actions.

**What you'd expect:** one card per action.

**What actually happens:** a repeated section is never properly closed, so every one of the fifteen actions is drawn three times — about forty-five cards, with duplicated labels and internal identifiers. Each card still runs its own correct action.

**Why it matters:** medium — a dangerous screen looks chaotic and it is easy to click the wrong thing.

**Technical pointer:** `resources/views/admin/purge.blade.php:12-47` — `broken`, `medium`.

### C3-09 — Purge and Archive permissions don't match the entries purge — decision needed

**What you see:** the Purge dashboard and the Archive page, including "Purge ALL Data" and "Purge Participants".

**What you'd expect:** the most destructive resets to be restricted at least as tightly as the smaller ones.

**What actually happens:** the big resets can be run by a level-1 admin, while the much narrower purge on the entries list requires the top-level admin. Both still require the confirmation step.

**Why it matters:** medium. **A decision is needed** — the notes that explain the original intention refer to documents that are not in this repository, so we could not confirm whether the difference was deliberate.

**Technical pointer:** `routes/archive.php:11-22` vs `routes/backoffice.php:43-44` — `needs-decision`, `medium`.

### C3-10 — The inline judging-number box accepts anything

**What you see:** an editable judging-number cell on the entries list.

**What you'd expect:** a six-character judging number, checked like every other place that sets one.

**What actually happens:** this particular cell saves whatever is typed, with no length or character check at all, while the edit-entry screen does check it. Judging numbers are what barcodes and QR codes read.

**Why it matters:** medium — a bad judging number here can break scanning and report ordering.

**Technical pointer:** `app/Http/Controllers/Admin/EntriesController.php:276-306` — `broken`, `medium`.

### C3-11 — "Are you sure?" warnings are browser-only

**What you see:** confirmation prompts before the Admin Actions on the entries list (mark all as paid/received/confirmed, purge, regenerate numbers) and before deleting a row.

**What you'd expect:** the confirmation is required before the action runs.

**What actually happens:** the warning exists only in your browser. The action is protected by login and role checks but not by a confirmation the server re-checks, so a direct request skips the warning. (The purge and archive screens, by contrast, do enforce it.)

**Why it matters:** small — it matches the original system, and only someone deliberately bypassing the screen is affected.

**Technical pointer:** `resources/views/admin/entries.blade.php:124-177` — `wired`, `low`.

## Outputs and Reports

### D1-01 — Four Results print options all produce the same document

**What you see:** a Results menu offering "All with Scores", "Winners Only with Scores", "All without Scores" and an HTML option, plus three sort orders.

**What you'd expect:** four different documents, and sorting that sorts.

**What actually happens:** only the section choice is used — every variant returns the same winners-only PDF, the sort options do nothing, and the HTML option returns a PDF as well.

**Why it matters:** high — the reports you choose are not the reports you get, which can mislead results work.

**Technical pointer:** `app/Http/Controllers/Output/ResultsController.php:40` — `broken`, `high`.

### D1-02 — Every Participant Summary link produces the same list

**What you see:** a "Print Current View" menu offering orderings such as by last name, by club, by judge ID and by judge rank, plus similar dashboard links.

**What you'd expect:** one list per ordering and filter.

**What actually happens:** none of those options is read, so every link produces the identical all-participants list in one fixed order.

**Why it matters:** medium — five different reports are really one.

**Technical pointer:** `app/Http/Controllers/Output/ParticipantSummaryController.php:30-32` — `broken`, `medium`.

### D1-03 — "Table Cards for Session…" ignores the session

**What you see:** a "For Session…" menu offering one card set per judging session.

**What you'd expect:** cards for the session you chose.

**What actually happens:** the session choice is ignored, so every session's link returns the same set of all tables for that round.

**Why it matters:** medium — session-specific printing is not possible.

**Technical pointer:** `app/Http/Controllers/Output/TableCardsController.php:54-71` — `broken`, `medium`.

### D1-04 — All eighteen Data Export links download the same file

**What you see:** a list of export links with names like available judges, assigned stewards, winners, circuit, MHP members, paid, non-paid and required information.

**What you'd expect:** each link gives you the dataset it names.

**What actually happens:** only the file name changes — every link downloads the full all-entries file, so a file named "winners" contains everything.

**Why it matters:** high. Anyone relying on these for medals, circuit points or BJCP work would be working from the wrong data without any warning on screen.

**Technical pointer:** `app/Http/Controllers/Output/ExportController.php:63-84,102` — `broken`, `high`.

### D1-05 — The printed entries sheet never uses the Professional layout

**What you see:** on a Professional competition, the printed entries list is expected to show an Organization column and hide Club.

**What you'd expect:** the Professional layout.

**What actually happens:** the software looks up the setting under the wrong name, so it always thinks you are on the amateur edition and prints the amateur headings and columns.

**Why it matters:** small — the printed sheet looks wrong on Professional competitions only.

**Technical pointer:** `app/Http/Controllers/Output/EntriesPrintController.php:53` — `broken`, `low`.

### D1-06 — The BJCP Points "Print" and "PDF" buttons do the same thing

**What you see:** Print, PDF and XML links for the BJCP points report.

**What you'd expect:** Print opens a printable page; PDF downloads a file.

**What actually happens:** only the XML option is handled; Print and PDF both open the same PDF in the browser, and it is never downloaded as a file.

**Why it matters:** small — you get the report either way.

**Technical pointer:** `app/Http/Controllers/Output/StaffPointsController.php:65` — `ui-only`, `low`.

## Public and entrant screens

### D2-01 — Hiding the MHP field destroys stored MHP numbers

**What you see:** an MHP number field on the entrant's club/account page, which disappears when the MHP option is switched off.

**What you'd expect:** hiding the field just hides it.

**What actually happens:** because the field is absent from the page, saving that page stores an empty value over whatever was there — so any entrant who edits their club details while MHP is switched off loses their stored MHP number. Registration still asks for it, and Professional competitions always switch it off, so this is easy to hit.

**Why it matters:** high — this is silent data loss, and the number cannot be recovered.

**Technical pointer:** `app/Http/Controllers/BrewerForm1Controller.php:64`; `brewer/clubs.blade.php:67` — `broken`, `high`.

### D2-02 — A disabled contact form can still send messages

**What you see:** a contact-form setting: show the form, show only a list of officials, or show nothing.

**What you'd expect:** disabling the form stops messages being sent.

**What actually happens:** the screen hides the form, but the page that sends the message never checks the setting — so a message posted directly still reaches the official you chose.

**Why it matters:** medium — "disabled" is a display change only, which matters if you turned it off to stop spam.

**Technical pointer:** `app/Http/Controllers/PublicController.php:354-393` — `broken`, `medium`.

### D2-03 — The judges' scoresheet-label links don't work for judges

**What you see:** on the account page, links for a judge to print their own scoresheet labels (Letter or A4).

**What you'd expect:** a judge can print their labels.

**What actually happens:** those links point at a page reserved for administrators, so a judge is bounced away with an error. The equivalent *entry* label links use the correct public page.

**Why it matters:** medium — the feature is unusable by the people it is for.

**Technical pointer:** `resources/views/public/partials/account-main.blade.php:61-64`; `routes/outputs.php:10-25` — `broken`, `medium`.

### D2-04 — Required entry fields are checked when editing but not when adding

**What you see:** entry fields marked with an asterisk, such as Required Info for certain styles.

**What you'd expect:** the asterisk means you cannot submit without filling them in.

**What actually happens:** editing an entry applies the check and marks the entry unconfirmed if something is missing; adding an entry does not check at all, so a first submission with Required Info blank is accepted as confirmed and is never cleaned up.

**Why it matters:** medium — incomplete entries can reach judging, and the automatic tidy-up does not catch them.

**Technical pointer:** `app/Http/Controllers/BrewController.php:247` vs `:419-432` — `broken`, `medium`.

### D2-05 — The QR check-in number box accepts anything

**What you see:** a "Judging Number" box during QR check-in, described as six numbers with leading zeros.

**What you'd expect:** the entry is refused or corrected if the number is not right.

**What actually happens:** the limit is only in the browser. The server accepts whatever arrives, so text like "abc" is stored as a judging number.

**Why it matters:** small — a bad number here complicates sorting, and it can be corrected afterwards.

**Technical pointer:** `resources/views/qr/checkin.blade.php:87`; `QrCheckinController.php:96-110` — `broken`, `low`.

### D2-06 — Turning MHP off does not remove it from registration

**What you see:** the MHP switch, described as enabling or disabling the ability for entrants to enter their MHP number when adding or editing their account.

**What you'd expect:** switched off, entrants cannot enter an MHP number.

**What actually happens:** the registration page still asks for it and still saves it; only the display row and the club-page field are switched off. This combines badly with D2-01, which then wipes what was saved.

**Why it matters:** small on its own — it is a promise the setting does not keep.

**Technical pointer:** `resources/views/auth/register.blade.php:239-242`; `RegisterController.php:145,268` — `broken`, `low`.

## Sign-in, evaluation and the install wizard

### D3-01 — The waiver is recorded as accepted even when it isn't

**What you see:** on registration, a tick-box saying the registrant accepts the waiver, marked as required.

**What you'd expect:** no judge or steward can be registered without accepting it.

**What actually happens:** the requirement exists only in the browser. If the tick is missing, the server stores "Yes" for it anyway — and the sign-in sheets then print that person as having accepted.

**Why it matters:** medium — consent is recorded for people who never gave it.

**Technical pointer:** `app/Http/Controllers/Auth/RegisterController.php:159,266` — `broken`, `medium`.

### D3-02 — Password reset has no limit and confirms which emails exist

**What you see:** the forgotten-password flow, protected by a security question.

**What you'd expect:** repeated guesses are slowed or stopped, and the first step does not reveal whether an address is registered.

**What actually happens:** the security answer is checked properly, but none of the reset steps have any rate limit — unlike registration and email verification — and the first step tells a caller whether an address exists.

**Why it matters:** medium — it makes guessing answers and building a list of valid addresses easier than it should be.

**Technical pointer:** `routes/web.php:140-145`; `ForgotPasswordController.php:90-154` — `broken`, `medium`.

### D3-03 — Sign-in has no attempt limit — decision needed

**What you see:** the login form.

**What you'd expect:** repeated failed attempts are slowed or locked out.

**What actually happens:** there is no rate limit and no failed-attempt counter. A failure only clears the session and, on a production server, writes a line to the server log.

**Why it matters:** medium. **A decision is needed** — this matches how the original system behaved, so it may be an accepted risk rather than an oversight.

**Technical pointer:** `routes/web.php:72`; `LoginController.php:71-119` — `needs-decision`, `medium`.

### D3-04 — The new password has no minimum length

**What you see:** the Change Password screen, with an old-password box, a new-password box and a strength meter.

**What you'd expect:** the strength meter means weak passwords are refused.

**What actually happens:** the old password is checked correctly, but the new password has no minimum length — unlike registration and password reset, which require eight characters.

**Why it matters:** small — it matches the original system, where the strength meter was only a hint.

**Technical pointer:** `app/Http/Controllers/Auth/ChangePasswordController.php:36-40` — `wired`, `low`.

### D3-05 — The security question on the reset page can be faked in the address

**What you see:** the password-reset page displaying your security question.

**What you'd expect:** the question shown is the one stored on the account.

**What actually happens:** the page takes the question text straight from the web address, so a hand-made link can display any question the sender likes. It does not let anyone through — the answer is still checked against the real stored answer — but it can be used to show a convincing, genuine-looking fake prompt.

**Why it matters:** small — it is a trick-ability issue, not a way into accounts.

**Technical pointer:** `app/Http/Controllers/Auth/ForgotPasswordController.php:46-52` — `broken`, `low`.

### D3-06 — The structured scoresheet cannot be read back

**What you see:** a judge fills in the structured scoresheet, ticking the various characteristics.

**What you'd expect:** opening that evaluation again shows the ticks as saved.

**What actually happens:** the ticks are stored and the entry form shows them, but the page used to view a saved evaluation does not handle this sheet type — it falls back to a generic layout and the ticks are invisible.

**Why it matters:** medium — the evaluation record looks empty when reviewed.

**Technical pointer:** `eval/output.blade.php:39-44` — `broken`, `medium`.

### D3-07 — The import confirmation page is unreachable

**What you see:** an "Import Score Data" button on the evaluation dashboard.

**What you'd expect:** a confirmation step explaining the rules before official scores are written.

**What actually happens:** the button imports immediately. The confirmation page that was built — the only place the import rules are stated, for example that single-judge evaluations are not imported — is linked from nowhere.

**Why it matters:** medium — an admin can overwrite official figures without seeing the rules.

**Technical pointer:** `eval/dashboard.blade.php:41-44`; orphaned `eval/import.blade.php` — `broken`, `medium`.

### D3-08 — A record-counting web address answers anyone — decision needed

**What you see:** nothing in the interface directly.

**What you'd expect:** internal counting requests to require a login.

**What actually happens:** the web address that counts evaluation records answers without any login, unlike the equivalent save request. It returns only a count, not the records themselves.

**Why it matters:** small. **A decision is needed** — this may be intentional for the dashboard, and it exposes no personal or score data.

**Technical pointer:** `routes/web.php:245`; `AjaxController.php` — `needs-decision`, `low`.

### D3-09 — The installer cannot proceed without JavaScript

**What you see:** the database step of the install wizard, with a "Next" button.

**What you'd expect:** you fill in the details and press Next.

**What actually happens:** the button starts disabled and is only switched on by JavaScript after a connection test. If JavaScript is unavailable, the install cannot continue — even though the server performs its own connection test anyway.

**Why it matters:** small — it only affects installs on browsers or setups without JavaScript.

**Technical pointer:** `wizard/install/database.blade.php:53,60-105` — `broken`, `low`.

### D3-10 — The upgrade acknowledgement is a browser-only gesture

**What you see:** on the upgrade screen, a tick-box saying you understand the site will be briefly unavailable, before "Upgrade Now" becomes clickable.

**What you'd expect:** the update cannot start until you have acknowledged.

**What actually happens:** the tick only enables the button in your browser; the update itself has no record of whether it was ticked.

**Why it matters:** small, and worth stating plainly: this is an acknowledgement, not a safety check, so there is nothing to fix unless you want it to be enforced.

**Technical pointer:** `wizard/upgrade/confirm.blade.php:24-33,47-52` — `wired`, `low`.

## What happens next

Nothing has been changed. Every item above is listed so you can decide what to do about it — fix it, accept it as it is, or leave it for later. The technical table in `docs/unwired-features-audit.md` has a blank decision column for each row, and this document follows the same order and the same reference codes.
