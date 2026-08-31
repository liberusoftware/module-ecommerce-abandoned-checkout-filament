<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;

/**
 * One campaign. There is nothing to do to it here: redefining is done by name
 * from the listing, and attempting is done against an abandonment, because an
 * attempt is one send of one campaign against one abandonment and the panel
 * offers no sweep of its own.
 */
final class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
