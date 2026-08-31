# The panel

What each screen is for, what it deliberately does not offer, and the decisions behind both.

## 1. What this package is

A one-to-one adapter over `liberusoftware/ecommerce-abandoned-checkout`. It contains no business
rules: every decision is the domain package's, reached through its published actions, queries and
policies. Where this package found itself wanting to compute something, that is recorded below as a
gap in the domain rather than closed here.

## 2. The decision the panel carries

Every end of the host's pipeline was unattached. No job wrote an abandoned basket, nothing read the
campaign bodies, the recovery URL called a route no file in `routes/` declared, and the page that
route would have reached did not exist. Four missing joints, and one appearance on screen: nothing
happens.

The domain turns each joint into a seam that is **unbound by default**, and an action that needs an
unbound seam records a **refusal with a reason** on the attempt and returns. That is the fact this
panel exists to render.

So an attempt that did not go is never an empty row here. It is a row with `NoChannelBound` or
`NoLinkerBound` on it and a sentence saying what that means. A merchant on a fresh install sees the
joint that is not attached, not a table that reads as "nothing to do".

`Support\Render::refusal()` holds one sentence for each of the thirteen reasons the domain publishes,
in a `match` with no default arm, so a reason the domain adds is a hole the suite finds rather than a
blank on a screen.

## 3. Nothing heard is three answers

`Data\AttemptDescription::$silence` carries `NotYet`, `NeverWill` or `CannotKnow`, and the attempts
table renders all three as three. `CannotKnow` is the honest answer whenever the bound transport
reports nothing back, and it is not the same as "no".

**Where the domain cannot support the distinction.** `Queries\ListAttempts::silence()` branches on
`AttemptState::Refused` alone, so a `Pending` attempt — a claim written before the send and never
settled — is reported as `NotYet`. That is precisely the thing `AttemptState::Pending`'s own docblock
says the module does not know. `Support\Render::unsettled()` answers **no way to know** instead, and
says why; `Widgets\OutreachStanding` counts unsettled claims on their own line rather than folding
them into sent or refused. The `-api` and `-livewire` surfaces compensate identically, so all three
give one answer. The fix belongs one branch earlier in the domain and is recorded as a gap below.

## 4. Money

`Data\Money` carries the minor amount, the ISO 4217 code **and the exponent**, so
`Support\Render::money()` places the decimal point from what it was given rather than guessing:
2500 GBP renders as `25.00 GBP` and 2500 JPY as `2500 JPY`. The digits are placed by string, so no
division and no float is involved, and `->money()` appears nowhere — the host's own orders widget
hard-coded `->money('USD')` and drew every order in dollars whatever currency it was taken in.

`Data\Condition` is the exception, and not by choice: it carries a currency and **no exponent**, so
an amount threshold on a campaign is rendered as minor units with its code beside it and said to be.
Placing a decimal point there would be this package inventing an exponent it was not given.

## 5. The state is derived, so it is not sorted and not filtered

`Abandonment` has no `state` column. `Models\Abandonment::stateAt()` derives it from `expires_at`,
`stopped_at` and `stop_reason` against an instant. The column shows it and says it is derived; there
is no sort and no filter on it, because the database has nothing to index. `stop_reason` is the
stored half, and it carries both.

## 6. One clock, one read

`Support\Snapshot` is the only file that calls `CarbonImmutable::now()`, and
`ModuleBoundaryRulesTest` pins that. Every window, delay and expiry on a page is measured from that
one instant, so a page rendered across the moment an abandonment expires cannot show one widget
calling it open and another calling it expired. The same object memoises each domain read for the
request, so five components on one page do not run the same query five times.

## 7. Widgets

A widget carries no resource abilities at all and `Filament\Widgets\Widget::canView()` defaults to
`true`; no panel scope reaches a widget's query. Both widgets override `canView()` and state the
tenant in every read, and both are constructed by the suite and asserted through a **public accessor
returning structured rows** — `OutreachStanding::lines()` and `DueNow::dueIds()` — rather than by
grepping rendered markup.

`grep -rn 'Widgets\\' tests/` returns nothing across the whole host tree, which is why its
`TopProductsWidget` shipped a fatal unknown-column error for every panel user whose team owned a
store without CI ever noticing.

