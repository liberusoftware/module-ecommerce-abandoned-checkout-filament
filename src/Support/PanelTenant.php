<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Support;

use Closure;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * Where the panel gets its merchant id from. Every domain query and action takes
 * the tenant as its first argument, so the argument comes from one place rather
 * than from `Filament::getTenant()` repeated across two resources, a relation
 * manager, two widgets and a page.
 *
 * There is no fallback to "no tenant": `where('tenant_id', null)` compiles to
 * `is null` and lists exactly the orphan rows a scope exists to hide.
 */
final class PanelTenant
{
    /** ponytail: one process-global resolver; move it onto the plugin if a host ever needs one rule per panel. */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function current(): string
    {
        $tenant = self::$resolver !== null
            ? (self::$resolver)()
            : Filament::getTenant()?->getKey();

        if (! is_string($tenant) && ! is_int($tenant)) {
            throw new RuntimeException(
                'Abandoned checkout could not resolve a tenant for this panel. '
                .'Set one with AbandonedCheckoutPlugin::make()->tenantUsing(...) in the panel provider.'
            );
        }

        $tenant = (string) $tenant;

        if ($tenant === '') {
            throw new RuntimeException('Abandoned checkout resolved an empty tenant id, which would match orphan rows.');
        }

        return $tenant;
    }

    /**
     * Whether a merchant can be named at all.
     *
     * A widget is the surface Filament's own tenancy does not reach — `canView()`
     * defaults to true and no panel scope applies — so every widget here answers
     * this before it reads anything.
     */
    public static function resolvable(): bool
    {
        try {
            self::current();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
