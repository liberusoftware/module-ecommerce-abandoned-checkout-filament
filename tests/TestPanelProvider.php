<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Tests;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\AbandonedCheckout\Filament\AbandonedCheckoutPlugin;

/** A merchant panel with this module's plugin attached and nothing else — the whole of what a host writes. */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->plugin(
                AbandonedCheckoutPlugin::make()
                    ->tenantUsing(fn (): string => TestTenant::current()),
            );
    }
}
