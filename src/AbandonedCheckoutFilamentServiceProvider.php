<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers nothing. Every screen and both widgets arrive through
 * {@see AbandonedCheckoutPlugin}, so the host decides which panel gets them — a
 * provider that registered widgets would put one merchant's baskets on whatever
 * panel happened to boot.
 */
class AbandonedCheckoutFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
