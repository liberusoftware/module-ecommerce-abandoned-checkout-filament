# Runbook

## Nothing has been sent, and the panel shows no attempts

Not a fault. Open **Outreach**. It names, separately:

- whether a linker is bound;
- for each channel key this merchant's campaigns name, whether anything is bound to it;
- whether anything has been recorded as abandoned at all;
- whether anything has been attempted;
- every refusal, by reason, with a sentence for each.

An empty **Abandonments** table means nothing has called `Actions\RecordAbandonment`. An empty
attempt list under one abandonment means nobody has asked, which is not the same as asking having
failed — a refusal is a row.

## Every screen raises `RuntimeException: could not resolve a tenant`

The panel has no Filament tenant and the plugin was attached without `tenantUsing()`. Set one. This
is deliberate: there is no "show everything" to fall back to.

## Outreach says a key is configured and the container cannot build it

The value in `config/abandoned_checkout.channels` or `abandoned_checkout.link` is a class name that
does not resolve, or resolves to something that is not the contract. Fix the class name or the
binding. This is not the unbound state and binding something else will not help: something is already
configured there.

## A campaign's conditions render as "a condition names a field the module does not publish"

A condition was written into `abandoned_checkout_campaigns.conditions` directly rather than through
`Actions\DefineCampaign`. The panel's form takes every field and every operator from a domain enum,
so it cannot produce one. Redefine the campaign from the listing — naming an existing one redefines
it.

## A basket shows "the amount cannot be read back as it was stored"

The row's `currency` or `currency_exponent` is not something `Data\Money` accepts, or a stored line
is missing a key `Data\Line` requires. The domain rebuilds both lazily, so the row was written by
something that bypassed `Actions\RecordAbandonment`. The figure is suppressed rather than guessed;
find the writer.

## An attempt says "whether this went at all is not known"

The attempt is `Pending`: the claim was written and the process died before the send settled it.
`Queries\ListAttempts` reads its silence as though the message had gone, so the panel names both
possibilities. There is no repair action — the domain publishes none, and inventing one here would be
this surface deciding what happened.

## The counts on Outreach disagree with the Abandonments table

They cannot within one request: every figure comes from `Support\Snapshot`, which reads once and
shares. Across two requests they can, because time moved and the state is derived. Reload.

## A screen shows another merchant's row

It cannot. If one appears, `tenantUsing()` is returning the wrong key. Every query on every screen
and in both widgets states the tenant, every custody check is keyed on the row rather than on a
reference two merchants can both hold, and `CustodyTest` proves both with two merchants holding
deliberately identical values.
