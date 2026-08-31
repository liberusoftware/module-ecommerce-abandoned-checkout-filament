<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes;

use Liberu\Ecommerce\AbandonedCheckout\Contracts\RecoveryLink;
use Throwable;

final class FakeLinker implements RecoveryLink
{
    public function __construct(private readonly ?Throwable $failure = null) {}

    public function urlFor(string $tenantId, string $token): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return 'https://shop.example/recover/'.$token;
    }
}
