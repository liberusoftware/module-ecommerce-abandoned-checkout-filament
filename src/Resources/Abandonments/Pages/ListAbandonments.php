<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;

/**
 * There is no control here that makes an abandonment. The module owns no sweeper
 * and does not decide what abandoned means: whoever owns the checkout notices
 * and calls `RecordAbandonment`. A button that minted one from a panel would be
 * this package deciding, which is the boundary it exists to keep.
 */
final class ListAbandonments extends ListRecords
{
    protected static string $resource = AbandonmentResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
