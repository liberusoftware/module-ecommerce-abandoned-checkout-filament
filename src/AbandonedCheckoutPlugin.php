<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Pages\Outreach;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\DueNow;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\OutreachStanding;

/**
 * The operator's view of what this merchant has not managed to sell, as the host
 * attaches it.
 *
 *     $panel->plugin(
 *         AbandonedCheckoutPlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
 *     );
 *
 * Both widgets are registered here rather than discovered, so they arrive on the
 * panel the host chose rather than on whichever one happened to boot.
 */
final class AbandonedCheckoutPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-abandoned-checkout';
    }

    /** How this panel names the merchant. Without it, the panel's own Filament tenant is used. */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                AbandonmentResource::class,
                CampaignResource::class,
            ])
            ->pages([
                Outreach::class,
            ])
            ->widgets([
                OutreachStanding::class,
                DueNow::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