## 8. Actions, and why neither is guarded

`ViewAbandonment` offers two: `AttemptRecovery` and `StopOutreach`. Neither carries a `visible()`
guard.

Filament re-evaluates `visible()` against a freshly hydrated record at mount *and* at
`callMountedAction`, so a guard restating "this is already stopped" or "this has expired" makes the
domain's own refusal unreachable through the panel — and an unreachable branch is unreachable
against a 100% coverage bar. Both are the panel restating a rule the domain owns, which is the host
fault this module was extracted over. **The guards are dropped and the branches stay**, and
`AbandonmentTest` reaches `OutreachAlreadyStopped` through the panel to prove it.

## 9. What this panel deliberately does not offer

| | |
|---|---|
| A control that records an outcome | An outcome is an observation. A merchant clicking "delivered" on a message nobody can see the fate of would be this surface inventing the fact the module exists to keep honest. The three silences are already reachable without one: refused gives `NeverWill`, an unreporting transport gives `CannotKnow`, and a reporting one gives `NotYet` until expiry |
| A control that records an abandonment | Deciding a checkout was abandoned belongs to whatever owns the checkout. The module owns no sweeper and neither does its panel |
| Create, edit or delete, anywhere | An abandonment is a statement about a moment that has passed. A campaign is changed by defining it again. A delete would cascade to attempts and outcomes, and an outcome raises from its own `deleting` hook — an ability that fatals is still an ability offered |
| The cross-tenant export and erasure | Both say in their names that they span merchants. A screen scoped to one must not carry a control that acts on all |
| A sweep or a schedule | The package schedules nothing, and neither does the domain. An attempt is one send of one campaign against one abandonment, and that is the only shape offered here |

## 10. Gaps found in the domain package

Reported rather than closed here, per §1. None of them has an issue number yet — the domain
repository has no open issues — so they are described rather than cited.

1. **No published aggregate over attempts, and no count of abandonments.** `Support\Snapshot`
   counts rows itself, with the tenant stated, in one file. Everything else on these screens comes
   through `ListCampaigns`, `ListAttempts` or `ListDueAbandonments`.
2. **`Support\Seams::channel()` and `Seams::link()` raise rather than refuse** when the configured
   value is a class name the container cannot build: `App::make()` throws
   `BindingResolutionException`, which is neither the seam's `null` nor a refusal. Unguarded, a typo
   in a host's config is a 500 on every screen here. `Snapshot` catches it and reports a third fact —
   configured and unbuildable — which is not the same as unbound, and telling an operator to bind
   something already bound is the wrong instruction.
3. **`Data\Condition` carries a currency and no exponent**, where `Data\Money` carries both. An
   amount threshold cannot be rendered as an amount, so it is rendered as minor units and said to be.
4. **`Models\Abandonment::total()` and `lineList()` rebuild value objects lazily**, so a row whose
   currency or stored lines the domain will not accept raises `MoneyIsInvalid` or `LineIsInvalid`
   while a column renders rather than when the query ran. A column that throws mid-render is a broken
   page. Every read of both goes through `AbandonmentResource::amount()`, `::items()` and `::lines()`,
   which render the fact that the value cannot be read back rather than a figure this screen invented.
5. **`Queries\ListAttempts::silence()` collapses `Pending` into `Sent`** — see §3.
6. **Nothing guards a string against its own column width.** `checkout_ref` is 128, `contact_ref`
   is 191, `channel_key` is 32, `name` is 128, and no action or value object checks any of them, so
   an over-long value is a database error on MySQL or a silent truncation depending on the mode —
   neither of which the module can classify as a refusal. `Data\Condition`'s currency is likewise
   unvalidated where `Data\Money`'s is. This panel writes `name`, `channel_key` and a condition's
   currency, and bounds all three in the form schema. **That is not the fix**: a form rule binds one
   caller, and the real check belongs in the value objects. This panel writes neither `checkout_ref`
   nor `contact_ref` at all.
7. **No published read for a single abandonment.** `ListDueAbandonments` returns only the due ones,
   so a merchant looking at an expired or stopped checkout cannot reach it through a query. The
   resource is model-bound, as a Filament resource is by construction, and states the tenant in
   `getEloquentQuery()`; no page here works around the gap by reaching past that.
