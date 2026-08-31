<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Tests;

/**
 * The merchant the test panel is showing. Mutable, because every custody proof
 * here gives the second merchant deliberately identical values — two merchants
 * both calling a campaign `first-nudge` and both using the checkout reference
 * their own storefront minted is the ordinary case, and a proof that creates one
 * merchant's rows proves nothing about a `where` nobody wrote.
 */
final class TestTenant
{
    public const PRIMARY = 'tenant-a';

    public const OTHER = 'tenant-b';

    private static string $current = self::PRIMARY;

    public static function current(): string
    {
        return self::$current;
    }

    public static function use(string $tenantId): void
    {
        self::$current = $tenantId;
    }

    public static function reset(): void
    {
        self::$current = self::PRIMARY;
    }
}
