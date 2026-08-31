<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes;

use Liberu\Ecommerce\AbandonedCheckout\Contracts\RecoveryChannel;
use Liberu\Ecommerce\AbandonedCheckout\Data\Dispatch;
use Throwable;

final class FakeChannel implements RecoveryChannel
{
    /** @var list<Dispatch> */
    public array $sent = [];

    public function __construct(
        private readonly bool $reports = true,
        private readonly ?Throwable $failure = null,
        private readonly string $reference = 'provider-message-1',
    ) {}

    public function send(string $tenantId, Dispatch $dispatch): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sent[] = $dispatch;

        return $this->reference;
    }

    public function reportsOutcomes(): bool
    {
        return $this->reports;
    }
}
