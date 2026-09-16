# Site-preferences wiring — control disposition

Date: 2026-09-16
Scope: `/admin/site-preferences` (five tabs) + the entry/payment paths its
controls feed.

An audit traced every control from the form through the controller to the code
that reads the stored value. Fifteen controls saved a value nothing consumed.
Each is now either wired to real behaviour or removed. This file records the
disposition so a future change does not silently re-introduce a dead control.

## General tab

| Control | Stored as | Before | Now |
|---|---|---|---|
| Search Engine Friendly URLs | `prefsSEF` | Read by nothing | **Removed** — Laravel always serves clean URLs, so the toggle could never have an effect (column kept) |
| Custom Modules | `prefsUseMods` | Live | unchanged |
| Automatically Purge… | `prefsAutoPurge` | Read by nothing (legacy cron path not ported) | **Replaced** by a "Purge stale entries now" action running the legacy 24-hour rule on demand (`EntryPurge`) |
| Records Displayed | `prefsRecordPaging` | Wrote a column nothing read; tables hard-coded `data-dt-page="25"` | Drives the DataTables page size on the participants/entries/pool-assign tables |
| Hide Brewer's Specifics | `prefsSpecific` | Read by nothing | Hides the `brewComments` field; an absent field preserves the stored value |
| Everything else | — | Live | unchanged |

## Entries tab

| Control | Stored as | Before | Now |
|---|---|---|---|
| Entry Limits per <set> Style (by group) | `prefsStyleLimits` (JSON) | Read by nothing | Enforced per participant (`EntryLimits::checkCapacity`) |
| Entry Limit Method "By Table/Medal Group" | `prefsStyleLimits = '2'` | Read by nothing | Enforced via `judging_tables.tableEntryLimit` |
| Per-style-type limits | `style_types.styleTypeEntryLimit` | Read by nothing | Enforced |
| #1–#4 Incremental Entry Limits | `prefsUserEntryLimitDates` | Read by nothing | Enforced — the active tier's limit caps the participant while its window is open (`EntryLimits::incrementalLimit`) |
| Member Discount Password | `contest_info.contestEntryFeePassword` | Compared to nothing; nothing set `brewerDiscount`, so the member rate was unreachable | The password is entered on the entry form and grants the member rate by setting `brewer.brewerDiscount='Y'` |
| Per-participant / per-sub-category caps | `prefsUserEntryLimit`, `prefsUserSubCatLimit`, `prefsUSCLEx*` | Enforced on add only | Enforced on add **and** edit (counts exclude the edited row) |
| Everything else | — | Live | unchanged |

Enforcement redirects with `/list?msg=` codes: `8` user cap, `9` sub-category
cap, `12` style/table cap. The add and edit paths both run the checks; counts
exclude the row being edited so a benign re-save never trips a cap it already
met.

## Email & Contact tab

| Control | Stored as | Before | Now |
|---|---|---|---|
| SMTP Settings Test Yes/No | `send-test-email` | Posted, never read | **Removed** — the "Test Current Email Sending Settings" button runs the test |
| Contact Form CC | `prefsEmailCC` | Read by nothing | Copies the sender on the contact message |
| Everything else | — | Live | unchanged |

## Currency and payments tab

| Control | Stored as | Before | Now |
|---|---|---|---|
| Pay to Print? | `prefsPayToPrint` | Read by nothing | Gates the entrant's own label printing (`/list/labels`) |
| Accept Cash? / Accept Checks? | `prefsCash`, `prefsCheck` | Read by nothing | Gate the manual mark-as-paid method list (`ManualGateway::methodsFor`) |
| Checks Payable To | `prefsCheckPayee` | Read by nothing | Shown on the check payment confirmation |
| Checkout Fees Paid by Entrant | `prefsTransFee` (+ new `prefsTransFeePercent` / `prefsTransFeeFixed`) | Read by nothing — no rate existed | Adds the configured percentage + fixed amount to the checkout/manual total (`FeeCalculator::forEntrant`) |

A method is treated as enabled unless it was explicitly stored off (`'0'`/`'N'`),
so an install that never touched the switches keeps its full method list.

## Best Brewer and/or Club tab

All keys were already live (scoring, titles, points, tie-breaks) — unchanged.

## Still inert (deliberately, no control offered)

- `prefsHideRecipe` — no UI, no consumer. Left as a legacy column.
- `styles.brewStyleAtLimit` — written/cleared by the styles admin, never
  consulted at entry time. Not part of this change.
