# Adoption

## Install

```bash
composer require liberusoftware/ecommerce-abandoned-checkout-filament
```

The domain package is not on Packagist, so a host adds the same `repositories` entry this package
carries:

```json
{ "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-abandoned-checkout" }
```

Installing boots nothing. `extra.laravel.providers` is absent on purpose; the module manager
registers `AbandonedCheckoutFilamentServiceProvider` only when the module is named in
`MODULES_ENABLED`.

## Attach it to a panel

The provider registers nothing. Every screen and both widgets arrive through the plugin, so the host
decides which panel gets them.

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\AbandonedCheckout\Filament\AbandonedCheckoutPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(
        AbandonedCheckoutPlugin::make()
            ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
    );
}
```

`tenantUsing()` is how this panel names the merchant. Without it, the panel's own Filament tenant is
used. There is no fallback to "no tenant": `where('tenant_id', null)` compiles to `is null` and lists
exactly the orphan rows a scope exists to hide, so an unresolvable tenant raises and every screen and
both widgets refuse to render.

In the host, Filament tenancy resolves the `Team`, and `Team` is the tenant boundary. Derive the
domain's `tenantId` from it once, here.

## Bind the seams, or read why nothing goes

Nothing is bound as the domain ships. That is not an error, and every screen here says so rather than
showing an empty table.

```php
// config/abandoned_checkout.php
return [
    'channels' => ['email' => \App\Recovery\MailChannel::class],
    'link' => \App\Recovery\RecoveryLink::class,
];
```

- `channels` is a keyed map: a campaign names a channel key, and email and SMS are different
  transports. A key with nothing against it makes every attempt on it a refusal with
  `NoChannelBound`.
- `link` turns an abandonment's token into a URL the host serves. A package cannot know a host's
  routes. Unbound, every attempt refuses with `NoLinkerBound` before a channel is asked.

A class name the container cannot build is a third state, and the Outreach screen names it as a
misconfiguration rather than as an unbound seam.

## Wire the record

The module owns no sweeper. Whoever owns the checkout decides one has been abandoned and calls
`Actions\RecordAbandonment`. Until something does, there is nothing here to reach out about — which
the Outreach screen says in as many words, because it is a different state from having nothing to
say.

Attempting recovery is likewise a host decision. This panel offers one attempt against one
abandonment under one campaign; a host that wants a sweep schedules `Queries\ListDueAbandonments`
against `Actions\AttemptRecovery` itself.

## What the host should delete

Nothing, yet. The host's `abandoned_carts`, `cart_recovery_attempts` and `cart_recovery_campaigns`
tables and their three models are unreachable from any job, command, service, controller, listener or
scheduler entry, and no Filament resource in the host presents them. There is nothing on screen to
replace, so this package adds screens rather than removing any. The host's tables and models are the
domain package's business to retire, and `docs/adoption.md` there records it.
