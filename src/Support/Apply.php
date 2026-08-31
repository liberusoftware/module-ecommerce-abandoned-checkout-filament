<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Support;

use Filament\Notifications\Notification;

/**
 * What the panel says after a domain action answered. Every action this panel
 * calls takes the tenant as its first argument and re-reads its own row, so
 * there is nothing to re-read here and nothing to guard: the sentence is the
 * whole job.
 */
final class Apply
{
    public static function say(string $title, string $body, string $colour): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->color($colour)
            ->persistent()
            ->send();
    }
}
