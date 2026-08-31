<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;

/**
 * The abandonments the domain says are eligible right now, under any of this
 * merchant's campaigns.
 *
 * Eligible is not the same as "will go": the domain's rule is about the cap, the
 * quiet period, the delay, the expiry and the conditions, and none of those
 * knows whether a transport is bound. A row here on a fresh install is an
 * abandonment that would be attempted and refused, which is why the widget above
 * says which seams are unbound rather than leaving this table to imply it.
 */
class DueNow extends TableWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * The abandonments the domain counts as due, by key.
     *
     * @return list<int>
     */
    public function dueIds(): array
    {
        return Snapshot::dueIds(PanelTenant::current());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Due under a campaign right now')
            ->description('Measured against one clock this page read once, so two widgets cannot disagree about whether a delay has elapsed. Eligible under the campaign\'s rule — not a promise that anything will go.')
            ->query(fn (): Builder => Abandonment::query()
                ->where('tenant_id', PanelTenant::current())
                ->whereKey($this->dueIds()))
            ->columns([
                TextColumn::make('checkout_ref')->label('Checkout'),
                TextColumn::make('amount_minor')
                    ->label('Basket')
                    ->state(fn (Abandonment $record): string => AbandonmentResource::amount($record)),
                TextColumn::make('items')
                    ->label('Items')
                    ->state(fn (Abandonment $record): string => AbandonmentResource::items($record)),
                TextColumn::make('abandoned_at')->label('Abandoned')->dateTime(),
                TextColumn::make('expires_at')->label('Expires')->dateTime(),
            ])
            ->defaultSort('abandoned_at')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing is due under a campaign at this instant')
            ->emptyStateDescription('Which is one of several different facts: no campaign defined, nothing recorded as abandoned, every delay still running, every cap already spent, or every condition unmet. The refusals beside each attempt say which.');
    }
}
