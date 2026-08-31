# Changelog

## 0.1.0

The operator's view of `liberusoftware/ecommerce-abandoned-checkout`, as a Filament 5 plugin a host
attaches to a panel it owns. Two resources, one page, two widgets, one relation manager, and no
business rule of its own.

### The decision it carries

An attempt that did not go renders the refusal that stopped it, with a sentence for each of the
thirteen reasons the domain publishes. On a fresh install — where both seams are unbound, which is
the honest description of one — the panel names the joint that is not attached rather than showing an
empty table that reads as "nothing to do".

"Nothing heard" renders as three answers and not as a blank: *not yet*, *never will*, and *no way to
know*, the last being what a bound transport that reports nothing back actually leaves behind.

### Decisions

- Money is placed by the exponent the domain stores beside the amount, by string, so no division and
  no float touches a figure and `->money()` appears nowhere.
- The state an abandonment has reached is derived, and is shown as derived: no sort and no filter,
  because the database has nothing to index. `stop_reason` is the stored half and carries both.
- One clock per request, read in one file, pinned by a boundary test. Every domain read is memoised
  for the request and shared.
- Every widget overrides `canView()` and states the tenant in its own query, and both are constructed
  by the suite and asserted through a public accessor returning structured rows.
- Neither header action carries a `visible()` guard: Filament re-evaluates one against a freshly
  hydrated record, so a guard would make the domain's own refusal unreachable. The guards are dropped
  and the branches stay.
- Every custody check is keyed on the row rather than on a reference two merchants can both hold.
- No contact and no subject reference appears on any listing; a redacted one renders as a fact.

### Deliberately not shipped

- **A control that records an outcome.** An outcome is an observation, and a merchant clicking
  "delivered" on a message nobody can see the fate of would be this surface inventing the fact the
  module exists to keep honest. All three silences are reachable without one.
- **A control that records an abandonment**, and any sweep or schedule. Deciding a checkout was
  abandoned belongs to whatever owns the checkout.
- **The cross-tenant export and erasure.** Both say in their names that they span merchants.
- **Create, edit and delete**, everywhere.

### Gaps found in the domain package

Reported rather than closed here. `docs/panel.md` §10 carries them in full: no published aggregate
over attempts; `Seams::channel()` and `Seams::link()` raising rather than refusing on a class name the
container cannot build; `Data\Condition` carrying a currency and no exponent, and not validating the
currency it does carry; `total()` and `lineList()` able to raise while a column renders;
`ListAttempts::silence()` collapsing `Pending` into `Sent`; nothing guarding a string against its own
column width; and no published read for a single abandonment.
